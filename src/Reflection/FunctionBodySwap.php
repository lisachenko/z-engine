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
 *  - The entry gets a fresh run-time cache: the donor's cache is scope-dependent and
 *    owned by the donor. The fresh cache is request memory WITHOUT the
 *    ZEND_ACC_HEAP_RT_CACHE flag (a heap-flagged cache shared by the entry's own
 *    bucket and the inherited-method buckets of subclasses would be freed once per
 *    bucket at shutdown), so z-engine releases it itself: every subsequent swap of
 *    the entry releases the previous cache, which keeps repeated swaps memory-flat.
 *  - Static variables never stay shared with a donor that owns them: a closure
 *    destroys its own static table when it dies, so the entry duplicates the
 *    defaults; the live per-entry table materializes lazily on the first
 *    ZEND_BIND_STATIC, exactly like a plain compiled function.
 *  - The previous body is destroyed with engine semantics (destroy_op_array) when
 *    the swap is committed. A previous body living in opcache shared memory keeps
 *    its compiled arrays (SHM is never freed), but the per-entry resources the
 *    swap minted for it (the run-time cache, a statics defaults duplicate) are
 *    still released, so swaps stay memory-flat with shared-memory donors too.
 *    The entry keeps its owned reference on the function name;
 *    everything exclusively owned by the old body (opcodes, literals, vars,
 *    arg_info, static variables, the minted run-time cache) is released, and bodies still
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
    public const int BODY_LEVEL_FUNCTION_FLAGS = Core::ZEND_ACC_HEAP_RT_CACHE
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
     * Run-time caches minted by swaps and publications, keyed by entry address
     *
     * A minted cache is request memory the entry refers to WITHOUT the
     * ZEND_ACC_HEAP_RT_CACHE flag (see installFreshRunTimeCache()), so the engine never
     * frees it: the next swap of the same entry, or the rollback of the swap that minted
     * it, releases it here - which keeps repeated swaps memory-flat.
     *
     * @var array<int, CData> Entry address => `void **` view of the cache block
     */
    private static array $mintedRunTimeCaches = [];

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
        FunctionLikeInterface $entryFunction,
        FunctionLikeInterface $donorFunction,
        bool $preserveDeclaration,
        bool $duplicateStatics,
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
        Core::memcpy($entry, $donor, Core::sizeOfType(zend_function::class));

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
        // The cache minted by an earlier swap of this entry is released when this swap
        // commits (or kept, when this swap is rolled back)
        $previousMintedCache = self::$mintedRunTimeCaches[$entryAddress] ?? null;
        self::installFreshRunTimeCache($entryFunction);
        $previousMintedRecord = self::$mintedStaticDefaults[$entryAddress] ?? null;
        $mintedDefaults       = self::unshareStaticVariables($entryFunction, $duplicateStatics, $entryAddress);

        return new PendingBodySwap(
            $entryFunction,
            $previousBody,
            $entryAddress,
            $publishedShares,
            $destroyPrevious,
            $mintedDefaults,
            $previousMintedRecord,
            $previousMintedCache,
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
        return max(count(self::publishedShareHolders($entryFunction)), 1);
    }

    /**
     * Returns the user classes whose method tables publish the given entry structure
     * under its own name: the declaring class and every subclass that inherited the
     * entry at link time (see countPublishedShares() for the accounting this implies)
     *
     * A structure published in no class table (a plain function entry) has no holders.
     * Class aliases resolve to the same class entry and are deduplicated.
     *
     * @return list<ReflectionClass>
     *
     * @internal
     */
    public static function publishedShareHolders(FunctionLikeInterface $entryFunction): array
    {
        $entryCommon = $entryFunction->getCommonPointer();
        if ($entryCommon->scope === null) {
            return [];
        }
        $namePointer = $entryCommon->function_name;
        assert($namePointer instanceof CData);
        $lowerName    = strtolower(StringEntry::fromCData($namePointer)->getStringValue());
        $entryAddress = $entryFunction->getAddress();

        $holders     = [];
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
                $holders[] = $reflectionClass;
            }
        }

        return $holders;
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
     * @param ReflectionClass|null $newScope Class the published method will belong to, or null
     *                                       for a global function entry
     *
     * @internal
     */
    public static function adoptFunctionForPublishing(object $container, FunctionLikeInterface $donor, ?ReflectionClass $newScope): void
    {
        Core::memcpy($container, $donor->getEntryPointer(), Core::sizeOfType(zend_function::class));

        $containerFunction      = ReflectionFunction::fromCData(Core::cast(zend_function::class, Core::addr($container)));
        $containerCommon        = $containerFunction->getCommonPointer();
        $containerCommon->scope = $newScope === null
            ? null
            : Core::cast(zend_class_entry::class, $newScope->getRawValue());

        // The published bucket owns one reference on the name and one body share
        $namePointer = $containerCommon->function_name;
        assert($namePointer !== null);
        StringEntry::fromCData($namePointer)->addReference();

        self::addBodyReference($containerFunction);
        self::installFreshRunTimeCache($containerFunction);
        self::unshareStaticVariables($containerFunction, false, $containerFunction->getAddress());
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
        StringEntry::fromCData($namePointer)->addReference();

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
     * never be shared.
     *
     * The fresh cache is a request-memory block the entry refers to WITHOUT the
     * ZEND_ACC_HEAP_RT_CACHE flag - the shape the engine gives ordinary compiled
     * functions (an arena block, released with the request). A heap-flagged cache is
     * efree()d by destroy_op_array BEFORE its refcount check, i.e. once per bucket that
     * publishes the structure: an entry shared by a parent and a subclass table (every
     * subclass linked after a swap, every published alias inherited by a subclass) would
     * have the same block freed once per table at shutdown. So the block is never left
     * to the engine; the next swap of the same entry releases it (keeping repeated swaps
     * memory-flat) and the request end reclaims whatever is left. It is never left NULL
     * either: a call site whose inline cache already resolved the entry skips the lazy
     * initialization and dereferences the cache unconditionally.
     */
    private static function installFreshRunTimeCache(FunctionLikeInterface $entryFunction): void
    {
        $opArray      = $entryFunction->getOpArrayPointer();
        $entryCommon  = $entryFunction->getCommonPointer();
        $entryAddress = $entryFunction->getAddress();
        $cacheSize    = $opArray->cache_size;
        if ($cacheSize > 0) {
            $cache                                    = Core::cast('void **', Core::new("char[{$cacheSize}]", false));
            $opArray->run_time_cache__ptr             = $cache;
            self::$mintedRunTimeCaches[$entryAddress] = $cache;
        } else {
            $opArray->run_time_cache__ptr = null;
            unset(self::$mintedRunTimeCaches[$entryAddress]);
        }
        $entryCommon->fn_flags &= (~Core::ZEND_ACC_HEAP_RT_CACHE);
    }

    /**
     * Releases the run-time cache an earlier swap minted for the entry, once the entry
     * executes a body with its own fresh cache
     *
     * Kept allocated while a frame of the entry still runs on it (the same rule as for
     * the previous body, see destroyPreviousBody()).
     *
     * @param CData|null $previousMintedCache `void **` view recorded before the swap
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function releaseMintedRunTimeCache(int $entryAddress, ?CData $previousMintedCache): void
    {
        if ($previousMintedCache === null || self::hasLiveFrame($entryAddress)) {
            return;
        }
        Core::free($previousMintedCache);
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
    private static function unshareStaticVariables(
        FunctionLikeInterface $entryFunction,
        bool $duplicateStatics,
        int $entryAddress,
    ): bool {
        $opArray                            = $entryFunction->getOpArrayPointer();
        $opArray->static_variables_ptr__ptr = null;

        $defaultsTable = $opArray->static_variables;
        if ($defaultsTable !== null) {
            // The engine's shutdown walk destroys per-method live static tables only
            // for classes flagged as having statics - a swapped-in body with statics
            // must set the flag or its materialized table leaks at request end
            $entryScope = $entryFunction->getCommonPointer()->scope;
            if ($entryScope !== null) {
                ReflectionClass::fromCData($entryScope)->markHasStaticInMethods();
            }
        }
        if ($defaultsTable === null || !$duplicateStatics) {
            return false;
        }
        // Core::call() proxies a variadic engine symbol, its result stays untyped
        $ownDefaults = Core::call('zend_array_dup', $defaultsTable);
        assert($ownDefaults instanceof CData);
        $opArray->static_variables = Core::cast(HashTableStruct::class, $ownDefaults);

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
     * @param CData      $previousBody        zend_function snapshot taken by the swap
     * @param int        $releasedShares      Total bucket shares the entry held on the previous body
     * @param CData|null $previousMintedCache Run-time cache minted for the previous body by an
     *                                        earlier swap, if any (see installFreshRunTimeCache())
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function destroyPreviousBody(
        object $previousBody,
        int $entryAddress,
        int $releasedShares,
        ?CData $previousMintedCache = null,
    ): void {
        $previousFunction = ReflectionFunction::fromCData(Core::cast('zend_function *', Core::addr($previousBody)));
        $previousOpArray  = $previousFunction->getOpArrayPointer();

        if (self::hasLiveFrame($entryAddress)) {
            // A frame of this very entry is still executing the previous opcodes (the
            // function redefined itself, directly or through a callee): freeing them -
            // or the run-time cache the frame reads its inline caches from - would pull
            // memory out from under the running VM frame. The previous body stays
            // allocated instead - bounded to one body per such in-flight
            // redefinition, see docs/hot-swap.md.
            return;
        }
        if ($previousMintedCache !== null) {
            // The cache of the previous body was minted by z-engine (no heap flag, so
            // destroy_op_array below leaves it alone) and nobody reads it anymore
            Core::free($previousMintedCache);
        }

        // A body without a refcount is opcache-shared: its compiled arrays live in
        // shared memory and are never freed (destroy_op_array below returns before
        // touching them), so it can never be the "last holder". The per-entry
        // resources the swap minted for it - the run-time cache (released above) and a
        // statics defaults duplicate - are ordinary request memory though, and are
        // released exactly like for a refcounted body, or repeated swaps whose donors
        // were declared in a cached file leak one cache per swap (the fixed-donor
        // plateau of issue #242 under opcache).
        $refCountPointer = $previousOpArray->refcount;
        $isLastHolder    = false;
        if ($refCountPointer !== null) {
            // All bucket shares move to the new body: drop every previous share except
            // the one that destroy_op_array below releases itself
            $referenceCount = self::counterValue($refCountPointer);
            if ($releasedShares > 1) {
                assert($referenceCount >= $releasedShares);
                $referenceCount     = $referenceCount - ($releasedShares - 1);
                $refCountPointer[0] = $referenceCount;
            }
            $isLastHolder = $referenceCount <= 1;
        }

        $rawOpArray = Core::cast('zend_op_array *', Core::addr($previousBody));
        if ($isLastHolder) {
            // Frees the materialized live static-variables table (if any) and clears
            // the map slot; with other holders alive (or unaccountable, as for a
            // refcount-less body) the table must survive - fake closures over the old
            // body still reference it through their map slots
            Core::call('zend_destroy_static_vars', $rawOpArray);
        }

        // A minted defaults duplicate belongs exclusively to this entry: release it
        // eagerly when the shared body arrays themselves are not freed below
        $defaultsTable = $previousOpArray->static_variables;
        if ($defaultsTable !== null) {
            $isMintedTable = (self::$mintedStaticDefaults[$entryAddress] ?? null) === Core::addressOf($defaultsTable);
            if ($isMintedTable) {
                if (!$isLastHolder) {
                    Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
                    $previousOpArray->static_variables = null;
                }
                unset(self::$mintedStaticDefaults[$entryAddress]);
            }
        }

        // The entry keeps the single owned reference on the name - the snapshot must
        // not release it. destroy_op_array releases the HEAP_RT_CACHE run-time cache
        // (and would release the name) BEFORE its refcount check, then frees the body
        // arrays for the last holder of a refcounted body and returns without touching
        // the arrays of a refcount-less (opcache-shared) one - engine-exact semantics
        // for both lifetime classes.
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
     * @param CData|null $previousMintedCache Cache record to reinstate for the entry (the one
     *                                        the previous body still refers to), if any
     *
     * @internal called by PendingBodySwap::rollback() while the donor is still alive
     */
    public static function releaseSwappedInBody(
        FunctionLikeInterface $entryFunction,
        int $entryAddress,
        int $publishedShares,
        bool $mintedDefaults,
        ?int $previousMintedRecord,
        ?CData $previousMintedCache = null,
    ): void {
        // The fresh run-time cache was minted by the swap and is not published anywhere
        $mintedCache = self::$mintedRunTimeCaches[$entryAddress] ?? null;
        if ($mintedCache !== null) {
            Core::free($mintedCache);
        }
        if ($previousMintedCache !== null) {
            self::$mintedRunTimeCaches[$entryAddress] = $previousMintedCache;
        } else {
            unset(self::$mintedRunTimeCaches[$entryAddress]);
        }

        if ($mintedDefaults) {
            $opArray       = $entryFunction->getOpArrayPointer();
            $defaultsTable = $opArray->static_variables;
            if ($defaultsTable !== null) {
                Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
            }
            // Restore the bookkeeping of the previous (still committed) swap, if any
            if ($previousMintedRecord !== null) {
                self::$mintedStaticDefaults[$entryAddress] = $previousMintedRecord;
            } else {
                unset(self::$mintedStaticDefaults[$entryAddress]);
            }
        }

        self::dropBodyReferences($entryFunction, $publishedShares);
    }
}
