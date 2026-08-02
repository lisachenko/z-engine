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
use ZEngine\Type\HashTable;
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
 *  - Static variables never stay shared with a donor that owns them: a closure
 *    destroys its own static table when it dies, so the entry duplicates the
 *    defaults; the live per-entry table materializes lazily on the first
 *    ZEND_BIND_STATIC, exactly like a plain compiled function.
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
     * Static-variable defaults tables minted by swaps, keyed by entry address
     *
     * A minted table is exclusively owned by its entry, so the next swap of the same
     * entry may release it eagerly even while the body arrays themselves are still
     * shared with a live template op_array (whose own defaults pointer differs).
     *
     * @var array<int, int> Entry address => defaults table address
     */
    private static array $mintedStaticDefaults = [];

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
     * @param CData $entry               zend_function pointer of the published entry (user function)
     * @param CData $donor               zend_function pointer whose op_array becomes the new body
     * @param bool  $preserveDeclaration True keeps the entry's declaration identity (prototype and
     *                                   declaration-level flags - the redefine() contract); false
     *                                   adopts the donor's declaration (the ClassDelta contract,
     *                                   where the new source is authoritative). The entry always
     *                                   keeps its own function name and class scope.
     * @param bool  $duplicateStatics    True when the donor keeps ownership of its static-variables
     *                                   table (closure donors destroy theirs on death); false when
     *                                   the table's lifetime is guarded by the body refcount alone
     *                                   (method donors about to be released via destroy_zend_class)
     * @param bool  $destroyPrevious     False when the previous body must stay allocated on commit
     *                                   (opcache shared memory is never freed)
     * @param int   $publishedShares     Number of table buckets pointing at this entry structure,
     *                                   see countPublishedShares()
     *
     * @internal
     */
    public static function swapUserFunctionBody(
        CData $entry,
        CData $donor,
        bool $preserveDeclaration,
        bool $duplicateStatics,
        bool $destroyPrevious = true,
        int $publishedShares = 1,
    ): PendingBodySwap {
        assert($publishedShares >= 1);
        $entryAddress = Core::addressOf($entry);

        // Snapshot the previous body byte-exact: rollback restores it wholesale, commit
        // detaches the shared identity fields and destroys the rest
        $previousBody = Core::new('zend_op_array');
        Core::memcpy($previousBody, $entry, Core::sizeof($previousBody));

        // Remember the declaration identity of the entry: it survives the body
        // replacement, while everything executor-related (opcodes, literals, run-time
        // cache, temporaries count) comes from the donor body
        $entryCommon = $entry->common;
        assert($entryCommon instanceof CData);
        $previousName  = $entryCommon->function_name;
        $previousScope = $entryCommon->scope;
        $previousProto = $entryCommon->prototype;
        $previousFlags = $entryCommon->fn_flags;
        assert(is_int($previousFlags));
        $donorCommon = $donor->common;
        assert($donorCommon instanceof CData);
        $donorFlags = $donorCommon->fn_flags;
        assert(is_int($donorFlags));

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
            self::addBodyReference($entry);
        }
        self::installFreshRunTimeCache($entry);
        $previousMintedRecord = self::$mintedStaticDefaults[$entryAddress] ?? null;
        $mintedDefaults       = self::unshareStaticVariables($entry, $duplicateStatics, $entryAddress);

        return new PendingBodySwap(
            $entry,
            $previousBody,
            $entryAddress,
            $publishedShares,
            $destroyPrevious,
            $mintedDefaults,
            $previousMintedRecord,
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
     * @param CData $entry zend_function pointer of a published user function/method
     *
     * @internal
     */
    public static function countPublishedShares(CData $entry): int
    {
        $entryCommon = $entry->common;
        assert($entryCommon instanceof CData);
        if ($entryCommon->scope === null) {
            return 1;
        }
        $namePointer = $entryCommon->function_name;
        assert($namePointer instanceof CData);
        $lowerName    = strtolower(StringEntry::fromCData($namePointer)->getStringValue());
        $entryAddress = Core::addressOf($entry);

        $shares      = 0;
        $seenClasses = [];
        foreach (Core::$executor->classTable as $classValue) {
            assert($classValue instanceof ReflectionValue);
            try {
                $classEntry = $classValue->getRawClass();
            } catch (\UnexpectedValueException $e) {
                // Class alias buckets (IS_ALIAS_PTR) resolve to an already-counted entry
                continue;
            }
            $classType = $classEntry->type;
            assert(is_string($classType));
            if (ord($classType) !== Core::ZEND_USER_CLASS) {
                continue;
            }
            $classAddress = Core::addressOf($classEntry);
            if (isset($seenClasses[$classAddress])) {
                continue;
            }
            $seenClasses[$classAddress] = true;

            $functionTable = $classEntry->function_table;
            assert($functionTable instanceof CData);
            $methodTable = new HashTable(Core::addr($functionTable));
            $bucketValue = $methodTable->find($lowerName);
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
     * @param CData $container zend_function container to fill (writable, zeroed or reused)
     * @param CData $donor     zend_function pointer of the donor method
     * @param CData $newScope  zend_class_entry the published method will belong to
     *
     * @internal
     */
    public static function adoptFunctionForPublishing(CData $container, CData $donor, CData $newScope): void
    {
        Core::memcpy($container, $donor, Core::sizeof(Core::type('zend_function')));

        $containerCommon = $container->common;
        assert($containerCommon instanceof CData);
        $containerCommon->scope = $newScope;

        // The published bucket owns one reference on the name and one body share
        $namePointer = $containerCommon->function_name;
        assert($namePointer instanceof CData);
        StringEntry::fromCData($namePointer)->copy();

        $containerPointer = Core::cast('zend_function *', Core::addr($container));
        self::addBodyReference($containerPointer);
        self::installFreshRunTimeCache($containerPointer);
        self::unshareStaticVariables($containerPointer, false, Core::addressOf($containerPointer));
    }

    /**
     * Takes one owned reference on the (shared) op_array body of the entry
     *
     * Immutable (opcache SHM) bodies carry no refcount at all - they are process-shared
     * and never freed, so there is nothing to account for.
     */
    private static function addBodyReference(CData $entry): void
    {
        $opArray = $entry->op_array;
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
     * Drops body references without destroying anything (rollback bookkeeping)
     *
     * Only legal while another holder (the donor) provably keeps the body alive.
     */
    private static function dropBodyReferences(CData $entry, int $count): void
    {
        $opArray = $entry->op_array;
        assert($opArray instanceof CData);
        $refCountPointer = $opArray->refcount;
        if ($refCountPointer !== null) {
            assert($refCountPointer instanceof CData);
            $referenceCount = $refCountPointer[0];
            assert(is_int($referenceCount) && $referenceCount > $count);
            $refCountPointer[0] = $referenceCount - $count;
        }
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
    private static function installFreshRunTimeCache(CData $entry): void
    {
        $opArray = $entry->op_array;
        assert($opArray instanceof CData);
        $cacheSize = $opArray->cache_size;
        assert(is_int($cacheSize));
        $entryCommon = $entry->common;
        assert($entryCommon instanceof CData);
        $entryFlags = $entryCommon->fn_flags;
        assert(is_int($entryFlags));
        if ($cacheSize > 0) {
            // Request-allocator block handed over to the engine: destroy_op_array
            // releases it through efree, matching the allocation exactly
            $cache                        = Core::new("char[{$cacheSize}]", false);
            $opArray->run_time_cache__ptr = Core::cast('void **', $cache);
            $entryCommon->fn_flags        = $entryFlags | Core::ZEND_ACC_HEAP_RT_CACHE;
        } else {
            $opArray->run_time_cache__ptr = null;
            $entryCommon->fn_flags        = $entryFlags & (~Core::ZEND_ACC_HEAP_RT_CACHE);
        }
    }

    /**
     * Detaches the entry's static variables from the donor
     *
     * The live per-entry table always materializes lazily (first ZEND_BIND_STATIC dup
     * of the defaults), exactly like a plain compiled function; the engine destroys it
     * through its regular shutdown walks. When the donor owns the defaults table (a
     * closure destroys its own on death), the entry gets an independent duplicate,
     * recorded so the next swap can release it eagerly.
     *
     * @return bool True when an own defaults duplicate was minted for the entry
     */
    private static function unshareStaticVariables(CData $entry, bool $duplicateStatics, int $entryAddress): bool
    {
        $opArray = $entry->op_array;
        assert($opArray instanceof CData);
        $opArray->static_variables_ptr__ptr = null;

        $defaultsTable = $opArray->static_variables;
        if ($defaultsTable !== null) {
            // The engine's shutdown walk destroys per-method live static tables only
            // for classes flagged as having statics - a swapped-in body with statics
            // must set the flag or its materialized table leaks at request end
            $entryCommon = $entry->common;
            assert($entryCommon instanceof CData);
            $entryScope = $entryCommon->scope;
            if ($entryScope !== null) {
                assert($entryScope instanceof CData);
                $scopeFlags = $entryScope->ce_flags;
                assert(is_int($scopeFlags));
                $entryScope->ce_flags = $scopeFlags | Core::engineConstant('ZEND_HAS_STATIC_IN_METHODS');
            }
        }
        if ($defaultsTable === null || !$duplicateStatics) {
            return false;
        }
        assert($defaultsTable instanceof CData);
        $ownDefaults = Core::call('zend_array_dup', $defaultsTable);
        assert($ownDefaults instanceof CData);
        $opArray->static_variables = $ownDefaults;

        self::$mintedStaticDefaults[$entryAddress] = Core::addressOf($ownDefaults);

        return true;
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
     * @param int $releasedShares Total bucket shares the entry held on the previous body
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function destroyPreviousBody(CData $previousBody, int $entryAddress, int $releasedShares): void
    {
        $refCountPointer = $previousBody->refcount;
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
        assert($refCountPointer instanceof CData);

        // All bucket shares move to the new body: drop every previous share except the
        // one that destroy_op_array below releases itself
        $referenceCount = $refCountPointer[0];
        assert(is_int($referenceCount));
        if ($releasedShares > 1) {
            assert($referenceCount >= $releasedShares);
            $referenceCount     = $referenceCount - ($releasedShares - 1);
            $refCountPointer[0] = $referenceCount;
        }
        $isLastHolder = $referenceCount <= 1;

        if ($isLastHolder) {
            // Frees the materialized live static-variables table (if any) and clears
            // the map slot; with other holders alive the table must survive - fake
            // closures over the old body still reference it through their map slots
            Core::call('zend_destroy_static_vars', Core::addr($previousBody));
        }

        // A minted defaults duplicate belongs exclusively to this entry: release it
        // eagerly when the shared body arrays themselves are not freed below
        $defaultsTable = $previousBody->static_variables;
        if ($defaultsTable !== null) {
            assert($defaultsTable instanceof CData);
            $isMintedTable = (self::$mintedStaticDefaults[$entryAddress] ?? null) === Core::addressOf($defaultsTable);
            if ($isMintedTable) {
                if (!$isLastHolder) {
                    Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
                    $previousBody->static_variables = null;
                }
                unset(self::$mintedStaticDefaults[$entryAddress]);
            }
        }

        // The entry keeps the single owned reference on the name - the snapshot must
        // not release it
        $previousBody->function_name = null;
        Core::call('destroy_op_array', Core::addr($previousBody));
    }

    /**
     * Checks if any frame of the current VM call stack executes the given entry
     *
     * Suspended frames that are not part of the current stack (generators, fibers)
     * cannot be discovered this way - see the interaction notes in docs/hot-swap.md.
     */
    private static function hasLiveFrame(int $entryAddress): bool
    {
        $frame = Core::$executor->getExecutionState();
        while (true) {
            if ($frame->getFunctionEntryAddress() === $entryAddress) {
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
        CData $entry,
        int $entryAddress,
        int $publishedShares,
        bool $mintedDefaults,
        ?int $previousMintedRecord,
    ): void {
        // The fresh run-time cache was installed by the swap and is not published anywhere
        $entryCommon = $entry->common;
        $opArray     = $entry->op_array;
        assert($entryCommon instanceof CData && $opArray instanceof CData);
        $entryFlags = $entryCommon->fn_flags;
        assert(is_int($entryFlags));
        $cachePointer = $opArray->run_time_cache__ptr;
        if (($entryFlags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0 && $cachePointer !== null) {
            assert($cachePointer instanceof CData);
            Core::free($cachePointer);
        }

        if ($mintedDefaults) {
            $defaultsTable = $opArray->static_variables;
            if ($defaultsTable !== null) {
                assert($defaultsTable instanceof CData);
                Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
            }
            // Restore the bookkeeping of the previous (still committed) swap, if any
            if ($previousMintedRecord !== null) {
                self::$mintedStaticDefaults[$entryAddress] = $previousMintedRecord;
            } else {
                unset(self::$mintedStaticDefaults[$entryAddress]);
            }
        }

        self::dropBodyReferences($entry, $publishedShares);
    }
}
