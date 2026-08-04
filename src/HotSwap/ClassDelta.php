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

namespace ZEngine\HotSwap;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\FunctionBodySwap;
use ZEngine\Reflection\PendingBodySwap;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionClassConstant;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Reflection\ReflectionProperty;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * A staged, atomically applicable difference between a live class and its new source
 *
 * Computed by HotSwap::prepare() against a hidden donor class (compiled from the new
 * source) and consumed entirely through the high-level reflection API: the delta
 * operates on ReflectionMethod / ReflectionClassConstant / ReflectionProperty /
 * ReflectionValue objects, never on raw engine structures. apply() executes the
 * operations in a stage-then-commit protocol: every step records an undo action,
 * nothing from the previous class state is destroyed before all steps succeeded, and
 * any failure rolls the class back to its exact previous state - no half-swapped class
 * is ever observable. Installed z-engine object handlers and hooks are never touched:
 * the class entry pointer, its handler fields and the handler block cache all stay.
 *
 * Operation semantics (support matrix and memory rules in docs/hot-swap.md):
 *
 *  - changed method: in-place body swap of the published zend_function (pointer
 *    identity preserved, so warmed-up inline caches, subclass method buckets and
 *    prototype links stay valid); the new source's declaration is authoritative.
 *  - added method: a writable immortal container adopting the donor body is published
 *    in the method table. Subclasses linked before the swap do not inherit it.
 *  - removed method: the bucket is unpublished but the zend_function structure and its
 *    body stay allocated for the rest of the request; if the class inherited a method
 *    of the same name from an ancestor, that ancestor entry becomes visible again.
 *  - changed/added constant: the constant value is replaced (or a new immortal
 *    container is published); constant ASTs are re-evaluated lazily by the engine.
 *  - changed default property/static value: the default slot is replaced; live objects
 *    and already-materialized static values keep their current state by design.
 */
final class ClassDelta
{
    /**
     * Method names the class entry may keep a direct magic-shortcut pointer for
     */
    private const MAGIC_METHOD_NAMES = [
        '__construct',
        '__destruct',
        '__clone',
        '__get',
        '__set',
        '__unset',
        '__isset',
        '__call',
        '__callstatic',
        '__tostring',
        '__debuginfo',
        '__serialize',
        '__unserialize',
    ];

    private bool $isApplied     = false;
    private bool $isDiscarded   = false;
    private bool $donorReleased = false;

    /**
     * @param array<string, ReflectionMethod>         $changedMethods      Lc name => donor method to swap in
     * @param array<string, ReflectionMethod>         $addedMethods        Lc name => donor method to publish
     * @param array<string, ?ReflectionMethod>        $removedMethods      Lc name => ancestor fallback or null
     * @param array<string, ReflectionClassConstant>  $changedConstants    Name => donor constant
     * @param array<string, ReflectionClassConstant>  $addedConstants      Name => donor constant
     * @param array<string, ReflectionProperty> $changedProperties  Name => live instance property
     * @param array<string, ReflectionProperty> $changedStatics     Name => live static property
     */
    private function __construct(
        private string $className,
        private ReflectionClass $liveClass,
        private ReflectionClass $donorClass,
        private array $changedMethods,
        private array $addedMethods,
        private array $removedMethods,
        private array $changedConstants,
        private array $addedConstants,
        private array $changedProperties,
        private array $changedStatics,
    ) {}

    public function __destruct()
    {
        if (!$this->isApplied && !$this->isDiscarded) {
            $this->discard();
        }
    }

    /**
     * Computes the delta between the live class and the donor class
     *
     * @internal called by HotSwap::prepare(); the donor ownership moves to the delta
     */
    public static function fromDiff(string $className, ReflectionClass $liveClass, ReflectionClass $donorClass): self
    {
        $originalMethods = $liveClass->getDeclaredMethods();
        $donorMethods    = $donorClass->getDeclaredMethods();

        $changedMethods = [];
        $addedMethods   = [];
        $removedMethods = [];
        foreach ($donorMethods as $lowerName => $donorMethod) {
            if (isset($originalMethods[$lowerName])) {
                if (!$originalMethods[$lowerName]->equals($donorMethod)) {
                    $changedMethods[$lowerName] = $donorMethod;
                }
                continue;
            }
            if ($liveClass->findMethod($lowerName) !== null) {
                throw HotSwapException::inheritedMethodOverride($className, $lowerName);
            }
            if (in_array($lowerName, self::MAGIC_METHOD_NAMES, true) || str_starts_with($lowerName, '__')) {
                throw HotSwapException::magicMethodAdded($className, $lowerName);
            }
            $addedMethods[$lowerName] = $donorMethod;
        }
        foreach ($originalMethods as $lowerName => $originalMethod) {
            if (isset($donorMethods[$lowerName])) {
                continue;
            }
            if (!$originalMethod->isRemovable()) {
                throw HotSwapException::magicMethodRemoved($className, $lowerName);
            }
            // If an ancestor declares the same method, the donor inherited it during
            // linking: republishing that ancestor entry restores plain inheritance
            $removedMethods[$lowerName] = $donorClass->findMethod($lowerName);
        }

        // Constants: only values (and their access flags) may change; the constant set
        // may grow, removal is not supported (see docs/hot-swap.md)
        $originalConstants = $liveClass->getDeclaredConstants();
        $donorConstants    = $donorClass->getDeclaredConstants();
        $changedConstants  = [];
        $addedConstants    = [];
        foreach ($donorConstants as $constantName => $donorConstant) {
            if (!isset($originalConstants[$constantName])) {
                $addedConstants[$constantName] = $donorConstant;
                continue;
            }
            if (!$originalConstants[$constantName]->equals($donorConstant)) {
                $changedConstants[$constantName] = $donorConstant;
            }
        }
        foreach ($originalConstants as $constantName => $originalConstant) {
            if (!isset($donorConstants[$constantName])) {
                throw HotSwapException::constantRemoved($className, $constantName);
            }
        }

        // Default property/static values (the surfaces are proven identical by
        // HotSwap::prepare(), so the donor slot of the same property lines up)
        $changedProperties = [];
        $changedStatics    = [];
        foreach ($liveClass->getDeclaredProperties() as $propertyName => $property) {
            if ($property->isVirtual()) {
                continue;
            }
            if ($property->isStatic()) {
                $liveValue  = $liveClass->getDefaultStaticValueOf($property);
                $donorValue = $donorClass->getDefaultStaticValueOf($property);
                if (!$liveValue->equals($donorValue)) {
                    $changedStatics[$propertyName] = $property;
                }
                continue;
            }
            $liveValue  = $liveClass->getDefaultPropertyValueOf($property);
            $donorValue = $donorClass->getDefaultPropertyValueOf($property);
            if (!$liveValue->equals($donorValue)) {
                $changedProperties[$propertyName] = $property;
            }
        }

        return new self(
            $className,
            $liveClass,
            $donorClass,
            $changedMethods,
            $addedMethods,
            $removedMethods,
            $changedConstants,
            $addedConstants,
            $changedProperties,
            $changedStatics,
        );
    }

    /**
     * @return list<string> Lowercased names of methods whose bodies will be swapped
     */
    public function getChangedMethods(): array
    {
        return array_keys($this->changedMethods);
    }

    /**
     * @return list<string> Lowercased names of methods that will be added
     */
    public function getAddedMethods(): array
    {
        return array_keys($this->addedMethods);
    }

    /**
     * @return list<string> Lowercased names of methods that will be removed
     */
    public function getRemovedMethods(): array
    {
        return array_keys($this->removedMethods);
    }

    /**
     * @return list<string> Names of constants whose values will be replaced
     */
    public function getChangedConstants(): array
    {
        return array_keys($this->changedConstants);
    }

    /**
     * @return list<string> Names of constants that will be added
     */
    public function getAddedConstants(): array
    {
        return array_keys($this->addedConstants);
    }

    /**
     * @return list<string> Names of instance properties whose default value will change
     */
    public function getChangedProperties(): array
    {
        return array_keys($this->changedProperties);
    }

    /**
     * @return list<string> Names of static properties whose default value will change
     */
    public function getStaticChangedProperties(): array
    {
        return array_keys($this->changedStatics);
    }

    /**
     * Checks if the delta contains no operations at all
     */
    public function isEmpty(): bool
    {
        return $this->changedMethods    === [] && $this->addedMethods === [] && $this->removedMethods === []
                                               && $this->changedConstants  === [] && $this->addedConstants === []
                                               && $this->changedProperties === [] && $this->changedStatics === [];
    }

    /**
     * Applies the delta atomically: stage every operation, commit only when all succeeded
     *
     * On failure every already-executed operation is undone in reverse order and the
     * previous class state is fully restored before the exception propagates.
     *
     * @throws HotSwapException When any operation fails (the class was rolled back)
     */
    public function apply(): void
    {
        $this->assertUsable();
        if (Core::isShutdown()) {
            throw HotSwapException::shutdown();
        }

        $methodTable    = $this->liveClass->getMethodTable();
        $constantsTable = $this->liveClass->getConstantsTable();
        /** @var list<PendingBodySwap> $pendingSwaps */
        $pendingSwaps = [];
        /** @var list<ReflectionValue> $replacedSnapshots values replaced in place, released on commit */
        $replacedSnapshots = [];
        /** @var list<\Closure> $undoStack */
        $undoStack = [];

        try {
            foreach ($this->changedMethods as $lowerName => $donorMethod) {
                $entry = $this->liveClass->findMethod($lowerName);
                assert($entry !== null);
                $pending = FunctionBodySwap::swapUserFunctionBody(
                    $entry,
                    $donorMethod,
                    // The new source is authoritative for the declaration as well
                    preserveDeclaration: false,
                    // The donor body refcount guards the statics defaults table
                    duplicateStatics: false,
                    destroyPrevious: true,
                    publishedShares: FunctionBodySwap::countPublishedShares($entry),
                );
                $pendingSwaps[] = $pending;
                $undoStack[]    = static function () use ($pending): void {
                    $pending->rollback();
                };
            }

            foreach ($this->addedMethods as $lowerName => $donorMethod) {
                // Immortal-by-design container: the engine destroys the body it carries
                // but never frees user zend_function containers (see docs/hot-swap.md)
                $container = Core::trackedNew('zend_function', true);
                FunctionBodySwap::adoptFunctionForPublishing($container, $donorMethod, $this->liveClass);
                $methodTable->addFunctionEntry($lowerName, $container);
                $undoStack[] = function () use ($methodTable, $lowerName, $container): void {
                    $methodTable->deleteWithoutDestructor($lowerName);
                    self::releaseAdoptedContainer($container);
                };
            }

            foreach ($this->removedMethods as $lowerName => $fallbackMethod) {
                $previousMethod = $this->liveClass->findMethod($lowerName);
                assert($previousMethod !== null);
                $methodTable->deleteWithoutDestructor($lowerName);
                if ($fallbackMethod !== null) {
                    // Restore plain inheritance: the bucket owns one name reference and
                    // one body share, exactly like zend_duplicate_function takes
                    FunctionBodySwap::acquireBucketOwnership($fallbackMethod);
                    self::publishMethodPointer($methodTable, $lowerName, $fallbackMethod);
                }
                $undoStack[] = function () use ($methodTable, $lowerName, $previousMethod, $fallbackMethod): void {
                    if ($fallbackMethod !== null) {
                        $methodTable->deleteWithoutDestructor($lowerName);
                        FunctionBodySwap::releaseBucketOwnership($fallbackMethod);
                    }
                    self::publishMethodPointer($methodTable, $lowerName, $previousMethod);
                };
            }

            foreach ($this->changedConstants as $constantName => $donorConstant) {
                $liveConstant = $this->liveClass->findConstant($constantName);
                assert($liveConstant !== null);
                $liveValue           = $liveConstant->getReflectionValue();
                $snapshot            = $liveValue->replaceWith($donorConstant->getReflectionValue());
                $replacedSnapshots[] = $snapshot;
                $undoStack[]         = static function () use ($liveValue, $snapshot): void {
                    $liveValue->restoreFrom($snapshot);
                };
            }

            foreach ($this->addedConstants as $constantName => $donorConstant) {
                $adopted = $donorConstant->adoptForClass($this->liveClass);
                self::publishConstantPointer($constantsTable, $constantName, $adopted);
                $undoStack[] = function () use ($constantsTable, $constantName, $adopted): void {
                    $constantsTable->deleteWithoutDestructor($constantName);
                    $adopted->releaseContainer();
                };
            }

            foreach ($this->changedProperties as $property) {
                $liveValue           = $this->liveClass->getDefaultPropertyValueOf($property);
                $snapshot            = $liveValue->replaceWith($this->donorClass->getDefaultPropertyValueOf($property));
                $replacedSnapshots[] = $snapshot;
                $undoStack[]         = static function () use ($liveValue, $snapshot): void {
                    $liveValue->restoreFrom($snapshot);
                };
            }

            foreach ($this->changedStatics as $property) {
                $liveValue           = $this->liveClass->getDefaultStaticValueOf($property);
                $snapshot            = $liveValue->replaceWith($this->donorClass->getDefaultStaticValueOf($property));
                $replacedSnapshots[] = $snapshot;
                $undoStack[]         = static function () use ($liveValue, $snapshot): void {
                    $liveValue->restoreFrom($snapshot);
                };
            }

            if ($this->touchesClassConstants()) {
                // New values may be unevaluated constant expressions: drop the
                // "all constants updated" shortcut so the engine re-evaluates lazily
                $liveClass     = $this->liveClass;
                $previousFlags = $liveClass->invalidateConstants();
                $undoStack[]   = static function () use ($liveClass, $previousFlags): void {
                    $liveClass->restoreFlags($previousFlags);
                };
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($undoStack) as $undoAction) {
                $undoAction();
            }
            $this->isDiscarded = true;
            $this->releaseDonor();
            if ($error instanceof HotSwapException) {
                throw $error;
            }
            throw HotSwapException::applyFailedAndRolledBack($this->className, $error);
        }

        // Commit: from here on nothing can fail - release everything the class
        // previously owned that the delta replaced
        foreach ($pendingSwaps as $pending) {
            $pending->commit();
        }
        foreach ($replacedSnapshots as $snapshot) {
            $snapshot->destroy();
        }
        $this->isApplied = true;
        $this->releaseDonor();
    }

    /**
     * Drops a prepared delta without applying it (donor entry is destroyed)
     */
    public function discard(): void
    {
        if ($this->isApplied || $this->isDiscarded) {
            return;
        }
        $this->isDiscarded = true;
        $this->releaseDonor();
    }

    /**
     * Destroys an unpublished class entry with engine semantics
     *
     * @internal shared with HotSwap::prepare() error paths
     */
    public static function destroyClassEntry(ReflectionClass $reflectionClass): void
    {
        if (Core::isShutdown()) {
            // Engine writes are forbidden after shutdown: the unpublished entry leaks
            // (bounded - it is unreachable and carries no trampolines)
            return;
        }
        $rawClass   = (new StructArray($reflectionClass->getRawValue(), 1))->rawAt(0);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawClass);
        Core::call('destroy_zend_class', $valueEntry->getRawValue());
        $valueEntry->release();
    }

    private function touchesClassConstants(): bool
    {
        return $this->changedConstants  !== [] || $this->addedConstants !== []
                                               || $this->changedProperties !== [] || $this->changedStatics !== [];
    }

    private function releaseDonor(): void
    {
        if ($this->donorReleased) {
            return;
        }
        $this->donorReleased = true;
        self::destroyClassEntry($this->donorClass);
    }

    private function assertUsable(): void
    {
        if ($this->isApplied) {
            throw HotSwapException::alreadyApplied();
        }
        if ($this->isDiscarded) {
            throw HotSwapException::discarded();
        }
    }

    /**
     * Publishes a method entry pointer into a method table bucket (shared structure)
     *
     * @param HashTable|ReflectionValue[] $table
     */
    private static function publishMethodPointer(HashTable $table, string $lowerName, ReflectionMethod $method): void
    {
        // newEntry(IS_PTR) stores the ADDRESS of the CData it receives, so it needs the
        // dereferenced zend_function struct, not the 8-byte pointer variable (which FFI
        // refuses to reinterpret as a larger zval - "attempt to cast to larger type")
        $rawFunction = (new StructArray($method->getEntryPointer(), 1))->rawAt(0);
        $valueEntry  = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $table->add($lowerName, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Publishes an adopted class-constant container into the constants table
     *
     * @param HashTable|ReflectionValue[] $table
     */
    private static function publishConstantPointer(HashTable $table, string $constantName, ReflectionClassConstant $constant): void
    {
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $constant->getRawValue());
        $table->add($constantName, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Releases everything an added-method container took (rollback path only)
     */
    private static function releaseAdoptedContainer(CData $container): void
    {
        $containerFunction = ReflectionFunction::fromCData(Core::cast('zend_function *', Core::addr($container)));
        $namePointer       = $containerFunction->getCommonPointer()->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->releaseReference();

        FunctionBodySwap::releaseSwappedInBody(
            $containerFunction,
            $containerFunction->getAddress(),
            1,
            false,
            null,
        );
        Core::untrackAndFree(Core::addr($container));
    }
}
