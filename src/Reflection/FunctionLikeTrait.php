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
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_function_common;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_op_array;
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
    /**
     * @var zend_function|zend_internal_function Typed view of the wrapped entry: the
     *      union view for user functions, the internal-function view for internal
     *      entries (matching ReflectionValue::getRawFunction()); the runtime value is
     *      the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

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
     * Returns the name of this function/method or null for main-scope/unnamed entries
     * (callers render "{main}" for those)
     *
     * Unlike the native getName(), this accessor is purely pointer-based: it never
     * constructs native reflection and never throws for a valid entry, so it is safe
     * for closure-backed entries and inside FFI callbacks (where an uncaught throw
     * from native reflection construction is fatal). Internal functions are served
     * through the same common struct, which carries their name directly.
     */
    public function getFunctionName(): ?string
    {
        $functionName = $this->getCommonPointer()->function_name;
        if ($functionName === null) {
            return null;
        }

        return StringEntry::fromCData($functionName)->getStringValue();
    }

    /**
     * Returns the file a user-defined function/method was declared in or null for
     * internal functions
     *
     * Unlike the native accessor (which reports false for internal functions), this
     * one is purely pointer-based and null-returning: it never constructs native
     * reflection and never throws for a valid entry, so it is safe for closure-backed
     * entries and inside FFI callbacks (where an uncaught throw from native reflection
     * construction is fatal).
     */
    // @phpstan-ignore method.childReturnType (closure-safe nullable accessor instead of the native string|false)
    #[\ReturnTypeWillChange]
    public function getFileName(): ?string
    {
        if (!$this->isUserDefined()) {
            return null;
        }
        $fileName = $this->getOpArrayPointer()->filename;
        if ($fileName === null) {
            return null;
        }

        return StringEntry::fromCData($fileName)->getStringValue();
    }

    /**
     * Marks this function as a closure or converts a closure-backed entry into a regular
     * function/method (toggles the ZEND_ACC_CLOSURE flag)
     *
     * @internal
     */
    public function setClosureFlag(bool $isClosure = true): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_CLOSURE, $isClosure);
    }

    /**
    * Declares method as deprecated/non-deprecated
    */
    public function setDeprecated(bool $isDeprecated = true): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_DEPRECATED, $isDeprecated);
    }

    /**
     * Declares method as variadic/non-variadic
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setVariadic(bool $isVariadic = true): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_VARIADIC, $isVariadic);
    }

    /**
     * Declares method as generator/non-generator
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setGenerator(bool $isGenerator = true): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_GENERATOR, $isGenerator);
    }

    /**
     * Turns exactly one ZEND_ACC_* flag of this entry on or off
     */
    private function setFunctionFlag(int $mask, bool $isEnabled): void
    {
        $this->replaceFunctionFlags($isEnabled ? 0 : $mask, $isEnabled ? $mask : 0);
    }

    /**
     * Clears the given fn_flags and sets the given ones, in a single write
     *
     * One read/modify/write against one getCommonPointer() lookup: the seven flag setters
     * built on it used to repeat the `if ($on) { |= M; } else { &= ~M; }` dance and to
     * resolve the common pointer twice per call.
     */
    private function replaceFunctionFlags(int $clearMask, int $setMask): void
    {
        $commonPointer = $this->getCommonPointer();
        $flags         = $commonPointer->fn_flags;
        assert(is_int($flags));

        $commonPointer->fn_flags = ($flags & (~$clearMask)) | $setMask;
    }

    /**
     * Replaces the body of this function/method with the body of a closure or of another
     * user function/method (the donor), in place
     *
     * The entry keeps its identity - name, scope, prototype, visibility, static and final
     * flags - while the compiled body, the signature (arg_info), the attributes and the doc
     * comment follow the donor. The published zend_function pointer never changes, so
     * warmed-up inline caches, subclass method buckets and prototype links stay valid and
     * dispatch the new body immediately. A donor must carry the entry's exact signature and
     * its static-ness, and may not be abstract or internal. `self`/`parent` types are
     * resolved to the DONOR's declaring class by the compiler and would keep enforcing it,
     * so a donor spells the entry's class explicitly where the entry says `self`.
     *
     * With `$preserveAs` the PREVIOUS body is kept callable: it is published under that name
     * before the swap - as a private method of the declaring class, carrying ZEND_ACC_CHANGED
     * when an ancestor already declares a private method of that name, and inherited by every
     * subclass linked earlier; or as a second global function. The alias is a regular entry:
     * `redefine()` with the alias as the donor swaps the previous body back in, and
     * `ReflectionClass::removeMethods()` drops the alias again. An alias name the class
     * already declares is refused, so a second preserving redefine() of the same method needs
     * a different name (or the first alias restored/removed first).
     *
     * The previous body is destroyed with engine semantics (the entry keeps one owned share
     * of the new body instead, and a preserved alias keeps one of the previous one) -
     * repeated redefinitions of the same entry are memory-flat, see FunctionBodySwap. An
     * opcache-shared (ZEND_ACC_IMMUTABLE) global function is first copied out of shared
     * memory into a writable entry (its SHM body stays allocated, never freed); a method of
     * an opcache-shared class is redefined on the writable copy of its declaring class,
     * which is copied out of shared memory the same way - see docs/hot-swap.md for the matrix
     * and the copy-out caveats. Preserving an alias on a shared-memory class is refused while
     * a subclass linked against the shared entry exists (it could never see the alias).
     *
     * An internal function keeps the handler-trampoline path: only a closure can redefine
     * it, and its body cannot be preserved under an alias.
     *
     * @param \Closure|FunctionLikeInterface $newBody    Closure, or a user method/function whose body is adopted
     * @param string|null                    $preserveAs Name to publish the previous body under
     *
     * @throws RedefinitionException When the donor or the alias cannot be accepted (nothing was modified)
     * @throws SharedMemoryException When the entry cannot be copied out of shared memory
     */
    public function redefine(\Closure|FunctionLikeInterface $newBody, ?string $preserveAs = null): void
    {
        if ($this->isInternal()) {
            if (!$newBody instanceof \Closure) {
                throw RedefinitionException::internalEntryRequiresClosure($this->getName());
            }
            if ($preserveAs !== null) {
                throw RedefinitionException::aliasUnsupportedForInternalEntry($this->getName());
            }
            $this->ensureCompatibleSignature(new \ReflectionFunction($newBody));

            // For internal function we can simply adjust a handler
            /** @var zend_internal_function $internalEntry Internal entries hold the internal view */
            $internalEntry          = $this->pointer;
            $internalEntry->handler = function (CData $executeData, CData $returnValue) use ($newBody): void {
                $rawValue   = ReflectionValue::fromValueEntry($returnValue);
                $stackTrace = debug_backtrace(0, 2);
                $result     = $newBody(...$stackTrace[1]['args']);
                $rawValue->setNativeValue($result);
            };

            return;
        }

        if ($newBody instanceof \Closure) {
            $this->ensureCompatibleSignature(new \ReflectionFunction($newBody));
            $selfExecutionState = Core::$executor->getExecutionState();
            $newCodeEntry       = $selfExecutionState->getArgument(0)->getRawObject();
            $closureEntry       = ClosureEntry::fromCData(Core::cast('zend_closure *', $newCodeEntry));
            $donorFunction      = ReflectionFunction::fromCData(Core::addr($closureEntry->getRawFunction()));
        } else {
            $this->ensureAcceptableDonor($newBody);
            $donorFunction = $newBody;
        }

        if ($preserveAs !== null) {
            // Checked before the copy-out: a subclass linked against the shared entry could
            // never see an alias published into the per-process copy
            MethodPublisher::assertAliasPublishable($this);
        }
        $isSharedMemoryEntry = $this->copyEntryOutOfSharedMemory();
        $entryFunction       = ReflectionFunction::fromCData($this->pointer);

        $unpublishAlias = null;
        if ($preserveAs !== null) {
            // The alias takes its own share of the previous body BEFORE the swap releases
            // the entry's share, which is what keeps the body alive through the swap
            [, $unpublishAlias] = $entryFunction->getCommonPointer()->scope !== null
                ? MethodPublisher::publishMethodAlias($entryFunction, $preserveAs)
                : MethodPublisher::publishFunctionAlias($entryFunction, $preserveAs);
        }
        /** @var list<PendingBodySwap> $pendingSwaps */
        $pendingSwaps = [];
        try {
            $pendingSwaps[] = FunctionBodySwap::swapUserFunctionBody(
                $entryFunction,
                $donorFunction,
                preserveDeclaration: true,
                // A donor closure owns (and destroys) its static-variables table, so the
                // entry needs a duplicate of the defaults; a method donor's table lives as
                // long as the body it belongs to, which the entry now holds a reference on
                // (a method donor whose body is a closure's is detected and duplicated too)
                duplicateStatics: $newBody instanceof \Closure,
                // A shared-memory body is immortal by definition and must not be freed
                destroyPrevious: !$isSharedMemoryEntry,
                // Subclass method tables may share this very structure - every such
                // bucket releases one body reference when the engine destroys it
                publishedShares: FunctionBodySwap::countPublishedShares($entryFunction),
            );
            // A subclass implementing an interface that declares the method holds an engine
            // CLONE of the entry instead of the entry pointer (FunctionBodySwap::cloneHolders()):
            // the clone dispatches for that subclass, so it follows the redefinition in place
            foreach (FunctionBodySwap::cloneHolders($entryFunction) as [, $clone]) {
                if ($clone->isImmutable()) {
                    // A clone inside a shared-memory subclass is never written: that subclass
                    // keeps the previous body, like every subclass linked against a shared entry
                    continue;
                }
                $pendingSwaps[] = FunctionBodySwap::swapUserFunctionBody(
                    $clone,
                    $donorFunction,
                    preserveDeclaration: true,
                    duplicateStatics: $newBody instanceof \Closure,
                    // The clone was copied from the entry: a shared-memory body (no refcount) is immortal
                    destroyPrevious: $clone->getOpArrayPointer()->refcount !== null,
                    publishedShares: FunctionBodySwap::countPublishedShares($clone),
                );
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($pendingSwaps) as $pendingSwap) {
                $pendingSwap->rollback();
            }
            if ($unpublishAlias !== null) {
                $unpublishAlias();
            }
            throw $error;
        }
        foreach ($pendingSwaps as $pendingSwap) {
            $pendingSwap->commit();
        }
    }

    /**
     * Marks or unmarks this entry as redeclaring a method that is private in an ancestor
     * (ZEND_ACC_CHANGED): the engine then resolves the ancestor's own private method for
     * calls made from the ancestor's scope on instances of this class
     *
     * @internal
     */
    public function setChangedFlag(bool $isChanged): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_CHANGED, $isChanged);
    }

    /**
     * Marks or unmarks this entry as a constructor (ZEND_ACC_CTOR)
     *
     * @internal
     */
    public function setConstructorFlag(bool $isConstructor): void
    {
        $this->setFunctionFlag(Core::ZEND_ACC_CTOR, $isConstructor);
    }

    /**
     * Checks that a user method/function can serve as the donor body of this entry
     *
     * @throws RedefinitionException
     */
    private function ensureAcceptableDonor(FunctionLikeInterface $donor): void
    {
        $entryName = $this->getName();
        $donorName = MethodPublisher::entryName($donor);
        if (!$donor->isUserDefined()) {
            throw RedefinitionException::donorIsInternal($donorName);
        }
        if ($donor->getAddress() === $this->getAddress()) {
            throw RedefinitionException::donorIsEntry($entryName);
        }
        $donorFlags = $donor->getCommonPointer()->fn_flags;
        $entryFlags = $this->getCommonPointer()->fn_flags;
        assert(is_int($donorFlags) && is_int($entryFlags));
        if (($donorFlags & Core::ZEND_ACC_ABSTRACT) !== 0) {
            throw RedefinitionException::donorIsAbstract($donorName);
        }
        if (($donorFlags & Core::ZEND_ACC_STATIC) !== ($entryFlags & Core::ZEND_ACC_STATIC)) {
            throw RedefinitionException::staticModifierMismatch($entryName, $donorName);
        }
        if (!$donor instanceof \ReflectionFunctionAbstract) {
            throw RedefinitionException::donorNotIntrospectable($donorName, new \LogicException('not a reflection object'));
        }
        // Pointer-level wrappers (fromRawEntry) carry no native reflection state: the native
        // `name` property is only initialized by the native constructor, and the signature
        // readers below would fail with an engine error on such a wrapper
        if (!(new \ReflectionProperty(\ReflectionFunctionAbstract::class, 'name'))->isInitialized($donor)) {
            throw RedefinitionException::donorNotIntrospectable(
                $donorName,
                new \LogicException('the native reflection state of the donor is not initialized'),
            );
        }
        $this->ensureCompatibleSignature($donor);
    }

    /**
     * Copies an opcache-shared (ZEND_ACC_IMMUTABLE) entry out of shared memory and
     * rebinds this reflection to the writable entry now published in its table
     *
     * A no-op for entries that are not opcache-shared. For a global function the
     * per-process function-table bucket is repointed at a writable container; for a
     * method the whole declaring class is copied out (a method table lives inside the
     * class entry) and the reflection rebinds to the method entry of the writable
     * copy. In both cases the SHM original stays untouched - never written, never
     * freed. See docs/hot-swap.md for the support matrix and the copy-out caveats.
     *
     * @return bool True when the entry was opcache-shared and is now copied out
     *
     * @throws SharedMemoryException When the entry cannot be copied out of shared memory
     *
     * @internal called by redefine(); shared with the cache-image bridge (CacheImageSync)
     */
    public function copyEntryOutOfSharedMemory(): bool
    {
        if (!$this->isImmutable()) {
            return false;
        }
        // Copy the entry out of SHM: the per-process bucket that publishes it is
        // repointed at a writable container, the SHM original stays untouched
        // (never written, never freed). A method entry lives inside the shared
        // class entry, so the whole class is copied out and the swap targets the
        // method entry of the writable copy.
        $entryScope    = $this->getCommonPointer()->scope;
        $this->pointer = $entryScope !== null
            ? $this->copyMethodOutOfSharedMemory($entryScope)
            : $this->copyOutOfSharedMemory();

        return true;
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
     * @return zend_function Writable zend_function pointer now published in the table
     */
    private function copyOutOfSharedMemory(): object
    {
        $lowerKey    = strtolower($this->getName());
        $bucketValue = Core::$executor->functionTable->find($lowerKey);
        if ($bucketValue === null) {
            throw SharedMemoryException::functionNotPublished($lowerKey);
        }

        $writableEntry = Core::trackedNew(zend_function::class, true);
        Core::memcpy($writableEntry, $this->pointer, Core::sizeof($writableEntry));

        // The writable copy is not opcache-shared anymore; everything it points at
        // still is, so the body must be replaced (not freed) by the caller. The copy
        // is a user function, so its common struct carries the fn_flags word.
        $writablePointer = Core::cast(zend_function::class, Core::addr($writableEntry));
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
     * @param CData|zend_class_entry $entryScope Shared-memory zend_class_entry this method is declared in
     *
     * @return zend_function|zend_internal_function Writable zend_function pointer published in the copied method table
     */
    private function copyMethodOutOfSharedMemory(object $entryScope): object
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
        $opArray      = $this->getOpArrayPointer();
        $opCodes      = [];
        $opcodeIndex  = 0;
        $totalOpcodes = $opArray->last;
        assert($opArray->opcodes !== null);
        $opcodes = new StructArray($opArray->opcodes, $totalOpcodes);
        while ($opcodeIndex < $totalOpcodes) {
            $opCodes[] = new OpLine(Core::addr($opcodes[$opcodeIndex++]));
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
        return $this->getOpArrayPointer()->last_literal;
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
        $opArray     = $this->getOpArrayPointer();
        $lastLiteral = $opArray->last_literal;
        if ($index > $lastLiteral) {
            throw new \OutOfBoundsException("Literal index {$index} is out of bounds, last is {$lastLiteral}");
        }
        assert($opArray->literals !== null);
        $literals = new StructArray($opArray->literals, $lastLiteral + 1);

        return ReflectionValue::fromValueEntry($literals[$index]);
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
            $opArray       = $this->getOpArrayPointer();
            $literalIndex  = 0;
            $totalLiterals = $opArray->last_literal;
            assert($opArray->literals !== null);
            $literals = new StructArray($opArray->literals, $totalLiterals);
            while ($literalIndex < $totalLiterals) {
                yield ReflectionValue::fromValueEntry($literals[$literalIndex++]);
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
            Core::addressOf($argInfoTable) + $index * Core::sizeOfType($entryType),
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
     * is materialized - as a plain pointer for regular functions, or through the map-ptr
     * offset form (low bit set) opcache stores into shared immutable functions, whose
     * slot in the per-process map-ptr area is dereferenced here. The default table is
     * returned while no live table exists yet (before the first call binds the statics).
     * Returns null when the function declares no static variables at all.
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
        $opArray   = $this->getOpArrayPointer();
        $liveTable = $opArray->static_variables_ptr__ptr;
        if ($liveTable !== null) {
            $rawFieldValue = Core::addressOf($liveTable);
            if (($rawFieldValue & 1) === 0) {
                return HashTable::fromCData($liveTable);
            }
            // Immutable (opcache shared-memory) op_array: the field holds the offset form
            // of ZEND_MAP_PTR - an odd byte offset into the per-process map-ptr area. The
            // slot stays NULL until the first ZEND_BIND_STATIC materializes the live table
            $materialized = $this->dereferenceMapPointerSlot($rawFieldValue);
            if ($materialized !== null) {
                return HashTable::fromCData($materialized);
            }
        }
        $defaultTable = $opArray->static_variables;
        if ($defaultTable === null) {
            return null;
        }

        return HashTable::fromCData($defaultTable);
    }

    /**
     * Dereferences the offset form of a ZEND_MAP_PTR field into the per-process map-ptr area
     *
     * The slot address is CG(map_ptr_base) + offset: the engine pre-biases the base, so no
     * -1 adjustment is applied (see zend_map_ptr.h:ZEND_MAP_PTR_OFFSET2PTR and the identical
     * arithmetic in StringEntry::setCachedClassEntry()). The bound check mirrors
     * ZSTR_VALID_CE_CACHE: a slot beyond CG(map_ptr_last) was never handed out.
     *
     * @param int $slotOffset Raw field value with the low bit set (ZEND_MAP_PTR_IS_OFFSET)
     *
     * @return CData|null HashTable* stored in the slot, or null while the slot is not yet
     *                    materialized (or the map-ptr area cannot vouch for it)
     */
    /**
     * Returns the materialized live static-variables table of this user function as the raw
     * `HashTable *`, or null while no call has bound the statics yet (both slot forms, see
     * getStaticVariables())
     *
     * @return HashTableStruct|null
     *
     * @internal used by FunctionBodySwap, which moves the table to the alias preserving the body
     */
    public function getLiveStaticsPointer(): ?object
    {
        $liveTable = $this->getOpArrayPointer()->static_variables_ptr__ptr;
        if ($liveTable === null) {
            return null;
        }
        $rawFieldValue = Core::addressOf($liveTable);
        if (($rawFieldValue & 1) === 0) {
            return $liveTable;
        }
        $materialized = $this->dereferenceMapPointerSlot($rawFieldValue);
        if ($materialized === null) {
            return null;
        }

        // The map-ptr slot holds the materialized `HashTable *`: same pointer, stub-typed view
        return Core::cast(HashTableStruct::class, $materialized);
    }

    private function dereferenceMapPointerSlot(int $slotOffset): ?CData
    {
        $mapPointerBase = Core::$compiler->getMapPointerBaseAddress();
        if ($mapPointerBase === 0) {
            return null;
        }
        $slotIndex = intdiv($slotOffset - 1, Core::sizeOfType('void *'));
        if ($slotIndex >= Core::$compiler->getMapPointerLast()) {
            return null;
        }
        $slot         = Core::pointerAtAddress('HashTable **', $mapPointerBase + $slotOffset);
        $materialized = $slot[0];
        assert($materialized === null || $materialized instanceof CData);

        return $materialized;
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
        $elements      = [];
        $opArray       = $this->getOpArrayPointer();
        $totalElements = $opArray->last_try_catch;
        if ($totalElements > 0) {
            assert($opArray->try_catch_array !== null);
            $elementTable = new StructArray($opArray->try_catch_array, $totalElements);
            for ($index = 0; $index < $totalElements; $index++) {
                $rawElement = $elementTable[$index];
                $elements[] = new TryCatchElement(
                    $rawElement->try_op,
                    $rawElement->catch_op,
                    $rawElement->finally_op,
                    $rawElement->finally_end,
                );
            }
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
        $liveRanges  = [];
        $opArray     = $this->getOpArrayPointer();
        $totalRanges = $opArray->last_live_range;
        if ($totalRanges > 0) {
            assert($opArray->live_range !== null);
            $rangeTable = new StructArray($opArray->live_range, $totalRanges);
            for ($index = 0; $index < $totalRanges; $index++) {
                $rawRange     = $rangeTable[$index];
                $liveRanges[] = new LiveRange($rawRange->var, $rawRange->start, $rawRange->end);
            }
        }

        return $liveRanges;
    }

    /**
     * Returns the hash key for function or method
     */
    abstract protected function getHash(): string;

    /**
     * Checks that the donor signature matches this entry's (parameters, by-reference passing,
     * variadics, types and the return type)
     *
     * Types are compared as the engine resolved them at compile time. That is deliberate for
     * `self`/`parent` types: the compiler resolves them to the declaring class of the DONOR,
     * and the adopted arg_info keeps enforcing that class at run time - a donor must spell
     * the entry's class explicitly where the entry says `self` (only `static` is late-bound).
     *
     * @throws RedefinitionException if the donor signature is not compatible with this entry
     */
    private function ensureCompatibleSignature(\ReflectionFunctionAbstract $donor): void
    {
        $entrySignature = self::renderSignature($this);
        $donorSignature = self::renderSignature($donor);

        if ($entrySignature !== $donorSignature) {
            throw RedefinitionException::signatureMismatch($entrySignature, $donorSignature);
        }
    }

    /**
     * Renders a comparable signature string of a function
     *
     * The `self` and `parent` keywords are rendered as the classes they name in the function's
     * own scope (its declaring class, or the bound scope of a closure): PHP 8.5 reflection
     * resolves them itself, PHP 8.4 reports the keywords verbatim, and a donor compiled in
     * another class must be compared by what its types actually accept. `static` stays as is,
     * it binds to the called class on both sides.
     */
    private static function renderSignature(\ReflectionFunctionAbstract $function): string
    {
        $scope = $function instanceof \ReflectionMethod
            ? $function->getDeclaringClass()
            : ($function instanceof \ReflectionFunction ? $function->getClosureScopeClass() : null);

        $signature = 'function ';
        if ($function->returnsReference()) {
            $signature .= '&';
        }
        $signature .= '(';
        $parameters = [];
        foreach ($function->getParameters() as $reflectionParameter) {
            $parameter = '';
            $type      = $reflectionParameter->getType();
            if ($type !== null) {
                $parameter .= self::renderType($type, $scope) . ' ';
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
        $returnType = $function->getReturnType();
        if ($returnType !== null) {
            $signature .= ': ' . self::renderType($returnType, $scope);
        }

        return $signature;
    }

    /**
     * Renders a type with the class-scope keywords resolved against $scope (see renderSignature())
     *
     * @param \ReflectionClass<object>|null $scope
     */
    private static function renderType(\ReflectionType $type, ?\ReflectionClass $scope): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name      = $type->getName();
            $lowerName = strtolower($name);
            if ($scope !== null && $lowerName === 'self') {
                $name = $scope->getName();
            } elseif ($scope !== null && $lowerName === 'parent') {
                $parentClass = $scope->getParentClass();
                $name        = $parentClass !== false ? $parentClass->getName() : $name;
            }
            $isNullable = $type->allowsNull() && $lowerName !== 'null' && $lowerName !== 'mixed';

            return ($isNullable ? '?' : '') . $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            $members = [];
            foreach ($type->getTypes() as $member) {
                $rendered  = self::renderType($member, $scope);
                $members[] = $member instanceof \ReflectionIntersectionType ? '(' . $rendered . ')' : $rendered;
            }

            return join('|', $members);
        }
        if ($type instanceof \ReflectionIntersectionType) {
            $members = [];
            foreach ($type->getTypes() as $member) {
                $members[] = self::renderType($member, $scope);
            }

            return join('&', $members);
        }

        return (string) $type;
    }

    /**
     * Returns a pointer to the common structure (to work natively with zend_function and zend_internal_function)
     *
     * Both returned views carry the identical common field prefix (function_name,
     * scope, prototype, fn_flags, arg_info, ...), so consumers read the shared
     * fields through the union type without caring which entry kind they hold.
     *
     * @return zend_function_common|zend_internal_function
     *
     * @internal shared with the body-swap machinery (FunctionBodySwap)
     */
    public function getCommonPointer(): object
    {
        // For zend_internal_function we have same fields directly in current structure.
        // The check goes through the low-level structure (not native isInternal()) so it
        // also works on wrappers whose native reflection state is not initialized yet
        if (!$this->isUserDefined()) {
            /** @var zend_internal_function $pointer The stored handle IS the internal view here */
            $pointer = $this->pointer;
        } else {
            // zend_function uses "common" struct to store all important fields
            /** @var zend_function $entry User-defined entries always hold the union view */
            $entry   = $this->pointer;
            $pointer = $entry->common;
        }

        return $pointer;
    }

    /**
     * Returns the typed view of the op_array this user function executes
     *
     * Only user-defined functions carry an op_array.
     *
     * @return zend_op_array
     *
     * @internal shared with the body-swap machinery (FunctionBodySwap)
     */
    public function getOpArrayPointer(): object
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('op_array is available only for user-defined functions');
        }
        /** @var zend_function $entry User-defined entries always hold the union view */
        $entry = $this->pointer;

        return $entry->op_array;
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
    /**
     * @return zend_function|zend_internal_function
     */
    public function getEntryPointer(): object
    {
        return $this->pointer;
    }
}
