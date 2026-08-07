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
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Type\ArgumentEntry;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;
use ZEngine\Type\LiveRange;
use ZEngine\Type\OpLine;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;
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
     * Redefines an existing method in the class with closure
     *
     * The previous function body is destroyed with engine semantics (the entry keeps
     * one owned share of the new closure body instead) - repeated redefinitions of
     * the same entry are memory-flat, see FunctionBodySwap. An opcache-shared
     * (ZEND_ACC_IMMUTABLE) global function is first copied out of shared memory into
     * a writable entry (its SHM body stays allocated, never freed); a method of an
     * opcache-shared class is redefined on the writable copy of its declaring class,
     * which is copied out of shared memory the same way - see docs/hot-swap.md for
     * the matrix and the copy-out caveats.
     *
     * @internal
     */
    public function redefine(\Closure $newCode): void
    {
        $this->ensureCompatibleClosure($newCode);

        if (!$this->isInternal()) {
            $selfExecutionState = Core::$executor->getExecutionState();
            $newCodeEntry       = $selfExecutionState->getArgument(0)->getRawObject();
            $closureEntry       = ClosureEntry::fromCData(Core::cast('zend_closure *', $newCodeEntry));
            $newFunction        = $closureEntry->getRawFunction();

            $isSharedMemoryEntry = $this->isImmutable();
            if ($isSharedMemoryEntry) {
                // Copy the entry out of SHM: the per-process bucket that publishes it is
                // repointed at a writable container, the SHM original stays untouched
                // (never written, never freed). A method entry lives inside the shared
                // class entry, so the whole class is copied out and the swap targets the
                // method entry of the writable copy.
                $entryScope    = $this->getCommonPointer()->scope;
                $this->pointer = $entryScope !== null
                    ? $this->copyMethodOutOfSharedMemory($entryScope)
                    : $this->copyOutOfSharedMemory();
            }

            $entryFunction = ReflectionFunction::fromCData($this->pointer);
            $donorFunction = ReflectionFunction::fromCData(Core::addr($newFunction));
            FunctionBodySwap::swapUserFunctionBody(
                $entryFunction,
                $donorFunction,
                preserveDeclaration: true,
                // A shared-memory body is immortal by definition and must not be freed
                destroyPrevious: !$isSharedMemoryEntry,
                // Subclass method tables may share this very structure - every such
                // bucket releases one body reference when the engine destroys it
                publishedShares: FunctionBodySwap::countPublishedShares($entryFunction),
            )->commit();
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
     * Copies this opcache-shared global function out of shared memory into a writable
     * container and repoints its per-process function-table bucket at the copy
     *
     * The SHM original is left completely untouched (never written, never freed). The
     * writable container is a malloc-backed immortal-by-design block (see
     * docs/long-running.md): the engine's function table destructor releases the body
     * it will eventually carry but never frees user zend_function containers, and a
     * request-lifetime block would dangle if the engine walked it after the FFI request
     * memory was reclaimed.
     *
     * @return CData Writable zend_function pointer now published in the table
     */
    private function copyOutOfSharedMemory(): CData
    {
        $lowerKey    = strtolower($this->getName());
        $bucketValue = Core::$executor->functionTable->find($lowerKey);
        if ($bucketValue === null) {
            throw SharedMemoryException::functionNotPublished($lowerKey);
        }

        $writableEntry = Core::trackedNew('zend_function', true);
        Core::memcpy($writableEntry, $this->pointer, Core::sizeof($writableEntry));

        // The writable copy is not opcache-shared anymore; everything it points at
        // still is, so the body must be replaced (not freed) by the caller. The copy
        // is a user function, so its common struct carries the fn_flags word.
        $writablePointer = Core::cast('zend_function *', Core::addr($writableEntry));
        $writableCommon  = ReflectionFunction::fromCData($writablePointer)->getCommonPointer();
        $writableCommon->fn_flags &= (~Core::ZEND_ACC_IMMUTABLE);

        // Repoint the per-process bucket at the writable copy (IS_PTR payload)
        $bucketValue->setPointer($writablePointer);

        return $writablePointer;
    }

    /**
     * Copies the opcache-shared class this method belongs to out of shared memory and
     * returns the writable method entry of the copy
     *
     * A method table lives INSIDE the class entry, so there is no per-method bucket to
     * repoint: the whole class entry is copied out instead (see
     * ReflectionClass::copyOutOfSharedMemory() for the copy model and its caveats) and the
     * body swap targets the entry the copy publishes, which is a writable zend_op_array
     * struct still sharing the compiled body with shared memory.
     *
     * @param CData $entryScope Shared-memory zend_class_entry this method is declared in
     *
     * @return CData Writable zend_function pointer published in the copied method table
     */
    private function copyMethodOutOfSharedMemory(CData $entryScope): CData
    {
        $declaringClass = ReflectionClass::fromCData($entryScope);
        $declaringClass->copyOutOfSharedMemory();

        $methodName  = $this->getName();
        $methodValue = $declaringClass->getMethodTable()->find(strtolower($methodName));
        if ($methodValue === null) {
            throw SharedMemoryException::methodMissingAfterCopyOut($declaringClass->getName(), $methodName);
        }

        return $methodValue->getRawFunction();
    }

    /**
     * @inheritDoc
     */
    public function isUserDefined(): bool
    {
        return (bool) ($this->pointer->type & Core::ZEND_USER_FUNCTION);
    }

    /**
     * Checks if this function entry lives in opcache shared memory (ZEND_ACC_IMMUTABLE)
     *
     * Opcache marks every function it publishes from its shared memory as immutable:
     * such an entry is visible to all worker processes and must never be written or
     * freed in place - redefine() copies immutable global functions out of SHM first
     * (see docs/hot-swap.md). Only user functions can be opcache-shared; internal
     * functions are persistent process memory, a different lifetime class entirely.
     */
    public function isImmutable(): bool
    {
        if (!$this->isUserDefined()) {
            return false;
        }

        return ($this->getCommonPointer()->fn_flags & Core::ZEND_ACC_IMMUTABLE) !== 0;
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

        return HashTable::fromCData($attributes);
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
     * Returns the names of the compiled variables (CV slots) of this function
     *
     * The array index is the CV slot number: the same numbering that
     * ExecutionData::getCallVariableByNumber() uses, so pairing both gives named
     * access to the live frame variables of any user function. Declared arguments
     * occupy the first slots in declaration order; names carry no '$' sigil.
     *
     * Internal functions have no compiled variables, so the list is empty for them
     * (unlike opcodes/literals this is a real empty set, not a misuse - no exception).
     *
     * @return array<int, string> Variable names indexed by CV slot number
     */
    public function getVariableNames(): array
    {
        if (!$this->isUserDefined()) {
            return [];
        }
        $opArray        = $this->getOpArrayPointer();
        $variableTable  = $opArray->vars;
        $totalVariables = $opArray->last_var;
        if ($variableTable === null || $totalVariables === 0) {
            return [];
        }

        $names = [];
        foreach (new StructArray($variableTable, $totalVariables) as $index => $namePointer) {
            $names[$index] = StringEntry::fromCData($namePointer)->getStringValue();
        }

        return $names;
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
        $commonPointer  = $this->getCommonPointer();
        $functionFlags  = $commonPointer->fn_flags;
        $numberOfArgs   = $commonPointer->num_args;
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
     * On PHP 8.4+ the engine keeps two tables: op_array.static_variables holds the default
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
     * @return (HashTable&iterable<int|string, ReflectionValue>)|null
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
                return HashTable::fromCData($liveTable);
            }
        }
        $defaultTable = $opArray->static_variables;
        if ($defaultTable === null) {
            return null;
        }
        assert($defaultTable instanceof CData);

        return HashTable::fromCData($defaultTable);
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
     * Returns a pointer to the common structure (to work natively with zend_function and zend_internal_function)
     *
     * The declared shape (see phpstan.dist.neon typeAliases and AGENTS.md) is the
     * single narrowing point for every common-struct field this trait touches.
     *
     * @return ZendFunctionCommonShape
     *
     * @internal shared with the body-swap machinery (FunctionBodySwap)
     */
    public function getCommonPointer(): object
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

        /** @var ZendFunctionCommonShape $pointer */
        return $pointer;
    }

    /**
     * Returns the shaped view of the op_array this user function executes
     *
     * The declared shape (see phpstan.dist.neon typeAliases and AGENTS.md) is the
     * single narrowing point for the op_array fields the swap machinery touches.
     * Only user-defined functions carry an op_array.
     *
     * @return ZendOpArrayShape
     *
     * @internal shared with the body-swap machinery (FunctionBodySwap)
     */
    public function getOpArrayPointer(): object
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('op_array is available only for user-defined functions');
        }
        $opArray = $this->pointer->op_array;

        /** @var ZendOpArrayShape $opArray */
        return $opArray;
    }

    /**
     * Returns the numeric address of the underlying zend_function entry
     *
     * The address is the identity of the published entry: warmed-up caches, method
     * table buckets and VM frames reference the entry by this pointer, so callers
     * compare addresses instead of poking the raw CData handle.
     */
    public function getAddress(): int
    {
        return Core::addressOf($this->pointer);
    }

    /**
     * Returns the raw zend_function pointer this reflection wraps
     *
     * @internal shared with the body-swap machinery (FunctionBodySwap), which performs
     *           the low-level engine surgery on the entry
     */
    public function getEntryPointer(): CData
    {
        return $this->pointer;
    }
}
