<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_function;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * Publishes function bodies under additional names in method and function tables
 *
 * Two operations, both built from the FunctionBodySwap primitives:
 *
 *  - an ALIAS of an existing entry: a second entry executing the entry's current body,
 *    published in the declaring class as a private method (or as a second global function).
 *    This is the backup `redefine($newBody, preserveAs: ...)` publishes before it swaps the
 *    body, so the previous body stays callable and can be swapped back in later;
 *  - an OVERRIDE of an inherited method: an own entry of a class adopting a donor body under
 *    a name the class only inherited so far (`addMethod()` with a method donor).
 *
 * Containers are allocated from request memory (`Core::new(..., owned: false)`): the engine
 * never frees user zend_function containers itself (op_arrays are arena-allocated), so a
 * request-lifetime block matches its own arena semantics, and every container minted during
 * a request goes away with the request - which keeps per-request publication (an FPM worker
 * re-publishing on every request) memory-flat across requests. The body behind a container is
 * refcounted independently, exactly like for the methods ClassDelta adds.
 *
 * Both operations stage their table writes with undo actions: a failure in the middle unwinds
 * everything published so far before the exception propagates.
 *
 * @internal used by FunctionLikeTrait::redefine() and ReflectionClass::addMethod()
 */
final class MethodPublisher
{
    private function __construct() {}

    /**
     * Refuses an alias publication that a subclass linked against shared memory could never see
     *
     * Must run BEFORE the declaring class is copied out of shared memory: the copy-out leaves
     * every subclass linked earlier on the shared parent entry, so the alias published into
     * the copy would never reach them while the swapped body would still call it.
     *
     * @throws SharedMemoryException
     */
    public static function assertAliasPublishable(FunctionLikeInterface $entry): void
    {
        $scope = $entry->getCommonPointer()->scope;
        if ($scope === null) {
            return;
        }
        $declaringClass = ReflectionClass::fromCData($scope);
        if (!$declaringClass->isImmutable()) {
            return;
        }
        // Every subclass linked so far keeps dispatching the shared entry after the copy-out,
        // whether it inherited the pointer, holds an engine clone of it or overrides the
        // method and reaches the shared body through parent::
        foreach (self::subclassesOf($declaringClass) as $subclass) {
            throw SharedMemoryException::sharedEntryHasLinkedSubclasses(
                $declaringClass->getName(),
                self::entryName($entry),
                $subclass->getName(),
            );
        }
    }

    /**
     * Refuses an override publication that a subclass linked against shared memory could never see
     *
     * Same timing rule as assertAliasPublishable(): a subclass linked against the shared
     * entry of $class keeps inheriting the ancestor's method after the copy-out.
     *
     * @throws SharedMemoryException
     */
    public static function assertOverridePublishable(ReflectionClass $class, string $methodName): void
    {
        if (!$class->isImmutable()) {
            return;
        }
        $inherited = $class->getMethodTable()->find(strtolower($methodName));
        if ($inherited === null) {
            return;
        }
        $inheritedMethod = ReflectionMethod::fromRawEntry($inherited->getRawFunction());
        foreach (self::descendantsHolding($class, strtolower($methodName), $inheritedMethod) as $descendant) {
            throw SharedMemoryException::sharedEntryHasLinkedSubclasses(
                $class->getName(),
                $methodName,
                $descendant->getName(),
            );
        }
    }

    /**
     * Publishes the current body of a user method under $alias as a private method of its
     * declaring class
     *
     * The alias is what the engine would have produced for `use Trait { m as private alias; }`:
     * an own entry of the class with the same body, signature and static-ness, private, never
     * a constructor and never final. When the class inherits a private method of that name
     * from an ancestor the alias carries ZEND_ACC_CHANGED, exactly like a redeclared private
     * method, so the ancestor's own code still resolves its own alias on instances of this
     * class. Every subclass linked before receives the alias in its method table too, the way
     * the engine copies a parent's private methods into child tables at link time (the
     * preserved body runs on instances of overriding subclasses as well, through parent::),
     * with the ownership an inherited bucket holds; a subclass declaring the alias name itself
     * keeps its own method, which gets ZEND_ACC_CHANGED like any redeclared parent-private
     * method. The alias takes over the live static variables of the body it preserves.
     *
     * @return array{ReflectionMethod, \Closure(): void} The published alias and the action
     *                                                    unpublishing it again (rollback of a
     *                                                    failed swap)
     */
    public static function publishMethodAlias(FunctionLikeInterface $entry, string $alias): array
    {
        self::assertValidName($alias);
        $scope = $entry->getCommonPointer()->scope;
        assert($scope !== null);
        $class          = ReflectionClass::fromCData($scope);
        $className      = $class->getName();
        $table          = $class->getMethodTable();
        $lowerAlias     = strtolower($alias);
        $inheritedAlias = self::findInheritedEntry($class, $lowerAlias);
        if ($inheritedAlias === false) {
            throw RedefinitionException::aliasAlreadyDeclared($className, $alias);
        }
        if ($inheritedAlias !== null && !self::hasFlag($inheritedAlias, Core::ZEND_ACC_PRIVATE)) {
            throw RedefinitionException::aliasCollidesWithInheritedMethod(
                $className,
                $alias,
                self::declaringClassName($inheritedAlias),
            );
        }

        // Every subclass linked before inherits the alias as well, or redeclares it - collected
        // before anything is published, while the tables are untouched
        $inheritors  = [];
        $redeclarers = [];
        foreach (self::subclassesOf($class) as $subclass) {
            $subclassEntry = self::findInheritedEntry($subclass, $lowerAlias);
            if ($subclassEntry === false) {
                $redeclarers[] = $subclass;
            } elseif ($subclassEntry === null
                || ($inheritedAlias !== null && $subclassEntry->getAddress() === $inheritedAlias->getAddress())
            ) {
                // The name is free in the subclass, or it inherits the very ancestor-private
                // method the alias shadows: the nearer declaration wins, as at link time
                $inheritors[] = $subclass;
            }
        }

        $container   = self::newContainer();
        $aliasMethod = self::adopt($container, $entry, $class, $alias, inheritLiveStatics: true);
        // A preserved body is a private implementation detail of the class: not a
        // constructor, not final, and an override marker only where an ancestor declared a
        // private method of the same name
        $aliasMethod->setPrivate();
        $aliasMethod->setFinal(false);
        $aliasMethod->setConstructorFlag(false);
        $aliasMethod->setChangedFlag($inheritedAlias !== null);
        $aliasMethod->setPrototype(null);

        /** @var list<\Closure(): void> $undoStack */
        $undoStack = [];
        /** @var list<ReflectionMethod> $retired */
        $retired = [];
        try {
            $published = self::publishOwnEntry($table, $lowerAlias, $container, $inheritedAlias, $undoStack);
            foreach ($inheritors as $subclass) {
                self::shareIntoSubclass($subclass, $lowerAlias, $published, $undoStack, $retired);
            }
            foreach ($redeclarers as $subclass) {
                self::markRedeclaration($subclass, $lowerAlias, $entry, $undoStack);
            }
        } catch (\Throwable $error) {
            self::unwind($undoStack);
            self::releaseContainer($container);
            throw $error;
        }
        self::retire($retired);

        $undo = static function () use ($undoStack, $container): void {
            self::unwind($undoStack);
            self::releaseContainer($container);
        };

        return [ReflectionMethod::fromCData($published->getEntryPointer()), $undo];
    }

    /**
     * Publishes the current body of a user function under $alias as a second global function
     *
     * @return array{ReflectionFunction, \Closure(): void} The published alias and the action
     *                                                      unpublishing it again
     */
    public static function publishFunctionAlias(FunctionLikeInterface $entry, string $alias): array
    {
        self::assertValidName($alias);
        $table      = Core::$executor->functionTable;
        $lowerAlias = strtolower($alias);
        if ($table->find($lowerAlias) !== null) {
            throw RedefinitionException::functionAlreadyExists($alias);
        }

        $container = self::newContainer();
        self::adopt($container, $entry, null, $alias, inheritLiveStatics: true);
        try {
            $stored = $table->addFunctionEntry($lowerAlias, $container);
        } catch (\Throwable $error) {
            self::releaseContainer($container);
            throw $error;
        }

        $undo = static function () use ($table, $lowerAlias, $container): void {
            $table->deleteWithoutDestructor($lowerAlias);
            self::releaseContainer($container);
        };

        return [ReflectionFunction::fromCData($stored), $undo];
    }

    /**
     * Publishes a donor body as an own method of $class, overriding an inherited entry
     * of that name when there is one
     *
     * The override follows the engine's inheritance rules: it keeps the inherited method's
     * prototype (so reflection and `parent::` keep working), may not override a final
     * method, may not narrow visibility, must agree on static-ness, and redeclaring a
     * method that is private in the ancestor only marks the new entry ZEND_ACC_CHANGED - a
     * bit that also travels down from an inherited method carrying it, so an ancestor's
     * own code keeps resolving its own private method of that name on instances of this
     * class. The replaced inherited bucket returns the ownership it held; the class's magic
     * slots (constructor, __get, ...) that referenced the inherited entry follow the
     * override, and so do the method tables and magic slots of subclasses that were linked
     * before and still dispatch the ancestor's declaration (the inherited entry pointer, or
     * an engine clone of it, see FunctionBodySwap::cloneHolders()).
     */
    public static function publishOverride(ReflectionClass $class, string $methodName, FunctionLikeInterface $donor): ReflectionMethod
    {
        self::assertValidName($methodName);
        $className = $class->getName();
        $table     = $class->getMethodTable();
        $lowerName = strtolower($methodName);
        $inherited = self::findInheritedEntry($class, $lowerName);
        if ($inherited === false) {
            throw RedefinitionException::methodAlreadyDeclared($className, $methodName);
        }

        $descendants = [];
        if ($inherited !== null) {
            $declaringClass = self::declaringClassName($inherited);
            if (self::hasFlag($inherited, Core::ZEND_ACC_FINAL)) {
                throw RedefinitionException::finalMethodOverride($className, $methodName, $declaringClass);
            }
            if (self::hasFlag($inherited, Core::ZEND_ACC_STATIC) !== self::hasFlag($donor, Core::ZEND_ACC_STATIC)) {
                throw RedefinitionException::staticModifierMismatch("{$declaringClass}::{$methodName}", self::entryName($donor));
            }
            $isInheritedPrivate = self::hasFlag($inherited, Core::ZEND_ACC_PRIVATE);
            if (!$isInheritedPrivate && self::visibilityRank($donor) < self::visibilityRank($inherited)) {
                throw RedefinitionException::visibilityNarrowed($className, $methodName, $declaringClass);
            }
            $descendants = self::descendantsHolding($class, $lowerName, $inherited);
        }

        $container = self::newContainer();
        $method    = self::adopt($container, $donor, $class, $methodName);
        if ($inherited !== null) {
            // The engine marks a redeclared parent-private method CHANGED and links no
            // prototype; a CHANGED inherited method passes the bit on (do_inheritance_check_on_method)
            $isInheritedPrivate = self::hasFlag($inherited, Core::ZEND_ACC_PRIVATE);
            $method->setChangedFlag($isInheritedPrivate || self::hasFlag($inherited, Core::ZEND_ACC_CHANGED));
            $method->setPrototype($isInheritedPrivate ? null : self::prototypeOf($inherited));
        } else {
            // A method overriding nothing carries neither the donor's prototype nor its CHANGED bit
            $method->setChangedFlag(false);
            $method->setPrototype(null);
        }

        /** @var list<\Closure(): void> $undoStack */
        $undoStack = [];
        /** @var list<ReflectionMethod> $retired */
        $retired = [];
        try {
            $published = self::publishOwnEntry($table, $lowerName, $container, $inherited, $undoStack);
            if ($inherited !== null) {
                self::followMagicSlot($class, $inherited, $published, $undoStack);
                foreach ($descendants as $descendant) {
                    self::shareIntoSubclass($descendant, $lowerName, $published, $undoStack, $retired);
                    self::followMagicSlot($descendant, $inherited, $published, $undoStack);
                }
            }
        } catch (\Throwable $error) {
            self::unwind($undoStack);
            self::releaseContainer($container);
            throw $error;
        }
        self::retire($retired);

        return ReflectionMethod::fromCData($published->getEntryPointer());
    }

    /**
     * Allocates a zeroed zend_function container from request memory
     *
     * Not owned by FFI (never freed when the handle dies) and not persistent: the block is
     * reclaimed with the request, like the arena the engine allocates op_arrays from.
     * Handed around as the raw handle: the publication APIs take the container by handle
     * and the typed view is only ever taken through ReflectionMethod::fromRawEntry().
     */
    private static function newContainer(): CData
    {
        $container = Core::new(zend_function::class, false);
        assert($container instanceof CData);

        return $container;
    }

    /**
     * Fills a container with the donor body under the given name and scope
     */
    private static function adopt(
        CData $container,
        FunctionLikeInterface $donor,
        ?ReflectionClass $scope,
        string $name,
        bool $inheritLiveStatics = false,
    ): ReflectionMethod {
        FunctionBodySwap::adoptFunctionForPublishing($container, $donor, $scope, $inheritLiveStatics);
        $method = ReflectionMethod::fromRawEntry(Core::cast(zend_function::class, Core::addr($container)));
        $method->setFunctionName($name);

        return $method;
    }

    /**
     * Returns the resources an adopted container took (rollback only: the body stays alive
     * through its other holders)
     */
    private static function releaseContainer(CData $container): void
    {
        $containerFunction = ReflectionFunction::fromCData(Core::cast(zend_function::class, Core::addr($container)));
        $namePointer       = $containerFunction->getCommonPointer()->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->releaseReference();

        // The adoption duplicated the donor's statics defaults when the donor owned them; the
        // container never had a previous swap, so there is no earlier record to restore
        FunctionBodySwap::releaseSwappedInBody(
            $containerFunction,
            $containerFunction->getAddress(),
            1,
            mintedDefaults: FunctionBodySwap::hasMintedStaticDefaults($containerFunction->getAddress()),
            previousMintedRecord: null,
        );
        Core::free($container);
    }

    /**
     * Publishes a container under $lowerName in $table, replacing an inherited bucket
     *
     * The inherited bucket is unpublished without the destructor and returns the one name
     * reference and one body share it held (its ancestor still owns the entry).
     *
     * @param list<\Closure(): void> $undoStack
     */
    private static function publishOwnEntry(
        HashTable $table,
        string $lowerName,
        CData $container,
        ?ReflectionMethod $inherited,
        array &$undoStack,
    ): ReflectionMethod {
        if ($inherited !== null) {
            $table->deleteWithoutDestructor($lowerName);
            FunctionBodySwap::releaseBucketOwnership($inherited);
            $undoStack[] = static function () use ($table, $lowerName, $inherited): void {
                FunctionBodySwap::acquireBucketOwnership($inherited);
                $table->addFunctionEntry($lowerName, self::entryStruct($inherited));
            };
        }
        $stored      = $table->addFunctionEntry($lowerName, $container);
        $undoStack[] = static function () use ($table, $lowerName): void {
            $table->deleteWithoutDestructor($lowerName);
        };

        return ReflectionMethod::fromRawEntry($stored);
    }

    /**
     * Publishes an entry pointer into the method table of a subclass that inherited the
     * replaced entry, with the ownership an inherited bucket holds (zend_duplicate_function)
     *
     * A subclass declaring its own method of that name keeps it. A replaced bucket that
     * published nothing but an engine clone of the inherited entry leaves that clone
     * unpublished: it is listed in $retired for the release of its resources once the
     * publication stands (see retire()).
     *
     * @param list<\Closure(): void> $undoStack
     * @param list<ReflectionMethod>  $retired
     */
    private static function shareIntoSubclass(
        ReflectionClass $subclass,
        string $lowerName,
        ReflectionMethod $published,
        array &$undoStack,
        array &$retired,
    ): void {
        $subTable = $subclass->getMethodTable();
        $previous = self::findInheritedEntry($subclass, $lowerName);
        if ($previous === false) {
            return;
        }
        if ($previous !== null) {
            $subTable->deleteWithoutDestructor($lowerName);
            FunctionBodySwap::releaseBucketOwnership($previous);
            $undoStack[] = static function () use ($subTable, $lowerName, $previous): void {
                FunctionBodySwap::acquireBucketOwnership($previous);
                $subTable->addFunctionEntry($lowerName, self::entryStruct($previous));
            };
            $retired[] = $previous;
        }
        FunctionBodySwap::acquireBucketOwnership($published);
        $subTable->addFunctionEntry($lowerName, self::entryStruct($published));
        $undoStack[] = static function () use ($subTable, $lowerName, $published): void {
            $subTable->deleteWithoutDestructor($lowerName);
            FunctionBodySwap::releaseBucketOwnership($published);
        };
    }

    /**
     * The dereferenced zend_function struct of a published entry, which is what a table
     * publication needs: newEntry(IS_PTR) stores the ADDRESS of the CData it receives, and
     * FFI refuses to reinterpret the 8-byte pointer variable as a zval
     *
     * @return zend_function
     */
    private static function entryStruct(FunctionLikeInterface $entry): object
    {
        /** @var zend_function $struct The element view of a single-entry pointer */
        $struct = (new StructArray($entry->getEntryPointer(), 1))[0];

        return $struct;
    }

    /**
     * Repoints the magic slot of $class that references $previous (constructor, __get, ...)
     * at the published override
     *
     * @param list<\Closure(): void> $undoStack
     */
    private static function followMagicSlot(
        ReflectionClass $class,
        ReflectionMethod $previous,
        ReflectionMethod $published,
        array &$undoStack,
    ): void {
        $slot = $class->getMagicSlotFor($previous);
        if ($slot === null) {
            return;
        }
        $class->setMagicSlot($slot, $published);
        $undoStack[] = static function () use ($class, $slot, $previous): void {
            $class->setMagicSlot($slot, $previous);
        };
    }

    /**
     * Subclasses of $class (linked before) whose tables still dispatch the declaration of the
     * inherited entry under $lowerName: the entry pointer itself or an engine clone of it
     * (a subclass implementing an interface that declares the method holds its own struct
     * copy of the inherited entry, see FunctionBodySwap::cloneHolders())
     *
     * @return list<ReflectionClass>
     */
    private static function descendantsHolding(ReflectionClass $class, string $lowerName, ReflectionMethod $inherited): array
    {
        $declaringScope = $inherited->getCommonPointer()->scope;
        assert($declaringScope !== null);
        $scopeAddress = Core::addressOf($declaringScope);

        $descendants = [];
        foreach (self::subclassesOf($class) as $subclass) {
            $bucket = self::findInheritedEntry($subclass, $lowerName);
            if (!$bucket instanceof ReflectionMethod) {
                continue;
            }
            $bucketScope = $bucket->getCommonPointer()->scope;
            if ($bucketScope !== null && Core::addressOf($bucketScope) === $scopeAddress) {
                $descendants[] = $subclass;
            }
        }

        return $descendants;
    }

    /**
     * Marks the own method of a subclass that declares the alias name itself as redeclaring
     * a parent-private method (ZEND_ACC_CHANGED), so the preserved body - private in the
     * declaring class - resolves its alias on instances of that subclass as the engine would
     *
     * @param list<\Closure(): void> $undoStack
     */
    private static function markRedeclaration(
        ReflectionClass $subclass,
        string $lowerName,
        FunctionLikeInterface $entry,
        array &$undoStack,
    ): void {
        $bucket = $subclass->getMethodTable()->find($lowerName);
        assert($bucket !== null);
        $ownMethod = ReflectionMethod::fromRawEntry($bucket->getRawFunction());
        if (self::hasFlag($ownMethod, Core::ZEND_ACC_CHANGED)) {
            return;
        }
        if ($ownMethod->isImmutable()) {
            // The flag cannot be written into shared memory, and without it the preserved
            // body could not reach its alias on instances of that subclass
            throw SharedMemoryException::sharedEntryHasLinkedSubclasses(
                self::declaringClassName($entry),
                self::entryName($entry),
                $subclass->getName(),
            );
        }
        $ownMethod->setChangedFlag(true);
        $undoStack[] = static function () use ($ownMethod): void {
            $ownMethod->setChangedFlag(false);
        };
    }

    /**
     * Releases the resources of entries a publication left unpublished (replaced engine
     * clones): nothing in any table reaches them anymore, see FunctionBodySwap::retireUnpublishedEntry()
     *
     * @param list<ReflectionMethod> $retired
     */
    private static function retire(array $retired): void
    {
        foreach ($retired as $entry) {
            FunctionBodySwap::retireUnpublishedEntry($entry);
        }
    }

    /**
     * Every linked user class descending from $class, class aliases deduplicated
     *
     * @return list<ReflectionClass>
     */
    private static function subclassesOf(ReflectionClass $class): array
    {
        $classAddress = $class->getAddress();
        $subclasses   = [];
        $seenClasses  = [];
        foreach (Core::$executor->classTable as $classValue) {
            try {
                $rawClass = $classValue->getRawClass();
            } catch (\UnexpectedValueException $e) {
                // Class alias buckets (IS_ALIAS_PTR) resolve to an already-visited entry
                continue;
            }
            $candidateAddress = Core::addressOf($rawClass);
            if ($candidateAddress === $classAddress || isset($seenClasses[$candidateAddress])) {
                continue;
            }
            $seenClasses[$candidateAddress] = true;
            $candidate                      = ReflectionClass::fromCData($rawClass);
            if ($candidate->isUserDefined() && self::descendsFrom($candidate, $classAddress)) {
                $subclasses[] = $candidate;
            }
        }

        return $subclasses;
    }

    /**
     * Whether the class entry at $ancestorAddress is on the parent chain of $candidate
     */
    private static function descendsFrom(ReflectionClass $candidate, int $ancestorAddress): bool
    {
        for ($parent = $candidate->getParentClass(); $parent !== null; $parent = $parent->getParentClass()) {
            if ($parent->getAddress() === $ancestorAddress) {
                return true;
            }
        }

        return false;
    }

    /**
     * Looks a name up in the method table of a class
     *
     * @return ReflectionMethod|null|false The inherited entry, null when the name is free,
     *                                     false when the class declares the method itself
     */
    private static function findInheritedEntry(ReflectionClass $class, string $lowerName): ReflectionMethod|null|false
    {
        $bucket = $class->getMethodTable()->find($lowerName);
        if ($bucket === null) {
            return null;
        }
        $method = ReflectionMethod::fromRawEntry($bucket->getRawFunction());
        $scope  = $method->getCommonPointer()->scope;
        if ($scope === null || Core::addressOf($scope) === $class->getAddress()) {
            return false;
        }

        return $method;
    }

    /**
     * The prototype an override of the inherited entry must carry
     * (the engine rule: the parent's prototype, or the parent itself)
     */
    private static function prototypeOf(ReflectionMethod $inherited): FunctionLikeInterface
    {
        $prototype = $inherited->getCommonPointer()->prototype;
        if ($prototype === null) {
            return $inherited;
        }

        return ReflectionMethod::fromRawEntry($prototype);
    }

    /**
     * Visibility ordered for narrowing checks: private < protected < public
     */
    private static function visibilityRank(FunctionLikeInterface $function): int
    {
        if (self::hasFlag($function, Core::ZEND_ACC_PRIVATE)) {
            return 1;
        }
        if (self::hasFlag($function, Core::ZEND_ACC_PROTECTED)) {
            return 2;
        }

        return 3;
    }

    private static function hasFlag(FunctionLikeInterface $function, int $flag): bool
    {
        $flags = $function->getCommonPointer()->fn_flags;
        assert(is_int($flags));

        return ($flags & $flag) !== 0;
    }

    private static function declaringClassName(FunctionLikeInterface $function): string
    {
        $scope = $function->getCommonPointer()->scope;
        if ($scope === null) {
            return '';
        }

        return ReflectionClass::fromCData($scope)->getName();
    }

    /**
     * Display name of an entry (Class::method or function), safe for pointer-level wrappers
     */
    public static function entryName(FunctionLikeInterface $function): string
    {
        $name  = $function->getFunctionName() ?? '{closure}';
        $scope = self::declaringClassName($function);

        return $scope === '' ? $name : "{$scope}::{$name}";
    }

    private static function assertValidName(string $name): void
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) !== 1) {
            throw RedefinitionException::invalidName($name);
        }
    }

    /**
     * @param list<\Closure(): void> $undoStack
     */
    private static function unwind(array $undoStack): void
    {
        foreach (array_reverse($undoStack) as $undoAction) {
            $undoAction();
        }
    }
}
