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
use ZEngine\Type\ArgumentEntry;
use ZEngine\Type\HashTable;
use ZEngine\Type\LiveRange;
use ZEngine\Type\OpLine;
use ZEngine\Type\StringEntry;
use ZEngine\Type\TryCatchElement;

trait FunctionLikeTrait
{
    private CData $pointer;

    /**
     * Changes the name of this function/method
     *
     * The function structure releases its previous name (if any) and takes over one owned
     * reference on the new one, following engine assignment semantics.
     *
     * @internal
     */
    public function setFunctionName(string $newName): void
    {
        $commonPointer = $this->getCommonPointer();
        $previousName  = $commonPointer->function_name;
        if ($previousName !== null) {
            assert($previousName instanceof CData);
            StringEntry::fromCData($previousName)->releaseReference();
        }
        $commonPointer->function_name = StringEntry::fromString($newName)
            ->transferReferenceOwnership()
            ->getRawValue();
    }

    /**
     * Marks this function as a closure or converts a closure-backed entry into a regular
     * function/method (toggles the ZEND_ACC_CLOSURE flag)
     *
     * @internal
     */
    public function setClosureFlag(bool $isClosure = true): void
    {
        $commonPointer = $this->getCommonPointer();
        $flags         = $commonPointer->fn_flags;
        assert(is_int($flags));
        if ($isClosure) {
            $commonPointer->fn_flags = $flags | Core::ZEND_ACC_CLOSURE;
        } else {
            $commonPointer->fn_flags = $flags & (~Core::ZEND_ACC_CLOSURE);
        }
    }

    /**
    * Declares method as deprecated/non-deprecated
    */
    public function setDeprecated(bool $isDeprecated = true): void
    {
        if ($isDeprecated) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_DEPRECATED;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_DEPRECATED);
        }
    }

    /**
     * Declares method as variadic/non-variadic
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setVariadic(bool $isVariadic = true): void
    {
        if ($isVariadic) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_VARIADIC;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_VARIADIC);
        }
    }

    /**
     * Declares method as generator/non-generator
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setGenerator(bool $isGenerator = true): void
    {
        if ($isGenerator) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_GENERATOR;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_GENERATOR);
        }
    }

    /**
     * Function flags that describe the body of the function (as opposed to its declaration):
     * they must always travel together with the op_array they were compiled for.
     *
     * ZEND_ACC_HEAP_RT_CACHE and the run_time_cache/T fields live in zend_function.common
     * since PHP 8.2, so a whole-common copy from the previous entry would graft a run-time
     * cache and a temporaries count sized for the OLD opcodes onto the new body - the VM
     * then reads cache slots out of bounds and crashes.
     */
    private const BODY_LEVEL_FUNCTION_FLAGS = Core::ZEND_ACC_HEAP_RT_CACHE
        | Core::ZEND_ACC_GENERATOR
        | Core::ZEND_ACC_VARIADIC
        | Core::ZEND_ACC_RETURN_REFERENCE
        | Core::ZEND_ACC_HAS_RETURN_TYPE
        | Core::ZEND_ACC_HAS_TYPE_HINTS
        | Core::ZEND_ACC_STRICT_TYPES
        | Core::ZEND_ACC_IMMUTABLE;

    /**
     * Redefines an existing method in the class with closure
     * @internal
     */
    public function redefine(\Closure $newCode): void
    {
        $this->ensureCompatibleClosure($newCode);

        if (!$this->isInternal()) {
            $selfExecutionState = Core::$executor->getExecutionState();
            $newCodeEntry       = $selfExecutionState->getArgument(0)->getRawObject();
            $newCodeEntry       = Core::cast('zend_closure *', $newCodeEntry);

            // Remember the declaration identity of the redefined entry: it survives the
            // body replacement, while everything executor-related (opcodes, literals,
            // run-time cache, temporaries count) comes from the new closure body
            $targetCommon  = $this->getCommonPointer();
            $previousName  = $targetCommon->function_name;
            $previousScope = $targetCommon->scope;
            $previousProto = $targetCommon->prototype;
            $previousFlags = $targetCommon->fn_flags;
            $newFunction   = $newCodeEntry->func;
            assert(is_int($previousFlags) && $newFunction instanceof CData);
            $newFunctionCommon = $newFunction->common;
            assert($newFunctionCommon instanceof CData);
            $newBodyFlags = $newFunctionCommon->fn_flags;
            assert(is_int($newBodyFlags));

            // Replace the whole function with the closure-backed one (the donor closure
            // object itself stays untouched - it keeps sole ownership of its own fields)
            Core::memcpy($this->pointer, Core::addr($newFunction), Core::sizeof($newFunction));

            // Restore the declaration identity: the single owned reference on the previous
            // name stays with this entry, declaration-level flags (visibility, static,
            // final, closure bit) are kept while body-level flags follow the new op_array
            $targetCommon->function_name = $previousName;
            $targetCommon->scope         = $previousScope;
            $targetCommon->prototype     = $previousProto;
            $targetCommon->fn_flags      = ($previousFlags & ~self::BODY_LEVEL_FUNCTION_FLAGS)
                | ($newBodyFlags & self::BODY_LEVEL_FUNCTION_FLAGS);
        } else {
            // For internal function we can simply adjust a handler
            $this->pointer->handler = function (CData $executeData, CData $returnValue) use ($newCode): void {
                $rawValue   = ReflectionValue::fromValueEntry($returnValue);
                $stackTrace = debug_backtrace(0, 2);
                $result     = $newCode(...$stackTrace[1]['args']);
                $rawValue->setNativeValue($result);
            };
        }
    }

    /**
     * @inheritDoc
     */
    public function isUserDefined(): bool
    {
        return (bool) ($this->pointer->type & Core::ZEND_USER_FUNCTION);
    }

    /**
     * Returns the engine attributes table of this function or null if the function has no attributes
     *
     * Each element of the returned table is an IS_PTR value pointing to a zend_attribute:
     * wrap it with ReflectionAttributeEntry::fromValueEntry() for structured access.
     *
     * @return HashTable|ReflectionValue[]|null
     */
    public function getAttributesTable(): ?HashTable
    {
        $attributes = $this->getCommonPointer()->attributes;
        if ($attributes === null) {
            return null;
        }
        assert($attributes instanceof CData);

        return new HashTable($attributes);
    }

    /**
     * Returns the iterable generator of opcodes for this function
     *
     * @return iterable|OpLine[]
     */
    public function getOpCodes(): iterable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Opcodes are available only for user-defined functions');
        }
        $opCodes      = [];
        $opcodeIndex  = 0;
        $totalOpcodes = $this->pointer->op_array->last;
        while ($opcodeIndex < $totalOpcodes) {
            $opCode = new OpLine(
                Core::addr($this->pointer->op_array->opcodes[$opcodeIndex++]),
            );
            $opCodes[] = $opCode;
        }

        return $opCodes;
    }

    /**
     * Returns the total number of literals
     */
    public function getNumberOfLiterals(): int
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $lastLiteral = $this->pointer->op_array->last_literal;

        return $lastLiteral;
    }

    /**
     * Returns one single literal's value by it's index
     *
     * @param int $index
     *
     * @return ReflectionValue
     */
    public function getLiteral(int $index): ReflectionValue
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $lastLiteral = $this->pointer->op_array->last_literal;
        if ($index > $lastLiteral) {
            throw new \OutOfBoundsException("Literal index {$index} is out of bounds, last is {$lastLiteral}");
        }
        $literal = $this->pointer->op_array->literals[$index];

        return ReflectionValue::fromValueEntry($literal);
    }

    /**
     * Returns list of literals, associated with this entry
     *
     * @return ReflectionValue[]
     */
    public function getLiterals(): iterable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $literalValueGenerator = function () {
            $literalIndex  = 0;
            $totalLiterals = $this->pointer->op_array->last_literal;
            while ($literalIndex < $totalLiterals) {
                $item = $this->pointer->op_array->literals[$literalIndex];
                $literalIndex++;
                yield ReflectionValue::fromValueEntry($item);
            }
        };

        return $literalValueGenerator();
    }

    /**
     * Returns one argument info entry by its position
     *
     * Entry layout follows the engine: entries 0..N-1 describe the declared parameters,
     * entry N (present only for variadic functions) describes the variadic parameter and
     * entry -1 (present only for functions with a declared return type) holds the
     * return-type information.
     *
     * Internal functions store zend_internal_arg_info entries whose names are plain
     * C strings; the return-type entry of an internal function reuses the name field for
     * the required-argument count, so the name of a return entry is always reported as
     * null (user functions store no name there either).
     */
    public function getArgumentInfo(int $index): ArgumentEntry
    {
        $commonPointer = $this->getCommonPointer();
        $functionFlags = $commonPointer->fn_flags;
        $numberOfArgs  = $commonPointer->num_args;
        assert(is_int($functionFlags) && is_int($numberOfArgs));
        $isVariadic     = ($functionFlags & Core::ZEND_ACC_VARIADIC)        !== 0;
        $hasReturnEntry = ($functionFlags & Core::ZEND_ACC_HAS_RETURN_TYPE) !== 0;
        $minIndex       = $hasReturnEntry ? ArgumentEntry::RETURN_ENTRY_INDEX : 0;
        $maxIndex       = $numberOfArgs - 1 + ($isVariadic ? 1 : 0);
        if ($index < $minIndex || $index > $maxIndex) {
            throw new \OutOfBoundsException(
                "Argument info index {$index} is out of bounds, valid range is {$minIndex}..{$maxIndex}",
            );
        }
        // For internal functions the same field is typed as zend_internal_arg_info *; both
        // structures share size and field layout, only the name representation differs
        $argInfoTable = $commonPointer->arg_info;
        if ($argInfoTable === null) {
            throw new \ReflectionException('Function does not provide argument info entries');
        }
        assert($argInfoTable instanceof CData);
        // Explicit pointer arithmetic also resolves the -1 return entry; the view type
        // selects the right name representation for the entry
        $entryType = $this->isUserDefined() ? 'zend_arg_info' : 'zend_internal_arg_info';
        $entry     = Core::pointerAtAddress(
            "{$entryType} *",
            Core::addressOf($argInfoTable) + $index * Core::sizeof(Core::type($entryType)),
        );
        $entryTypeStruct = $entry->type;
        assert($entryTypeStruct instanceof CData);
        $typeMask = $entryTypeStruct->type_mask;
        assert(is_int($typeMask));

        $name = null;
        // The name of the -1 return entry is never read: user functions store NULL there
        // and internal functions reuse the field for the required-argument count, so
        // dereferencing it would interpret a small integer as a C string pointer
        if ($index !== ArgumentEntry::RETURN_ENTRY_INDEX) {
            $rawName = $entry->name;
            if ($this->isUserDefined()) {
                if ($rawName !== null) {
                    assert($rawName instanceof CData);
                    $name = StringEntry::fromCData($rawName)->getStringValue();
                }
            } else {
                // FFI materializes const char* fields directly as PHP strings
                assert($rawName === null || is_string($rawName));
                $name = $rawName;
            }
        }

        return new ArgumentEntry($index, $name, $typeMask);
    }

    /**
     * Returns the static variables table of this function (borrowed engine view)
     *
     * On PHP 8.4 the engine keeps two tables: op_array.static_variables holds the default
     * values from the declaration, while the map pointer static_variables_ptr points to
     * the live table once it was materialized (on the first ZEND_BIND_STATIC execution,
     * or already at creation time for closures). The live table is returned whenever it
     * is materialized as a plain pointer; the default table is returned otherwise -
     * including the map-ptr offset form (low bit set) used by opcache-shared immutable
     * functions, whose per-request table is not directly addressable. Returns null when
     * the function declares no static variables at all.
     *
     * Memory ownership contract (see docs/long-running.md): the returned HashTable is a
     * BORROWED view over the engine-owned table - no addref is taken, the view stays
     * valid only while the function entry is alive, and nothing on the PHP side may
     * release the table or its buckets. Note that already-bound slots hold IS_REFERENCE
     * zvals shared with the executing function: a value read from them (eg via
     * ReflectionValue::getNativeValue()) is a live PHP reference, so writing through it
     * changes the static variable itself.
     *
     * @return (HashTable&iterable<string|null, ReflectionValue>)|null
     */
    // @phpstan-ignore method.childReturnType (borrowed engine table view instead of the native value array)
    #[\ReturnTypeWillChange]
    public function getStaticVariables(): ?HashTable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Static variables are available only for user-defined functions');
        }
        $opArray = $this->pointer->op_array;
        assert($opArray instanceof CData);
        $liveTable = $opArray->static_variables_ptr__ptr;
        if ($liveTable !== null) {
            assert($liveTable instanceof CData);
            if ((Core::addressOf($liveTable) & 1) === 0) {
                return new HashTable($liveTable);
            }
        }
        $defaultTable = $opArray->static_variables;
        if ($defaultTable === null) {
            return null;
        }
        assert($defaultTable instanceof CData);

        return new HashTable($defaultTable);
    }

    /**
     * Returns the list of try/catch/finally regions of this function
     *
     * @return list<TryCatchElement>
     */
    public function getTryCatchElements(): array
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Try/catch elements are available only for user-defined functions');
        }
        $elements = [];
        $opArray  = $this->pointer->op_array;
        assert($opArray instanceof CData);
        $totalElements = $opArray->last_try_catch;
        assert(is_int($totalElements));
        for ($index = 0; $index < $totalElements; $index++) {
            $elementTable = $opArray->try_catch_array;
            assert($elementTable instanceof CData);
            $rawElement = $elementTable[$index];
            assert($rawElement instanceof CData);
            $tryOp      = $rawElement->try_op;
            $catchOp    = $rawElement->catch_op;
            $finallyOp  = $rawElement->finally_op;
            $finallyEnd = $rawElement->finally_end;
            assert(is_int($tryOp) && is_int($catchOp) && is_int($finallyOp) && is_int($finallyEnd));
            $elements[] = new TryCatchElement($tryOp, $catchOp, $finallyOp, $finallyEnd);
        }

        return $elements;
    }

    /**
     * Returns the list of temporary-variable live ranges of this function
     *
     * @return list<LiveRange>
     */
    public function getLiveRanges(): array
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Live ranges are available only for user-defined functions');
        }
        $liveRanges = [];
        $opArray    = $this->pointer->op_array;
        assert($opArray instanceof CData);
        $totalRanges = $opArray->last_live_range;
        assert(is_int($totalRanges));
        for ($index = 0; $index < $totalRanges; $index++) {
            $rangeTable = $opArray->live_range;
            assert($rangeTable instanceof CData);
            $rawRange = $rangeTable[$index];
            assert($rawRange instanceof CData);
            $var   = $rawRange->var;
            $start = $rawRange->start;
            $end   = $rawRange->end;
            assert(is_int($var) && is_int($start) && is_int($end));
            $liveRanges[] = new LiveRange($var, $start, $end);
        }

        return $liveRanges;
    }

    /**
     * Returns the hash key for function or method
     */
    abstract protected function getHash(): string;

    /**
     * Checks if the given closure signature is compatible to original one (number of arguments, type hints, etc)
     *
     * @throws \ReflectionException if closure signature is not compatible with current function/method
     */
    private function ensureCompatibleClosure(\Closure $newCode): void
    {
        /** @var \ReflectionFunction[] $reflectionPair */
        $reflectionPair = [$this, new \ReflectionFunction($newCode)];
        $signatures     = [];
        foreach ($reflectionPair as $index => $reflectionFunction) {
            $signature = 'function ';
            if ($reflectionFunction->returnsReference()) {
                $signature .= '&';
            }
            $signature .= '(';
            $parameters = [];
            foreach ($reflectionFunction->getParameters() as $reflectionParameter) {
                $parameter = '';
                if ($reflectionParameter->hasType()) {
                    $type = $reflectionParameter->getType();
                    if ($type->allowsNull()) {
                        $parameter .= '?';
                    }
                    $parameter .= $type->getName() . ' ';
                }
                if ($reflectionParameter->isPassedByReference()) {
                    $parameter .= '&';
                }
                if ($reflectionParameter->isVariadic()) {
                    $parameter .= '...';
                }
                $parameter .= '$';
                $parameter .= $reflectionParameter->getName();
                $parameters[] = $parameter;
            }
            $signature .= join(', ', $parameters);
            $signature .= ')';
            if ($reflectionFunction->hasReturnType()) {
                $signature .= ': ';
                $type = $reflectionFunction->getReturnType();
                if ($type->allowsNull()) {
                    $signature .= '?';
                }
                $signature .= $type->getName();
            }
            $signatures[] = $signature;
        }

        if ($signatures[0] !== $signatures[1]) {
            throw new \ReflectionException(
                'Given function signature: "' . $signatures[1] . '"' .
                ' should be compatible with original "' . $signatures[0] . '"',
            );
        }
    }

    /**
     * Returns a pointer to the underlying zend_function structure
     *
     * Engine APIs that take a `zend_function *` (for example the zend_observer
     * per-function handler attachers) need the address of the reflected entry,
     * not a copy of it. The entry is referenced directly, so the caller writes
     * through to the live engine structure.
     *
     * @internal
     */
    public function getRawFunctionPointer(): CData
    {
        // $this->pointer is already the zend_function* the reflection wraps
        return $this->pointer;
    }

    /**
     * Returns a pointer to the common structure (to work natively with zend_function and zend_internal_function)
     */
    private function getCommonPointer(): CData
    {
        // For zend_internal_function we have same fields directly in current structure.
        // The check goes through the low-level structure (not native isInternal()) so it
        // also works on wrappers whose native reflection state is not initialized yet
        if (!$this->isUserDefined()) {
            $pointer = $this->pointer;
        } else {
            // zend_function uses "common" struct to store all important fields
            $pointer = $this->pointer->common;
        }

        return $pointer;
    }
}
