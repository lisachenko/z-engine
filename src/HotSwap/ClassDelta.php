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
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

/**
 * A staged, atomically applicable difference between a live class and its new source
 *
 * Computed by HotSwap::prepare() against a hidden donor class entry compiled from
 * the new source. apply() executes the operations in a stage-then-commit protocol:
 * every step records an undo action, nothing from the previous class state is
 * destroyed before all steps succeeded, and any failure rolls the class back to its
 * exact previous state - no half-swapped class is ever observable. Installed
 * z-engine object handlers and hooks are never touched: the class entry pointer,
 * its handler fields and the handler block cache all stay as they are.
 *
 * Operation semantics (support matrix and memory rules in docs/hot-swap.md):
 *
 *  - changed method: in-place body swap of the published zend_function (pointer
 *    identity preserved, so warmed-up inline caches, subclass method buckets and
 *    prototype links stay valid); the new source's declaration is authoritative
 *    (signature, visibility and other declaration flags follow the donor).
 *  - added method: a writable immortal container adopting the donor body is
 *    published in the method table. Subclasses linked before the swap do not
 *    inherit it (inheritance is materialized at link time).
 *  - removed method: the bucket is unpublished but the zend_function structure and
 *    its body stay allocated for the rest of the request - warmed-up inline caches
 *    and subclass buckets may still reference them. New lookups fail with the
 *    ordinary "undefined method" error; if the class inherited a method of the same
 *    name from an ancestor, that ancestor entry becomes visible again.
 *  - changed constant / added constant: the constant value zval is replaced (or a
 *    new immortal zend_class_constant container is published); constant ASTs are
 *    re-evaluated lazily by the engine.
 *  - changed default property values (instance and static declarations): the
 *    default table slot is replaced; live objects and already-materialized static
 *    property values keep their current state by design.
 */
final class ClassDelta
{
    /**
     * Test-only failure injection point for the apply protocol
     *
     * When set, the closure is invoked with each operation label (eg
     * "method.change:greet") right before the operation executes; a throw from it
     * exercises the rollback path.
     *
     * @internal
     */
    public static ?\Closure $applyFailureInjector = null;

    /**
     * zend_class_entry function pointer fields that shortcut magic methods: removing
     * or adding those requires field surgery this delta engine does not perform
     */
    private const MAGIC_POINTER_FIELDS = [
        'constructor',
        'destructor',
        'clone',
        '__get',
        '__set',
        '__unset',
        '__isset',
        '__call',
        '__callstatic',
        '__tostring',
        '__debugInfo',
        '__serialize',
        '__unserialize',
    ];

    private bool $isApplied     = false;
    private bool $isDiscarded   = false;
    private bool $donorReleased = false;

    /**
     * @param array<string, CData> $changedMethods       Lowercased name => donor zend_function*
     * @param array<string, CData> $addedMethods         Lowercased name => donor zend_function*
     * @param array<string, ?CData> $removedMethods      Lowercased name => inherited ancestor
     *                                                   zend_function* that becomes visible again, or null
     * @param array<string, CData> $changedConstants     Constant name => donor zend_class_constant*
     * @param array<string, CData> $addedConstants       Constant name => donor zend_class_constant*
     * @param list<int>            $changedPropertySlots Instance default table slots to update
     * @param list<int>            $changedStaticSlots   Static default table slots to update
     */
    private function __construct(
        private string $className,
        private CData $classEntry,
        private CData $donorEntry,
        private array $changedMethods,
        private array $addedMethods,
        private array $removedMethods,
        private array $changedConstants,
        private array $addedConstants,
        private array $changedPropertySlots,
        private array $changedStaticSlots,
    ) {}

    public function __destruct()
    {
        if (!$this->isApplied && !$this->isDiscarded) {
            $this->discard();
        }
    }

    /**
     * Computes the delta between the live class entry and the donor entry
     *
     * @internal called by HotSwap::prepare(); the donor ownership moves to the delta
     */
    public static function fromDiff(string $className, CData $classEntry, CData $donorEntry): self
    {
        $originalMethods = self::declaredMethods($classEntry);
        $donorMethods    = self::declaredMethods($donorEntry);

        $changedMethods = [];
        $addedMethods   = [];
        $removedMethods = [];
        $methodTable    = self::embeddedTable($classEntry->function_table);
        foreach ($donorMethods as $lowerName => $donorFunction) {
            if (isset($originalMethods[$lowerName])) {
                if (self::functionBodyDiffers($originalMethods[$lowerName], $donorFunction)) {
                    $changedMethods[$lowerName] = $donorFunction;
                }
                continue;
            }
            if ($methodTable->find($lowerName) !== null) {
                throw new HotSwapException(
                    "Hot-swap source for {$className} overrides the inherited method {$lowerName}() - "
                    . 'adding an override of an inherited method is not supported',
                );
            }
            if (in_array($lowerName, self::MAGIC_POINTER_FIELDS, true) || str_starts_with($lowerName, '__')) {
                throw new HotSwapException(
                    "Hot-swap source for {$className} adds the magic method {$lowerName}() - "
                    . 'adding or removing magic methods and constructors is not supported',
                );
            }
            $addedMethods[$lowerName] = $donorFunction;
        }
        $donorMethodTable = self::embeddedTable($donorEntry->function_table);
        foreach ($originalMethods as $lowerName => $originalFunction) {
            if (isset($donorMethods[$lowerName])) {
                continue;
            }
            self::assertRemovableMethod($className, $classEntry, $lowerName, $originalFunction);
            // If an ancestor declares the same method, the donor inherited it during
            // linking: republishing that ancestor entry restores plain inheritance
            $fallbackValue              = $donorMethodTable->find($lowerName);
            $fallbackEntry              = $fallbackValue !== null ? $fallbackValue->getRawFunction() : null;
            $removedMethods[$lowerName] = $fallbackEntry;
        }

        // Constants: only values (and their access flags) may change; the constant
        // set may grow, removal is not supported (see docs/hot-swap.md)
        $originalConstants = self::declaredConstants($classEntry);
        $donorConstants    = self::declaredConstants($donorEntry);
        $changedConstants  = [];
        $addedConstants    = [];
        foreach ($donorConstants as $constantName => $donorConstant) {
            if (!isset($originalConstants[$constantName])) {
                $addedConstants[$constantName] = $donorConstant;
                continue;
            }
            if (self::classConstantDiffers($originalConstants[$constantName], $donorConstant)) {
                $changedConstants[$constantName] = $donorConstant;
            }
        }
        foreach ($originalConstants as $constantName => $originalConstant) {
            if (!isset($donorConstants[$constantName])) {
                throw new HotSwapException(
                    "Hot-swap source for {$className} removes the constant {$constantName} - "
                    . 'constant removal is not supported',
                );
            }
        }

        [$changedPropertySlots, $changedStaticSlots] = self::changedDefaultSlots($classEntry, $donorEntry);

        return new self(
            $className,
            $classEntry,
            $donorEntry,
            $changedMethods,
            $addedMethods,
            $removedMethods,
            $changedConstants,
            $addedConstants,
            $changedPropertySlots,
            $changedStaticSlots,
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
     * @return list<int> Instance default-property table slots that will be updated
     */
    public function getChangedPropertySlots(): array
    {
        return $this->changedPropertySlots;
    }

    /**
     * @return list<int> Static default-member table slots that will be updated
     */
    public function getChangedStaticSlots(): array
    {
        return $this->changedStaticSlots;
    }

    /**
     * Checks if the delta contains no operations at all
     */
    public function isEmpty(): bool
    {
        $changeSets = [
            $this->changedMethods,
            $this->addedMethods,
            $this->removedMethods,
            $this->changedConstants,
            $this->addedConstants,
            $this->changedPropertySlots,
            $this->changedStaticSlots,
        ];

        return array_filter($changeSets) === [];
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
            throw new HotSwapException('Cannot apply a class delta after Core::shutdown()');
        }

        $methodTable = self::embeddedTable($this->classEntry->function_table);
        /** @var list<PendingBodySwap> $pendingSwaps */
        $pendingSwaps = [];
        /** @var list<CData> $replacedValues zval snapshots of the values being replaced */
        $replacedValues = [];
        /** @var list<\Closure> $undoStack */
        $undoStack = [];

        try {
            foreach ($this->changedMethods as $lowerName => $donorFunction) {
                self::injectApplyFailure("method.change:{$lowerName}");
                $entryValue = $methodTable->find($lowerName);
                assert($entryValue !== null);
                $entry   = $entryValue->getRawFunction();
                $pending = FunctionBodySwap::swapUserFunctionBody(
                    $entry,
                    $donorFunction,
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

            foreach ($this->addedMethods as $lowerName => $donorFunction) {
                self::injectApplyFailure("method.add:{$lowerName}");
                // Immortal-by-design container: the engine destroys the body it carries
                // but never frees user zend_function containers (see docs/hot-swap.md)
                $container = Core::trackedNew('zend_function', true);
                FunctionBodySwap::adoptFunctionForPublishing($container, $donorFunction, $this->classEntry);
                $methodTable->addFunctionEntry($lowerName, $container);
                $undoStack[] = function () use ($methodTable, $lowerName, $container): void {
                    self::unpublishFunctionBucket($methodTable, $lowerName);
                    self::releaseAdoptedContainer($container);
                };
            }

            foreach ($this->removedMethods as $lowerName => $fallbackEntry) {
                self::injectApplyFailure("method.remove:{$lowerName}");
                $entryValue = $methodTable->find($lowerName);
                assert($entryValue !== null);
                $previousEntry = $entryValue->getRawFunction();
                self::unpublishFunctionBucket($methodTable, $lowerName);
                if ($fallbackEntry !== null) {
                    // Restore plain inheritance: the bucket owns one name reference and
                    // one body share, exactly like zend_duplicate_function takes
                    self::acquireBucketOwnership($fallbackEntry);
                    self::publishFunctionPointer($methodTable, $lowerName, $fallbackEntry);
                }
                $undoStack[] = function () use ($methodTable, $lowerName, $previousEntry, $fallbackEntry): void {
                    if ($fallbackEntry !== null) {
                        self::unpublishFunctionBucket($methodTable, $lowerName);
                        self::releaseBucketOwnership($fallbackEntry);
                    }
                    self::publishFunctionPointer($methodTable, $lowerName, $previousEntry);
                };
            }

            $constantsTable = self::embeddedTable($this->classEntry->constants_table);
            foreach ($this->changedConstants as $constantName => $donorConstant) {
                self::injectApplyFailure("constant.change:{$constantName}");
                $constantValue = $constantsTable->find($constantName);
                assert($constantValue !== null);
                $originalConstant = Core::cast('zend_class_constant *', $constantValue->getRawPointer());
                $originalValue    = $originalConstant->value;
                $donorValue       = $donorConstant->value;
                assert($originalValue instanceof CData && $donorValue instanceof CData);
                $replacedValues[] = self::replaceZvalSlot($originalValue, $donorValue, $undoStack);
            }

            foreach ($this->addedConstants as $constantName => $donorConstant) {
                self::injectApplyFailure("constant.add:{$constantName}");
                $container = self::mintConstantContainer($donorConstant, $this->classEntry);
                self::publishConstantPointer($constantsTable, $constantName, $container);
                $undoStack[] = function () use ($constantsTable, $constantName, $container): void {
                    self::unpublishFunctionBucket($constantsTable, $constantName);
                    self::releaseConstantContainer($container);
                };
            }

            foreach ($this->changedPropertySlots as $slot) {
                self::injectApplyFailure("property.default:{$slot}");
                $originalTable = $this->classEntry->default_properties_table;
                $donorTable    = $this->donorEntry->default_properties_table;
                assert($originalTable instanceof CData && $donorTable instanceof CData);
                $originalSlot = $originalTable[$slot];
                $donorSlot    = $donorTable[$slot];
                assert($originalSlot instanceof CData && $donorSlot instanceof CData);
                $replacedValues[] = self::replaceZvalSlot($originalSlot, $donorSlot, $undoStack);
            }

            foreach ($this->changedStaticSlots as $slot) {
                self::injectApplyFailure("static.default:{$slot}");
                $originalTable = $this->classEntry->default_static_members_table;
                $donorTable    = $this->donorEntry->default_static_members_table;
                assert($originalTable instanceof CData && $donorTable instanceof CData);
                $originalSlot = $originalTable[$slot];
                $donorSlot    = $donorTable[$slot];
                assert($originalSlot instanceof CData && $donorSlot instanceof CData);
                $replacedValues[] = self::replaceZvalSlot($originalSlot, $donorSlot, $undoStack);
            }

            $touchesClassConstants = array_filter([
                $this->changedConstants,
                $this->addedConstants,
                $this->changedPropertySlots,
                $this->changedStaticSlots,
            ]) !== [];
            if ($touchesClassConstants) {
                self::injectApplyFailure('class.invalidate-constants');
                // New values may be unevaluated constant expressions: drop the
                // "all constants updated" shortcut so the engine re-evaluates lazily
                $previousFlags = $this->classEntry->ce_flags;
                assert(is_int($previousFlags));
                $this->classEntry->ce_flags = $previousFlags & ~Core::ZEND_ACC_CONSTANTS_UPDATED;
                $classEntry                 = $this->classEntry;
                $undoStack[]                = static function () use ($classEntry, $previousFlags): void {
                    $classEntry->ce_flags = $previousFlags;
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
            throw new HotSwapException(
                "Hot-swap of {$this->className} failed and was rolled back: {$error->getMessage()}",
                0,
                $error,
            );
        }

        // Commit: from here on nothing can fail - release everything the class
        // previously owned that the delta replaced
        foreach ($pendingSwaps as $pending) {
            $pending->commit();
        }
        foreach ($replacedValues as $valueSnapshot) {
            Core::call('zval_ptr_dtor', Core::addr($valueSnapshot));
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
    public static function destroyClassEntry(CData $classEntry): void
    {
        if (Core::isShutdown()) {
            // Engine writes are forbidden after shutdown: the unpublished entry leaks
            // (bounded - it is unreachable and carries no trampolines)
            return;
        }
        $rawClass = $classEntry[0];
        assert($rawClass instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawClass);
        Core::call('destroy_zend_class', $valueEntry->getRawValue());
        $valueEntry->release();
    }

    private function releaseDonor(): void
    {
        if ($this->donorReleased) {
            return;
        }
        $this->donorReleased = true;
        self::destroyClassEntry($this->donorEntry);
    }

    private function assertUsable(): void
    {
        if ($this->isApplied) {
            throw new HotSwapException('This class delta has already been applied');
        }
        if ($this->isDiscarded) {
            throw new HotSwapException('This class delta has been discarded');
        }
    }

    private static function injectApplyFailure(string $operationLabel): void
    {
        if (self::$applyFailureInjector !== null) {
            (self::$applyFailureInjector)($operationLabel);
        }
    }

    /**
     * Wraps an embedded engine hashtable (a struct field, not a pointer) in a view
     *
     * @param mixed $rawTable Embedded HashTable structure read from a CData field
     *
     * @return HashTable|ReflectionValue[]
     */
    private static function embeddedTable(mixed $rawTable): HashTable
    {
        assert($rawTable instanceof CData);

        return new HashTable(Core::addr($rawTable));
    }

    /**
     * Returns the user methods the class itself declares, keyed by lowercased name
     *
     * @return array<string, CData> Lowercased method name => zend_function*
     */
    private static function declaredMethods(CData $classEntry): array
    {
        $declaredMethods = [];
        $classAddress    = Core::addressOf($classEntry);
        $methodTable     = self::embeddedTable($classEntry->function_table);
        foreach ($methodTable as $methodName => $methodValue) {
            assert(is_string($methodName) && $methodValue instanceof ReflectionValue);
            $functionEntry = $methodValue->getRawFunction();
            $entryType     = $functionEntry->type;
            assert(is_int($entryType));
            if (($entryType & Core::ZEND_USER_FUNCTION) === 0) {
                continue;
            }
            $commonPointer = $functionEntry->common;
            assert($commonPointer instanceof CData);
            $methodScope = $commonPointer->scope;
            if ($methodScope === null) {
                continue;
            }
            assert($methodScope instanceof CData);
            if (Core::addressOf($methodScope) !== $classAddress) {
                continue;
            }
            $declaredMethods[$methodName] = $functionEntry;
        }

        return $declaredMethods;
    }

    /**
     * Returns the constants the class itself declares, keyed by name
     *
     * @return array<string, CData> Constant name => zend_class_constant*
     */
    private static function declaredConstants(CData $classEntry): array
    {
        $declaredConstants = [];
        $classAddress      = Core::addressOf($classEntry);
        $constantsTable    = self::embeddedTable($classEntry->constants_table);
        foreach ($constantsTable as $constantName => $constantValue) {
            assert(is_string($constantName) && $constantValue instanceof ReflectionValue);
            $rawConstant    = Core::cast('zend_class_constant *', $constantValue->getRawPointer());
            $declaringClass = $rawConstant->ce;
            assert($declaringClass instanceof CData);
            if (Core::addressOf($declaringClass) === $classAddress) {
                $declaredConstants[$constantName] = $rawConstant;
            }
        }

        return $declaredConstants;
    }

    /**
     * Conservative comparison of two compiled function bodies
     *
     * Anything that cannot be proven identical counts as changed: a spurious swap is
     * merely redundant work, while a missed change would keep stale code running.
     */
    private static function functionBodyDiffers(CData $originalFunction, CData $donorFunction): bool
    {
        $originalOpArray = $originalFunction->op_array;
        $donorOpArray    = $donorFunction->op_array;
        assert($originalOpArray instanceof CData && $donorOpArray instanceof CData);

        foreach (['last', 'last_var', 'last_literal', 'T', 'num_args', 'required_num_args', 'fn_flags'] as $field) {
            if ($originalOpArray->{$field} !== $donorOpArray->{$field}) {
                return true;
            }
        }
        $totalOpcodes = $originalOpArray->last;
        assert(is_int($totalOpcodes));
        $opcodesSize     = $totalOpcodes * Core::sizeof(Core::type('zend_op'));
        $originalOpcodes = $originalOpArray->opcodes;
        $donorOpcodes    = $donorOpArray->opcodes;
        assert($originalOpcodes instanceof CData && $donorOpcodes instanceof CData);
        if ($opcodesSize > 0 && \FFI::memcmp($originalOpcodes, $donorOpcodes, $opcodesSize) !== 0) {
            return true;
        }
        $totalLiterals = $originalOpArray->last_literal;
        assert(is_int($totalLiterals));
        $originalLiterals = $originalOpArray->literals;
        $donorLiterals    = $donorOpArray->literals;
        for ($index = 0; $index < $totalLiterals; $index++) {
            assert($originalLiterals instanceof CData && $donorLiterals instanceof CData);
            $originalLiteral = $originalLiterals[$index];
            $donorLiteral    = $donorLiterals[$index];
            assert($originalLiteral instanceof CData && $donorLiteral instanceof CData);
            if (self::zvalDiffers($originalLiteral, $donorLiteral)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conservative comparison of two class constants (value and access flags)
     */
    private static function classConstantDiffers(CData $originalConstant, CData $donorConstant): bool
    {
        $originalValue = $originalConstant->value;
        $donorValue    = $donorConstant->value;
        assert($originalValue instanceof CData && $donorValue instanceof CData);
        $originalExtra = $originalValue->u2;
        $donorExtra    = $donorValue->u2;
        assert($originalExtra instanceof CData && $donorExtra instanceof CData);
        if ($originalExtra->constant_flags !== $donorExtra->constant_flags) {
            return true;
        }

        return self::zvalDiffers($originalValue, $donorValue);
    }

    /**
     * Conservative scalar zval comparison: non-scalar payloads always count as different
     */
    private static function zvalDiffers(CData $firstValue, CData $secondValue): bool
    {
        $firstType  = self::zvalType($firstValue);
        $secondType = self::zvalType($secondValue);
        if ($firstType !== $secondType) {
            return true;
        }
        $firstPayload  = $firstValue->value;
        $secondPayload = $secondValue->value;
        assert($firstPayload instanceof CData && $secondPayload instanceof CData);
        switch ($firstType) {
            case ReflectionValue::IS_UNDEF:
            case ReflectionValue::IS_NULL:
            case ReflectionValue::IS_FALSE:
            case ReflectionValue::IS_TRUE:
                return false;
            case ReflectionValue::IS_LONG:
                return $firstPayload->lval !== $secondPayload->lval;
            case ReflectionValue::IS_DOUBLE:
                return $firstPayload->dval !== $secondPayload->dval;
            case ReflectionValue::IS_STRING:
                $firstString  = $firstPayload->str;
                $secondString = $secondPayload->str;
                assert($firstString instanceof CData && $secondString instanceof CData);

                return StringEntry::fromCData($firstString)->getStringValue()
                    !== StringEntry::fromCData($secondString)->getStringValue();
            default:
                // Arrays, objects and constant expressions: conservatively different
                return true;
        }
    }

    /**
     * Reads the base type byte of a zval structure
     */
    private static function zvalType(CData $valueEntry): int
    {
        $typeUnion = $valueEntry->u1;
        assert($typeUnion instanceof CData);
        $typeInfo = $typeUnion->v;
        assert($typeInfo instanceof CData);
        $baseType = $typeInfo->type;
        assert(is_int($baseType));

        return $baseType;
    }

    /**
     * Rejects removals the class entry keeps direct function pointers for
     */
    private static function assertRemovableMethod(
        string $className,
        CData $classEntry,
        string $lowerName,
        CData $originalFunction,
    ): void {
        $entryAddress = Core::addressOf($originalFunction);
        foreach (self::MAGIC_POINTER_FIELDS as $fieldName) {
            $fieldFunction = $classEntry->{$fieldName};
            if ($fieldFunction === null) {
                continue;
            }
            assert($fieldFunction instanceof CData);
            if (Core::addressOf($fieldFunction) === $entryAddress) {
                throw new HotSwapException(
                    "Hot-swap source for {$className} removes {$lowerName}() which the class entry "
                    . "references as its {$fieldName} - adding or removing magic methods and "
                    . 'constructors is not supported',
                );
            }
        }
    }

    /**
     * Computes the default-table slots whose values differ between the two entries
     *
     * The property surfaces are already proven identical, so slot numbers translate
     * one-to-one. Virtual (hooked, no backing storage) properties have no slots.
     *
     * @return array{list<int>, list<int>} Instance slots and static slots
     */
    private static function changedDefaultSlots(CData $classEntry, CData $donorEntry): array
    {
        $changedPropertySlots = [];
        $changedStaticSlots   = [];
        $zvalSize             = Core::sizeof(Core::type('zval'));
        $slotBase             = Core::type('zend_object')->getStructFieldOffset('properties_table');
        $classAddress         = Core::addressOf($classEntry);
        $propertiesInfo       = self::embeddedTable($classEntry->properties_info);
        foreach ($propertiesInfo as $propertyValue) {
            assert($propertyValue instanceof ReflectionValue);
            $rawInfo        = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $declaringClass = $rawInfo->ce;
            $flags          = $rawInfo->flags;
            $offset         = $rawInfo->offset;
            assert($declaringClass instanceof CData && is_int($flags) && is_int($offset));
            if (Core::addressOf($declaringClass) !== $classAddress) {
                continue;
            }
            if (($flags & Core::ZEND_ACC_VIRTUAL) !== 0) {
                continue;
            }
            if (($flags & Core::ZEND_ACC_STATIC) !== 0) {
                $originalTable = $classEntry->default_static_members_table;
                $donorTable    = $donorEntry->default_static_members_table;
                assert($originalTable instanceof CData && $donorTable instanceof CData);
                $originalSlot = $originalTable[$offset];
                $donorSlot    = $donorTable[$offset];
                assert($originalSlot instanceof CData && $donorSlot instanceof CData);
                if (self::zvalDiffers($originalSlot, $donorSlot)) {
                    $changedStaticSlots[] = $offset;
                }
                continue;
            }
            $slot          = intdiv($offset - $slotBase, $zvalSize);
            $originalTable = $classEntry->default_properties_table;
            $donorTable    = $donorEntry->default_properties_table;
            assert($originalTable instanceof CData && $donorTable instanceof CData);
            $originalSlot = $originalTable[$slot];
            $donorSlot    = $donorTable[$slot];
            assert($originalSlot instanceof CData && $donorSlot instanceof CData);
            if (self::zvalDiffers($originalSlot, $donorSlot)) {
                $changedPropertySlots[] = $slot;
            }
        }
        sort($changedPropertySlots);
        sort($changedStaticSlots);

        return [$changedPropertySlots, $changedStaticSlots];
    }

    /**
     * Replaces a zval slot with the donor's value (donor keeps its own reference)
     *
     * The previous value is NOT released here: its snapshot is returned and the
     * caller releases it at commit time, so a rollback can restore it byte-exact.
     *
     * @param list<\Closure> $undoStack Undo action list of the running apply
     *
     * @return CData Snapshot of the previous slot value (a zval container)
     */
    private static function replaceZvalSlot(CData $targetSlot, CData $donorSlot, array &$undoStack): CData
    {
        $zvalSize = Core::sizeof(Core::type('zval'));
        $snapshot = Core::new('zval');
        Core::memcpy($snapshot, $targetSlot, $zvalSize);
        Core::memcpy($targetSlot, $donorSlot, $zvalSize);
        // The slot now holds its own reference on the (possibly shared) payload
        Core::call('zval_add_ref', Core::addr($targetSlot));
        $undoStack[] = static function () use ($targetSlot, $snapshot, $zvalSize): void {
            Core::call('zval_ptr_dtor', Core::addr($targetSlot));
            Core::memcpy($targetSlot, Core::addr($snapshot), $zvalSize);
        };

        return $snapshot;
    }

    /**
     * Removes a table bucket without running the table destructor over its payload
     *
     * @param HashTable|ReflectionValue[] $table
     */
    private static function unpublishFunctionBucket(HashTable $table, string $lowerKey): void
    {
        $rawTable              = $table->getRawValue();
        $previousDestructor    = $rawTable->pDestructor;
        $rawTable->pDestructor = null;
        try {
            $table->delete($lowerKey);
        } finally {
            $rawTable->pDestructor = $previousDestructor;
        }
    }

    /**
     * Publishes a zend_function pointer into a method table bucket
     *
     * @param HashTable|ReflectionValue[] $table
     */
    private static function publishFunctionPointer(HashTable $table, string $lowerKey, CData $functionEntry): void
    {
        $rawFunction = Core::cast('zend_function *', $functionEntry)[0];
        assert($rawFunction instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $table->add($lowerKey, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Publishes a zend_class_constant pointer into the constants table
     *
     * @param HashTable|ReflectionValue[] $table
     */
    private static function publishConstantPointer(HashTable $table, string $constantName, CData $constant): void
    {
        $rawConstant = Core::cast('zend_class_constant *', Core::addr($constant))[0];
        assert($rawConstant instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawConstant);
        $table->add($constantName, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Takes the ownership a published method bucket holds: one name reference and
     * one body share (mirrors zend_duplicate_function)
     */
    private static function acquireBucketOwnership(CData $functionEntry): void
    {
        $commonPointer = $functionEntry->common;
        assert($commonPointer instanceof CData);
        $namePointer = $commonPointer->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->copy();

        $opArray = $functionEntry->op_array;
        assert($opArray instanceof CData);
        $refCountPointer = $opArray->refcount;
        if ($refCountPointer !== null) {
            assert($refCountPointer instanceof CData);
            $referenceCount = $refCountPointer[0];
            assert(is_int($referenceCount));
            $refCountPointer[0] = $referenceCount + 1;
        }
    }

    /**
     * Returns the ownership taken by acquireBucketOwnership (rollback path only:
     * other holders provably keep the name and the body alive)
     */
    private static function releaseBucketOwnership(CData $functionEntry): void
    {
        $opArray = $functionEntry->op_array;
        assert($opArray instanceof CData);
        $refCountPointer = $opArray->refcount;
        if ($refCountPointer !== null) {
            assert($refCountPointer instanceof CData);
            $referenceCount = $refCountPointer[0];
            assert(is_int($referenceCount) && $referenceCount > 1);
            $refCountPointer[0] = $referenceCount - 1;
        }

        $commonPointer = $functionEntry->common;
        assert($commonPointer instanceof CData);
        $namePointer = $commonPointer->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->releaseReference();
    }

    /**
     * Releases everything an added-method container took (rollback path only)
     */
    private static function releaseAdoptedContainer(CData $container): void
    {
        $containerPointer = Core::cast('zend_function *', Core::addr($container));
        $commonPointer    = $containerPointer->common;
        assert($commonPointer instanceof CData);
        $namePointer = $commonPointer->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->releaseReference();

        FunctionBodySwap::releaseSwappedInBody(
            $containerPointer,
            Core::addressOf($containerPointer),
            1,
            false,
            null,
        );
        Core::untrackAndFree(Core::addr($container));
    }

    /**
     * Mints an immortal zend_class_constant container adopting the donor's constant
     *
     * @param CData $declaringClass Target class entry the constant will belong to
     */
    private static function mintConstantContainer(CData $donorConstant, CData $declaringClass): CData
    {
        $container = Core::trackedNew('zend_class_constant', true);
        Core::memcpy($container, $donorConstant, Core::sizeof($container));
        // The engine releases the payload of constants whose ce matches the class
        // being destroyed - the adopted constant belongs to the target class now
        $container->ce = $declaringClass;

        // The container owns its own payload references: the engine releases the
        // value, doc comment and attributes when the declaring class is destroyed
        $containerValue = $container->value;
        assert($containerValue instanceof CData);
        Core::call('zval_add_ref', Core::addr($containerValue));
        $docComment = $container->doc_comment;
        if ($docComment !== null) {
            assert($docComment instanceof CData);
            StringEntry::fromCData($docComment)->copy();
        }
        $attributes = $container->attributes;
        if ($attributes !== null) {
            assert($attributes instanceof CData);
            self::addHashTableReference($attributes);
        }

        return $container;
    }

    /**
     * Releases everything an added-constant container took (rollback path only)
     */
    private static function releaseConstantContainer(CData $container): void
    {
        $containerValue = $container->value;
        assert($containerValue instanceof CData);
        Core::call('zval_ptr_dtor', Core::addr($containerValue));
        $docComment = $container->doc_comment;
        if ($docComment !== null) {
            assert($docComment instanceof CData);
            StringEntry::fromCData($docComment)->releaseReference();
        }
        $attributes = $container->attributes;
        if ($attributes !== null) {
            assert($attributes instanceof CData);
            self::dropHashTableReference($attributes);
        }
        Core::untrackAndFree(Core::addr($container));
    }

    /**
     * Takes one reference on an engine hashtable (no-op for immutable tables)
     */
    private static function addHashTableReference(CData $table): void
    {
        $gcHeader = $table->gc;
        assert($gcHeader instanceof CData);
        $gcTypeUnion = $gcHeader->u;
        assert($gcTypeUnion instanceof CData);
        $typeInfo = $gcTypeUnion->type_info;
        assert(is_int($typeInfo));
        if (($typeInfo & Core::engineConstant('GC_IMMUTABLE')) !== 0) {
            return;
        }
        $referenceCount = $gcHeader->refcount;
        assert(is_int($referenceCount));
        $gcHeader->refcount = $referenceCount + 1;
    }

    /**
     * Returns a reference taken by addHashTableReference (other holders remain)
     */
    private static function dropHashTableReference(CData $table): void
    {
        $gcHeader = $table->gc;
        assert($gcHeader instanceof CData);
        $gcTypeUnion = $gcHeader->u;
        assert($gcTypeUnion instanceof CData);
        $typeInfo = $gcTypeUnion->type_info;
        assert(is_int($typeInfo));
        if (($typeInfo & Core::engineConstant('GC_IMMUTABLE')) !== 0) {
            return;
        }
        $referenceCount = $gcHeader->refcount;
        assert(is_int($referenceCount) && $referenceCount > 1);
        $gcHeader->refcount = $referenceCount - 1;
    }
}
