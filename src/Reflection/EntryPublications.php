<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_function;
use ZEngine\Type\StringEntry;

/**
 * The method-table buckets of every linked user class that carry the name of one entry,
 * surveyed in a single class-table walk
 *
 * A body swap asks the same questions about its entry several times over: how many table
 * buckets publish the very structure (the body shares its accounting must mirror), which
 * subclasses hold an engine clone of it instead (clones follow the swap explicitly), and
 * the same again for every clone and once more when the swap is committed or rolled back.
 * Each answer used to cost a walk over the class table; one survey answers all of them for
 * the whole swap.
 *
 * A survey is a snapshot of the tables as they were when it was taken, which is exactly
 * what the accounting of a swap needs: the shares and clones of the PREVIOUS body are
 * frozen the moment the entry switches to the new one (a class linked afterwards inherits
 * or clones the new body), and nothing links a class between the survey and the swaps it
 * serves - the destructors a commit may run come after every decision that reads it.
 */
final class EntryPublications
{
    /**
     * The survey keeps addresses only and builds the reflection views on demand: a wrapper
     * per published bucket would stay alive for the whole swap, and the object-store walk a
     * commit makes to find the live closures pays for every live object.
     *
     * @param list<array{int, int, int|null}> $buckets Address of the holder class entry, of the
     *        bucket's function struct, and of that function's scope class entry
     */
    private function __construct(private readonly array $buckets) {}

    /**
     * Surveys the class table for the buckets named like $entryFunction (a global function
     * entry is published in no class table: its survey is empty)
     */
    public static function survey(FunctionLikeInterface $entryFunction): self
    {
        $entryCommon = $entryFunction->getCommonPointer();
        if ($entryCommon->scope === null) {
            return new self([]);
        }
        $namePointer = $entryCommon->function_name;
        assert($namePointer instanceof CData);
        // One engine string keys every lookup, and the walk - which visits every linked
        // class - builds a method view for the classes publishing the name only
        $lowerName = new StringEntry(strtolower(StringEntry::fromCData($namePointer)->getStringValue()));

        $buckets = [];
        foreach (Core::$executor->classTable->pointerValueAddresses() as $classAddress) {
            $bucketFunction = ReflectionClass::findUserMethodAt($classAddress, $lowerName);
            if ($bucketFunction === null) {
                continue;
            }
            $bucketScope = $bucketFunction->getCommonPointer()->scope;
            $buckets[]   = [
                $classAddress,
                $bucketFunction->getAddress(),
                $bucketScope === null ? null : Core::addressOf($bucketScope),
            ];
        }

        return new self($buckets);
    }

    /**
     * The classes whose method table publishes the very structure of $function: its declaring
     * class and every subclass that inherited the pointer at link time (zend_duplicate_function
     * shares the structure and takes one body reference per bucket). A structure published in
     * no class table has no holders; a subclass holding an engine clone instead of the pointer
     * is not a holder, see clonesOf().
     *
     * @return list<ReflectionClass>
     */
    public function holdersOf(FunctionLikeInterface $function): array
    {
        $address = $function->getAddress();
        $holders = [];
        foreach ($this->buckets as [$classAddress, $bucketAddress]) {
            if ($bucketAddress === $address) {
                $holders[] = self::classAt($classAddress);
            }
        }

        return $holders;
    }

    /**
     * The body shares the published buckets of $function account for: one per holder, and one
     * for a structure published in no class table (a plain function entry)
     */
    public function sharesOf(FunctionLikeInterface $function): int
    {
        $address = $function->getAddress();
        $shares  = 0;
        foreach ($this->buckets as [, $bucketAddress]) {
            if ($bucketAddress === $address) {
                $shares++;
            }
        }

        return max($shares, 1);
    }

    /**
     * The engine clones of $function, each with the class holding it
     *
     * Inheritance shares the parent's zend_function POINTER into a subclass table (see
     * holdersOf()) - except when the subclass implements an interface that declares the
     * method: the engine then separates the inherited entry into a struct copy of its own in
     * the subclass table (do_inheritance_check_on_method), carrying the entry's scope and name,
     * a prototype of its own and a share on the body the entry had at link time. Such a clone
     * follows neither an in-place swap of the entry nor its publications unless it is handled
     * explicitly, which is what the swap machinery does with this list. Each clone is listed
     * once, with the class the engine separated it for (its subclasses, linked after it,
     * share the clone's pointer and are its holders). Surveyed for a clone instead of the
     * entry, the list holds the clone's siblings: the other clones of the same entry.
     *
     * @return list<array{ReflectionClass, ReflectionMethod}>
     */
    public function clonesOf(FunctionLikeInterface $function): array
    {
        $scope = $function->getCommonPointer()->scope;
        if ($scope === null) {
            return [];
        }
        $scopeAddress = Core::addressOf($scope);
        $address      = $function->getAddress();

        // The structure the declaring class publishes is the entry itself, wherever a subclass
        // shares its pointer: never a clone - of the entry, or of a clone surveyed in its place
        $declaredAddress = null;
        foreach ($this->buckets as [$classAddress, $bucketAddress]) {
            if ($classAddress === $scopeAddress) {
                $declaredAddress = $bucketAddress;
                break;
            }
        }
        /** @var array<int, list<int>> $publishers Clone struct address => addresses of the classes publishing it */
        $publishers = [];
        foreach ($this->buckets as [$classAddress, $bucketAddress, $bucketScopeAddress]) {
            if ($bucketAddress === $address || $bucketAddress === $declaredAddress || $bucketScopeAddress !== $scopeAddress) {
                continue;
            }
            $publishers[$bucketAddress][] = $classAddress;
        }

        $clones = [];
        foreach ($publishers as $bucketAddress => $classAddresses) {
            $clones[] = [
                self::separatingClass($classAddresses),
                ReflectionMethod::fromRawEntry(Core::pointerAtAddress(zend_function::class, $bucketAddress)),
            ];
        }

        return $clones;
    }

    /**
     * The class the engine separated a clone for, among the classes publishing it: the one no
     * other publisher is an ancestor of (its subclasses inherit the clone's POINTER at their
     * own link time, the ordinary way - one share of the clone each, see sharesOf())
     *
     * Table order cannot tell: a class compiled before its parent was autoloaded keeps the
     * bucket position of its compile-time (delayed early binding) key.
     *
     * @param list<int> $classAddresses
     */
    private static function separatingClass(array $classAddresses): ReflectionClass
    {
        $separating = null;
        foreach ($classAddresses as $classAddress) {
            $class                  = self::classAt($classAddress);
            $descendsFromAPublisher = false;
            for ($parent = $class->getParentClass(); $parent !== null; $parent = $parent->getParentClass()) {
                if (in_array($parent->getAddress(), $classAddresses, true)) {
                    $descendsFromAPublisher = true;
                    break;
                }
            }
            if (!$descendsFromAPublisher) {
                $separating = $class;
                break;
            }
        }
        // A publishing class without a publishing ancestor always exists (the chain is finite)
        assert($separating !== null);

        return $separating;
    }

    /**
     * The reflection view of the class entry at the given address
     */
    private static function classAt(int $classAddress): ReflectionClass
    {
        return ReflectionClass::fromCData(Core::pointerAtAddress(zend_class_entry::class, $classAddress));
    }
}
