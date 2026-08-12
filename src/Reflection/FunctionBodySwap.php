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
 * The single implementation of an in-place user function body swap
 *
 * Both FunctionLikeTrait::redefine() and the ClassDelta hot-swap apply path replace
 * the body of a published zend_function while keeping the entry pointer (and thus
 * every warmed-up inline cache, child-class method bucket and reflection wrapper
 * pointing at it) valid. All the lifetime rules live here, in one place:
 *
 *  - The entry takes one owned reference on the shared op_array body PER published
 *    bucket that points at the entry structure (the entry's own bucket plus every
 *    inherited-method bucket of a subclass): each bucket's destructor decrements the
 *    body refcount once, so the accounting must match or the body is freed early.
 *  - The entry gets a fresh HEAP_RT_CACHE run-time cache: the donor's cache is
 *    scope-dependent and owned by the donor; the engine frees a heap cache together
 *    with the entry (destroy_op_array), and every subsequent swap releases the
 *    previous one, so repeated swaps stay memory-flat.
 *  - The entry never shares the donor's LIVE static-variables table: its ZEND_MAP_PTR
 *    slot is dropped so the table materializes lazily on the first ZEND_BIND_STATIC,
 *    exactly like a plain compiled function. The defaults table behind it stays
 *    shared and is guarded by the body refcount alone - no donor kind owns it, see
 *    unshareStaticVariables() for the PHP 8.5 ownership rules.
 *  - The previous body is destroyed with engine semantics (destroy_op_array) when
 *    the swap is committed - unless it lives in opcache shared memory, which is
 *    never freed. The entry keeps its owned reference on the function name;
 *    everything exclusively owned by the old body (opcodes, literals, vars,
 *    arg_info, static variables, heap run-time cache) is released, and bodies still
 *    shared with someone else (a template op_array, a fake closure) survive through
 *    their own refcount.
 *
 * A swap is returned as a PendingBodySwap: redefine() commits immediately, while
 * ClassDelta keeps the handles staged so a failed apply can roll every entry back
 * to its previous body without any half-swapped state becoming observable.
 *
 * Raw zend_function pointers are wrapped in pointer-level ReflectionFunction views
 * (fromCData) and read through the shaped accessors of FunctionLikeTrait
 * (getCommonPointer()/getOpArrayPointer()), so all struct typing lives with the
 * owning reflection class (see AGENTS.md "Engine structs are typed by shape").
 */
final class FunctionBodySwap
{
    /**
     * Function flags that describe the body of the function (as opposed to its declaration):
     * they must always travel together with the op_array they were compiled for.
     *
     * ZEND_ACC_HEAP_RT_CACHE and the run_time_cache/T fields live in zend_function.common
     * since PHP 8.2, so a whole-common copy from the previous entry would graft a run-time
     * cache and a temporaries count sized for the OLD opcodes onto the new body - the VM
     * then reads cache slots out of bounds and crashes.
     */
    public const BODY_LEVEL_FUNCTION_FLAGS = Core::ZEND_ACC_HEAP_RT_CACHE
        | Core::ZEND_ACC_GENERATOR
        | Core::ZEND_ACC_VARIADIC
        | Core::ZEND_ACC_RETURN_REFERENCE
        | Core::ZEND_ACC_HAS_RETURN_TYPE
        | Core::ZEND_ACC_HAS_TYPE_HINTS
        | Core::ZEND_ACC_STRICT_TYPES
        | Core::ZEND_ACC_IMMUTABLE;

    /**
     * This is an utility class, no instances needed
     */
    private function __construct() {}

    /**
     * Swaps the body of a published user function entry with the donor's body, in place
     *
     * The returned handle MUST be either committed (the previous body is destroyed with
     * engine semantics, or kept allocated for shared-memory bodies) or rolled back (the
     * previous struct is restored byte-exact and the resources taken from the donor are
     * returned). Nothing is destroyed until commit, so a batch of swaps can be staged
     * and reverted atomically.
     *
     * @param FunctionLikeInterface $entryFunction Published entry (user function/method) to swap
     * @param FunctionLikeInterface $donorFunction Donor whose op_array becomes the new body
     * @param bool  $preserveDeclaration True keeps the entry's declaration identity (prototype and
     *                                   declaration-level flags - the redefine() contract); false
     *                                   adopts the donor's declaration (the ClassDelta contract,
     *                                   where the new source is authoritative). The entry always
     *                                   keeps its own function name and class scope.
     * @param bool  $destroyPrevious     False when the previous body must stay allocated on commit
     *                                   (opcache shared memory is never freed)
     * @param int   $publishedShares     Number of table buckets pointing at this entry structure,
     *                                   see countPublishedShares()
     *
     * @internal
     */
    public static function swapUserFunctionBody(
        FunctionLikeInterface $entryFunction,
        FunctionLikeInterface $donorFunction,
        bool $preserveDeclaration,
        bool $destroyPrevious = true,
        int $publishedShares = 1,
    ): PendingBodySwap {
        assert($publishedShares >= 1);
        $entry        = $entryFunction->getEntryPointer();
        $donor        = $donorFunction->getEntryPointer();
        $entryAddress = $entryFunction->getAddress();

        // Snapshot the previous body byte-exact: rollback restores it wholesale, commit
        // detaches the shared identity fields and destroys the rest
        $previousBody = Core::new('zend_function');
        Core::memcpy($previousBody, $entry, Core::sizeof($previousBody));

        // Remember the declaration identity of the entry: it survives the body
        // replacement, while everything executor-related (opcodes, literals, run-time
        // cache, temporaries count) comes from the donor body
        $entryCommon   = $entryFunction->getCommonPointer();
        $previousName  = $entryCommon->function_name;
        $previousScope = $entryCommon->scope;
        $previousProto = $entryCommon->prototype;
        $previousFlags = $entryCommon->fn_flags;
        $donorFlags    = $donorFunction->getCommonPointer()->fn_flags;

        // Replace the whole function with the donor-backed one (the donor structure
        // itself stays untouched - it keeps sole ownership of its own fields)
        Core::memcpy($entry, $donor, Core::sizeof(Core::type('zend_function')));

        // Restore the entry identity: the single owned reference on the previous name
        // stays with this entry, and the scope always survives (the donor was compiled
        // in a foreign scope)
        $entryCommon->function_name = $previousName;
        $entryCommon->scope         = $previousScope;
        if ($preserveDeclaration) {
            // Declaration-level flags (visibility, static, final, closure bit) are kept
            // while body-level flags follow the new op_array
            $entryCommon->prototype = $previousProto;
            $entryCommon->fn_flags  = ($previousFlags & ~self::BODY_LEVEL_FUNCTION_FLAGS)
                | ($donorFlags & self::BODY_LEVEL_FUNCTION_FLAGS);
        }

        // Every published bucket pointing at this structure decrements the body
        // refcount once when it is destroyed - take exactly that many references
        for ($share = 0; $share < $publishedShares; $share++) {
            self::addBodyReference($entryFunction);
        }
        self::installFreshRunTimeCache($entryFunction);
        self::unshareStaticVariables($entryFunction);

        return new PendingBodySwap(
            $entryFunction,
            $previousBody,
            $entryAddress,
            $publishedShares,
            $destroyPrevious,
        );
    }

    /**
     * Counts the published table buckets that point at the given method entry structure
     *
     * Inheritance publishes the parent's zend_function pointer into every subclass
     * method table (zend_duplicate_function shares the structure and takes one body
     * reference per bucket), so an in-place body swap must mirror that accounting.
     * Class aliases resolve to the same class entry and are deduplicated; a structure
     * published in no class table (a plain function entry) counts as one share.
     *
     * @internal
     */
    public static function countPublishedShares(FunctionLikeInterface $entryFunction): int
    {
        $entryCommon = $entryFunction->getCommonPointer();
        if ($entryCommon->scope === null) {
            return 1;
        }
        $namePointer = $entryCommon->function_name;
        assert($namePointer instanceof CData);
        $lowerName    = strtolower(StringEntry::fromCData($namePointer)->getStringValue());
        $entryAddress = $entryFunction->getAddress();

        $shares      = 0;
        $seenClasses = [];
        foreach (Core::$executor->classTable as $classValue) {
            try {
                $rawClass = $classValue->getRawClass();
            } catch (\UnexpectedValueException $e) {
                // Class alias buckets (IS_ALIAS_PTR) resolve to an already-counted entry
                continue;
            }
            $classAddress = Core::addressOf($rawClass);
            if (isset($seenClasses[$classAddress])) {
                continue;
            }
            $seenClasses[$classAddress] = true;

            $reflectionClass = ReflectionClass::fromCData($rawClass);
            if (!$reflectionClass->isUserDefined()) {
                continue;
            }
            $bucketValue = $reflectionClass->getMethodTable()->find($lowerName);
            if ($bucketValue !== null && Core::addressOf($bucketValue->getRawFunction()) === $entryAddress) {
                $shares++;
            }
        }

        return max($shares, 1);
    }

    /**
     * Prepares a standalone writable zend_function container from a donor method for
     * publishing into a method table (the ClassDelta "new method" operation)
     *
     * The container adopts the donor body with its own owned resources: one body
     * reference, one owned reference on the function name (every published bucket
     * releases a name reference when the engine destroys it), a fresh run-time cache
     * and lazily-materialized statics. The donor may be destroyed afterwards.
     *
     * @param CData              $container zend_function container to fill (writable, zeroed or reused)
     * @param FunctionLikeInterface $donor  Donor method whose body is adopted
     * @param ReflectionClass    $newScope  Class the published method will belong to
     *
     * @internal
     */
    public static function adoptFunctionForPublishing(object $container, FunctionLikeInterface $donor, ReflectionClass $newScope): void
    {
        Core::memcpy($container, $donor->getEntryPointer(), Core::sizeOfType(zend_function::class));

        $containerFunction      = ReflectionFunction::fromCData(Core::cast(zend_function::class, Core::addr($container)));
        $containerCommon        = $containerFunction->getCommonPointer();
        $containerCommon->scope = Core::cast(zend_class_entry::class, $newScope->getRawValue());

        // The published bucket owns one reference on the name and one body share
        $namePointer = $containerCommon->function_name;
        assert($namePointer !== null);
        StringEntry::fromCData($namePointer)->copy();

        self::addBodyReference($containerFunction);
        self::installFreshRunTimeCache($containerFunction);
        self::unshareStaticVariables($containerFunction);
    }

    /**
     * Takes the ownership a published method bucket holds on an existing entry: one
     * name reference and one body share (mirrors zend_duplicate_function)
     *
     * @internal used by ClassDelta when republishing inherited entries
     */
    public static function acquireBucketOwnership(FunctionLikeInterface $entryFunction): void
    {
        $namePointer = $entryFunction->getCommonPointer()->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->copy();

        self::addBodyReference($entryFunction);
    }

    /**
     * Returns the ownership taken by acquireBucketOwnership (rollback path only:
     * other holders provably keep the name and the body alive)
     *
     * @internal used by ClassDelta when rolling an inherited republish back
     */
    public static function releaseBucketOwnership(FunctionLikeInterface $entryFunction): void
    {
        $refCountPointer = $entryFunction->getOpArrayPointer()->refcount;
        if ($refCountPointer !== null) {
            $referenceCount = self::counterValue($refCountPointer);
            assert($referenceCount > 1);
            $refCountPointer[0] = $referenceCount - 1;
        }

        $namePointer = $entryFunction->getCommonPointer()->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->releaseReference();
    }

    /**
     * Takes one owned reference on the (shared) op_array body of the entry
     *
     * Immutable (opcache SHM) bodies carry no refcount at all - they are process-shared
     * and never freed, so there is nothing to account for.
     */
    private static function addBodyReference(FunctionLikeInterface $entryFunction): void
    {
        $refCountPointer = $entryFunction->getOpArrayPointer()->refcount;
        if ($refCountPointer !== null) {
            $refCountPointer[0] = self::counterValue($refCountPointer) + 1;
        }
    }

    /**
     * Drops body references without destroying anything (rollback bookkeeping)
     *
     * Only legal while another holder (the donor) provably keeps the body alive.
     */
    private static function dropBodyReferences(FunctionLikeInterface $entryFunction, int $count): void
    {
        $refCountPointer = $entryFunction->getOpArrayPointer()->refcount;
        if ($refCountPointer !== null) {
            $referenceCount = self::counterValue($refCountPointer);
            assert($referenceCount > $count);
            $refCountPointer[0] = $referenceCount - $count;
        }
    }

    /**
     * Reads an engine counter cell (the uint32_t behind op_array.refcount)
     *
     * A bare counter cell is neither a struct array nor a hashtable, so this offset
     * read cannot be expressed through a shaped view; the numeric narrowing for
     * body-refcount dereferences is centralized here.
     *
     * @param \FFI\CData $counterPointer
     */
    private static function counterValue(object $counterPointer): int
    {
        $counterValue = $counterPointer[0];
        assert(is_int($counterValue));

        return $counterValue;
    }

    /**
     * Gives the entry its own zero-filled run-time cache for the new body
     *
     * The donor's cache is scope-dependent (inline caches for self::/parent:: and
     * property slots assume the donor's scope) and owned by the donor, so it must
     * never be shared. A heap cache (ZEND_ACC_HEAP_RT_CACHE) is released by
     * destroy_op_array together with the entry - and by the next swap's
     * previous-body destruction, which keeps repeated swaps memory-flat.
     */
    private static function installFreshRunTimeCache(FunctionLikeInterface $entryFunction): void
    {
        $opArray     = $entryFunction->getOpArrayPointer();
        $entryCommon = $entryFunction->getCommonPointer();
        $cacheSize   = $opArray->cache_size;
        if ($cacheSize > 0) {
            // Request-allocator block handed over to the engine: destroy_op_array
            // releases it through efree, matching the allocation exactly
            $cache                        = Core::new("char[{$cacheSize}]", false);
            $opArray->run_time_cache__ptr = Core::cast('void **', $cache);
            $entryCommon->fn_flags |= Core::ZEND_ACC_HEAP_RT_CACHE;
        } else {
            $opArray->run_time_cache__ptr = null;
            $entryCommon->fn_flags &= (~Core::ZEND_ACC_HEAP_RT_CACHE);
        }
    }

    /**
     * Detaches the entry's live static-variables table from the donor
     *
     * Only the LIVE table is unshared: dropping the ZEND_MAP_PTR slot makes it
     * materialize lazily on the first ZEND_BIND_STATIC, from the defaults, exactly
     * like a plain compiled function.
     *
     * The defaults table itself stays shared with the donor's other body holders. No
     * donor kind owns it: since PHP 8.5 zend_create_closure_ex() duplicates the
     * prototype defaults into the closure's ZEND_MAP_PTR slot only and leaves
     * op_array.static_variables aliasing the prototype's table, whose single destroy
     * is tied to the body refcount that destroy_op_array decrements. The entry holds
     * one such reference per published bucket, so the last holder - whichever it turns
     * out to be - frees the table exactly once.
     *
     * Duplicating it here instead would be a leak: the swap would replace the only
     * field through which this entry's body reference could still reach the original,
     * while PHP 8.5 also dropped the unconditional release destroy_op_array used to
     * perform for the closure prototypes of a dying op_array.
     */
    private static function unshareStaticVariables(FunctionLikeInterface $entryFunction): void
    {
        $opArray                            = $entryFunction->getOpArrayPointer();
        $opArray->static_variables_ptr__ptr = null;

        if ($opArray->static_variables === null) {
            return;
        }
        // The engine's shutdown walk destroys per-method live static tables only
        // for classes flagged as having statics - a swapped-in body with statics
        // must set the flag or its materialized table leaks at request end
        $entryScope = $entryFunction->getCommonPointer()->scope;
        if ($entryScope !== null) {
            ReflectionClass::fromCData($entryScope)
                ->markHasStaticInMethods();
        }
    }

    /**
     * Destroys the previous body of a swapped entry with engine semantics
     *
     * The snapshot shares the function name with the entry (which keeps its owned
     * reference), so the name pointer is detached before destroy_op_array runs. The
     * materialized static-variables table and a z-engine-minted defaults duplicate
     * are exclusively owned by this entry and are released explicitly; everything
     * else follows the body refcount - shared bodies (a live template op_array, a
     * fake closure over the old body) keep their arrays until the last holder dies.
     *
     * @param CData $previousBody   zend_function snapshot taken by the swap
     * @param int   $releasedShares Total bucket shares the entry held on the previous body
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function destroyPreviousBody(object $previousBody, int $entryAddress, int $releasedShares): void
    {
        $previousFunction = ReflectionFunction::fromCData(Core::cast('zend_function *', Core::addr($previousBody)));
        $previousOpArray  = $previousFunction->getOpArrayPointer();
        $refCountPointer  = $previousOpArray->refcount;
        if ($refCountPointer === null) {
            // No refcount means an opcache-shared body: never destroyed (and the swap
            // paths never destroy SHM bodies in the first place)
            return;
        }

        if (self::hasLiveFrame($entryAddress)) {
            // A frame of this very entry is still executing the previous opcodes (the
            // function redefined itself, directly or through a callee): freeing them
            // would pull memory out from under the running VM frame. The previous body
            // stays allocated instead - bounded to one body per such in-flight
            // redefinition, see docs/hot-swap.md.
            return;
        }

        // All bucket shares move to the new body: drop every previous share except the
        // one that destroy_op_array below releases itself
        $referenceCount = self::counterValue($refCountPointer);
        if ($releasedShares > 1) {
            assert($referenceCount >= $releasedShares);
            $referenceCount     = $referenceCount - ($releasedShares - 1);
            $refCountPointer[0] = $referenceCount;
        }
        $isLastHolder = $referenceCount <= 1;

        $rawOpArray = Core::cast('zend_op_array *', Core::addr($previousBody));
        if ($isLastHolder) {
            // Frees the materialized live static-variables table (if any) and clears
            // the map slot; with other holders alive the table must survive - fake
            // closures over the old body still reference it through their map slots
            Core::call('zend_destroy_static_vars', $rawOpArray);
        }

        // The defaults table needs no handling of its own: it is shared with the other
        // holders of this body and destroy_op_array below frees it exactly when this
        // entry drops the final reference

        // The entry keeps the single owned reference on the name - the snapshot must
        // not release it
        $previousOpArray->function_name = null;
        Core::call('destroy_op_array', $rawOpArray);
    }

    /**
     * Checks if any frame of the current VM call stack executes the given entry
     *
     * Frame functions are compared by their pointer identity
     * (ReflectionFunction::getAddress()). Suspended frames that are not part of the
     * current stack (generators, fibers) cannot be discovered this way - see the
     * interaction notes in docs/hot-swap.md.
     */
    private static function hasLiveFrame(int $entryAddress): bool
    {
        $frame = Core::$executor->getExecutionState();
        while (true) {
            $frameFunction = $frame->getFunctionEntry();
            if ($frameFunction !== null && $frameFunction->getAddress() === $entryAddress) {
                return true;
            }
            if (!$frame->hasPrevious()) {
                return false;
            }
            $frame = $frame->getPrevious();
        }
    }

    /**
     * Returns the resources taken by an uncommitted swap and forgets its bookkeeping
     *
     * @internal called by PendingBodySwap::rollback() while the donor is still alive
     */
    public static function releaseSwappedInBody(
        FunctionLikeInterface $entryFunction,
        int $publishedShares,
    ): void {
        // The fresh run-time cache was installed by the swap and is not published anywhere
        $opArray      = $entryFunction->getOpArrayPointer();
        $entryFlags   = $entryFunction->getCommonPointer()->fn_flags;
        $cachePointer = $opArray->run_time_cache__ptr;
        if (($entryFlags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0 && $cachePointer !== null) {
            Core::free($cachePointer);
        }
        // The defaults table was never duplicated, so the rollback owes nothing for it:
        // restoring the previous body puts the entry's own pointer back in place

        self::dropBodyReferences($entryFunction, $publishedShares);
    }
}
