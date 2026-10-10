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
use ZEngine\Generated\zend_closure;
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
 *    The engine copies the entry structure, cache pointer included, into every fake
 *    closure made over the entry and into the clone a subclass implementing an
 *    interface gets of the inherited entry; such copies are found and given a cache of
 *    their own before the block is released (see releaseRetiredRunTimeCache()).
 *  - Static variables never stay shared with a donor that owns them: a closure
 *    destroys its own static table when it dies, so the entry duplicates the
 *    defaults; the live per-entry table materializes lazily on the first
 *    ZEND_BIND_STATIC, exactly like a plain compiled function. A duplicate minted this
 *    way belongs to exactly one zend_function: a container adopting the body of an
 *    entry with a minted table, or an entry adopting such a body as its donor, mints a
 *    duplicate of its own. A preserved alias takes over the live statics table of the
 *    body it preserves (the preserved body keeps its state).
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
     * Static-variable defaults tables minted by swaps and publications, keyed by entry address
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
     * Address of the engine's Closure class entry, resolved once (see liveClosureFunctions())
     */
    private static ?int $closureClassAddress = null;

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
     *                                   (method donors about to be released via destroy_zend_class).
     *                                   A donor body that owns its table anyway (a closure-backed
     *                                   method) or carries a table minted by another swap gets a
     *                                   duplicate whatever the flag says, see unshareStaticVariables()
     * @param bool  $destroyPrevious     False when the previous body must stay allocated on commit
     *                                   (opcache shared memory is never freed)
     * @param EntryPublications|null $publications Survey of the tables publishing the entry (its
     *                                   shares and clones), taken by the caller when one survey
     *                                   serves several swaps; surveyed here when omitted
     *
     * @internal
     */
    public static function swapUserFunctionBody(
        FunctionLikeInterface $entryFunction,
        FunctionLikeInterface $donorFunction,
        bool $preserveDeclaration,
        bool $duplicateStatics,
        bool $destroyPrevious = true,
        ?EntryPublications $publications = null,
    ): PendingBodySwap {
        $publications ??= EntryPublications::survey($entryFunction);
        // Subclass method tables may share this very structure (inheritance publishes the
        // parent's pointer): every such bucket releases one body reference when the engine
        // destroys it, so the swap mirrors that accounting on the new body
        $publishedShares = $publications->sharesOf($entryFunction);
        $entry           = $entryFunction->getEntryPointer();
        $donor           = $donorFunction->getEntryPointer();
        $entryAddress    = $entryFunction->getAddress();

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
        // Same for a statics defaults duplicate minted by an earlier swap: the record is
        // read BEFORE this swap may overwrite it with its own duplicate
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
            $publications,
        );
    }

    /**
     * Returns the user classes whose method tables publish the given entry structure
     * under its own name, in a class-table walk of its own (see EntryPublications::holdersOf())
     *
     * @return list<ReflectionClass>
     *
     * @internal
     */
    public static function publishedShareHolders(FunctionLikeInterface $entryFunction): array
    {
        return EntryPublications::survey($entryFunction)->holdersOf($entryFunction);
    }

    /**
     * Returns the engine clones of a method entry published in the tables of its subclasses,
     * each with the class holding it, in a class-table walk of its own (see
     * EntryPublications::clonesOf())
     *
     * @return list<array{ReflectionClass, ReflectionMethod}>
     *
     * @internal
     */
    public static function cloneHolders(FunctionLikeInterface $entryFunction): array
    {
        return EntryPublications::survey($entryFunction)->clonesOf($entryFunction);
    }

    /**
     * Prepares a standalone writable zend_function container from a donor method for
     * publishing into a method table (the ClassDelta "new method" operation, the aliases
     * and overrides of MethodPublisher)
     *
     * The container adopts the donor body with its own owned resources: one body
     * reference, one owned reference on the function name (every published bucket
     * releases a name reference when the engine destroys it), a fresh run-time cache
     * and lazily-materialized statics - or, for an alias preserving the donor's body,
     * the donor's materialized live statics table, which moves over to the container
     * (the preserved body keeps its state; the donor entry, about to execute another
     * body, starts afresh). The donor may be destroyed afterwards.
     *
     * @param CData              $container zend_function container to fill (writable, zeroed or reused)
     * @param FunctionLikeInterface $donor  Donor method whose body is adopted
     * @param ReflectionClass|null $newScope Class the published method will belong to, or null
     *                                       for a global function entry
     * @param bool $inheritLiveStatics       True when the container takes over the donor's live
     *                                       statics table (alias publications)
     *
     * @return bool True when an own statics defaults duplicate was minted for the container
     *              (released through releaseSwappedInBody() when the container is dropped)
     *
     * @internal
     */
    public static function adoptFunctionForPublishing(
        object $container,
        FunctionLikeInterface $donor,
        ?ReflectionClass $newScope,
        bool $inheritLiveStatics = false,
    ): bool {
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
        $liveStatics    = $inheritLiveStatics ? self::detachLiveStatics($donor) : null;
        $mintedDefaults = self::unshareStaticVariables($containerFunction, false, $containerFunction->getAddress());
        if ($liveStatics !== null) {
            $containerFunction->getOpArrayPointer()->static_variables_ptr__ptr = $liveStatics;
        }

        return $mintedDefaults;
    }

    /**
     * Whether a swap or publication minted a statics defaults duplicate for the entry at this
     * address that is still recorded (see unshareStaticVariables())
     *
     * @internal used by the container release paths of MethodPublisher and ClassDelta
     */
    public static function hasMintedStaticDefaults(int $entryAddress): bool
    {
        return isset(self::$mintedStaticDefaults[$entryAddress]);
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
     * memory-flat, see releaseRetiredRunTimeCache() for the copies of the entry that may
     * still point at it) and the request end reclaims whatever is left. It is never left
     * NULL either: a call site whose inline cache already resolved the entry skips the
     * lazy initialization and dereferences the cache unconditionally.
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
     * @param CData|null             $previousMintedCache `void **` view recorded before the swap
     * @param EntryPublications|null $publications        Survey taken for the swap (see swapUserFunctionBody())
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function releaseMintedRunTimeCache(
        FunctionLikeInterface $entryFunction,
        ?CData $previousMintedCache,
        ?EntryPublications $publications = null,
    ): void {
        if ($previousMintedCache === null || self::hasLiveFrame($entryFunction->getAddress())) {
            return;
        }
        self::releaseRetiredRunTimeCache(
            $entryFunction,
            $previousMintedCache,
            self::liveClosureFunctions(),
            $publications ?? EntryPublications::survey($entryFunction),
        );
    }

    /**
     * Releases a run-time cache block the entry no longer executes on, once nothing else reads it
     *
     * The block is request memory without the heap flag, so nothing but this release frees it -
     * and the engine copies the entry structure, cache pointer included, wherever it needs a
     * zend_function of its own. Three kinds of copies may still point at the block when the
     * entry moves on, and each is handled before the free:
     *
     *  - a frame on the current stack executing on the block (a fake closure of the entry, or
     *    its caller chain, redefining the entry): the block stays allocated, bounded to one
     *    block per such in-flight redefinition exactly like the previous body itself;
     *  - a live fake closure made over the entry while the block was installed
     *    (Closure::fromCallable(), a first-class callable, ReflectionMethod::getClosure()):
     *    zend_create_closure_ex shares a scope-matching non-heap cache, so the closure gets a
     *    private heap copy of its warmed slots, flagged ZEND_ACC_HEAP_RT_CACHE - what the engine
     *    gives a closure over a heap-cached function, and freed with the closure by
     *    destroy_op_array;
     *  - an engine clone of the entry in a subclass table (see EntryPublications::clonesOf())
     *    gets a fresh minted cache of its own.
     *
     * Nothing here runs user code (no destructor can fire), so the closures gathered by the
     * caller stay exactly the live ones throughout.
     *
     * @param list<ReflectionFunction> $liveClosures The functions of the live closures, see liveClosureFunctions()
     */
    private static function releaseRetiredRunTimeCache(
        FunctionLikeInterface $entryFunction,
        CData $cache,
        array $liveClosures,
        EntryPublications $publications,
    ): void {
        $cacheAddress = Core::addressOf($cache);
        if (self::hasLiveFrameUsingCache($cacheAddress)) {
            return;
        }
        foreach ($liveClosures as $closureFunction) {
            $closureOpArray = $closureFunction->getOpArrayPointer();
            $closureCache   = $closureOpArray->run_time_cache__ptr;
            if ($closureCache === null || Core::addressOf($closureCache) !== $cacheAddress) {
                continue;
            }
            $cacheSize   = $closureOpArray->cache_size;
            $privateCopy = Core::new("char[{$cacheSize}]", false);
            Core::memcpy($privateCopy, $cache, $cacheSize);
            $closureOpArray->run_time_cache__ptr = Core::cast('void **', $privateCopy);
            $closureCommon                       = $closureFunction->getCommonPointer();
            $closureCommon->fn_flags             = $closureCommon->fn_flags | Core::ZEND_ACC_HEAP_RT_CACHE;
        }
        foreach ($publications->clonesOf($entryFunction) as [, $clone]) {
            $cloneCache = $clone->getOpArrayPointer()->run_time_cache__ptr;
            if ($cloneCache !== null && Core::addressOf($cloneCache) === $cacheAddress) {
                self::installFreshRunTimeCache($clone);
            }
        }
        Core::free($cache);
    }

    /**
     * Detaches the entry's static variables from the donor
     *
     * The live per-entry table always materializes lazily (first ZEND_BIND_STATIC dup
     * of the defaults), exactly like a plain compiled function; the engine destroys it
     * through its regular shutdown walks. When the donor owns the defaults table, the
     * entry gets an independent duplicate, recorded so the next swap can release it
     * eagerly. A donor owns its table when the caller says so (a closure donor destroys
     * its own on death), when its body is a closure's (zend_create_closure_ex points the
     * live slot of a closure AT its defaults table: a method published from a closure
     * carries exactly that shape) or when the table was minted by a swap for another
     * entry (which releases it on ITS next swap).
     *
     * @return bool True when an own defaults duplicate was minted for the entry
     */
    private static function unshareStaticVariables(
        FunctionLikeInterface $entryFunction,
        bool $duplicateStatics,
        int $entryAddress,
    ): bool {
        $opArray                            = $entryFunction->getOpArrayPointer();
        $liveSlot                           = $opArray->static_variables_ptr__ptr;
        $defaultsTable                      = $opArray->static_variables;
        $opArray->static_variables_ptr__ptr = null;
        if ($defaultsTable === null) {
            return false;
        }
        // The engine's shutdown walk destroys per-method live static tables only
        // for classes flagged as having statics - a swapped-in body with statics
        // must set the flag or its materialized table leaks at request end
        $entryScope = $entryFunction->getCommonPointer()->scope;
        if ($entryScope !== null) {
            ReflectionClass::fromCData($entryScope)->markHasStaticInMethods();
        }
        $defaultsAddress = Core::addressOf($defaultsTable);
        $donorOwnsTable  = $liveSlot !== null && Core::addressOf($liveSlot) === $defaultsAddress;
        $isMintedTable   = in_array($defaultsAddress, self::$mintedStaticDefaults, true);
        if (!$duplicateStatics && !$donorOwnsTable && !$isMintedTable) {
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
     * Takes the materialized live statics table away from an entry, for the container
     * that preserves the entry's current body under an alias
     *
     * @return HashTableStruct|null The table (plain `HashTable *`), null when none materialized yet
     */
    private static function detachLiveStatics(FunctionLikeInterface $entryFunction): ?object
    {
        $liveTable = $entryFunction->getLiveStaticsPointer();
        if ($liveTable === null) {
            return null;
        }
        $entryFunction->getOpArrayPointer()->static_variables_ptr__ptr = null;

        return $liveTable;
    }

    /**
     * Destroys the previous body of a swapped entry with engine semantics
     *
     * The snapshot shares the function name with the entry (which keeps its owned
     * reference), so the name pointer is detached before destroy_op_array runs. The
     * materialized static-variables table and a z-engine-minted defaults duplicate
     * are exclusively owned by this entry and are released explicitly - unless a copy
     * of the entry still points at them, see isLiveStaticsBorrowed() and
     * isDefaultsTableShared(); everything else follows the body refcount - shared
     * bodies (a live template op_array, a fake closure over the old body) keep their
     * arrays until the last holder dies.
     *
     * @param CData      $previousBody         zend_function snapshot taken by the swap
     * @param int        $releasedShares       Total bucket shares the entry held on the previous body
     * @param CData|null $previousMintedCache  Run-time cache minted for the previous body by an
     *                                         earlier swap, if any (see installFreshRunTimeCache())
     * @param int|null   $previousMintedRecord Address of the statics defaults duplicate minted for the
     *                                         previous body by an earlier swap, if any
     * @param EntryPublications|null $publications Survey taken for the swap (see swapUserFunctionBody())
     *
     * @internal called by PendingBodySwap::commit()
     */
    public static function destroyPreviousBody(
        FunctionLikeInterface $entryFunction,
        object $previousBody,
        int $releasedShares,
        ?CData $previousMintedCache = null,
        ?int $previousMintedRecord = null,
        ?EntryPublications $publications = null,
    ): void {
        $entryAddress     = $entryFunction->getAddress();
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
        $publications ??= EntryPublications::survey($entryFunction);

        // A body without a refcount is opcache-shared: its compiled arrays live in
        // shared memory and are never freed (destroy_op_array below returns before
        // touching them), so it can never be the "last holder". The per-entry
        // resources the swap minted for it - the run-time cache and a statics defaults
        // duplicate - are ordinary request memory though, and are released exactly like
        // for a refcounted body, or repeated swaps whose donors were declared in a
        // cached file leak one cache per swap (the fixed-donor plateau of issue #242
        // under opcache).
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

        $liveTable       = $previousOpArray->static_variables_ptr__ptr;
        $defaultsTable   = $previousOpArray->static_variables;
        $isMintedDefault = $defaultsTable !== null
            && $previousMintedRecord      !== null
            && $previousMintedRecord === Core::addressOf($defaultsTable);

        // Every decision that depends on the live closures is taken first, on one walk
        // over the object store: the releases below may run destructors (a value held
        // in a statics table), and user code may create or drop closures. Nothing a
        // destructor creates can reach the resources of the PREVIOUS body - the entry
        // already executes the new one - so the decisions stay valid while they are
        // carried out.
        $readsClosures = $previousMintedCache !== null
            || (!$isLastHolder && ($liveTable !== null || $isMintedDefault));
        $liveClosures = $readsClosures ? self::liveClosureFunctions() : [];
        if ($previousMintedCache !== null) {
            // The cache of the previous body was minted by z-engine (no heap flag, so
            // destroy_op_array below leaves it alone); nothing in the release runs user code
            self::releaseRetiredRunTimeCache($entryFunction, $previousMintedCache, $liveClosures, $publications);
        }
        // The materialized live static-variables table was reachable through this entry
        // only - the slot now belongs to the new body - unless a fake closure over the old
        // body or an engine clone of the entry borrowed it: then it must survive for them
        $destroysLiveTable = $liveTable !== null
            && ($isLastHolder || !self::isLiveStaticsBorrowed($entryFunction, $liveTable, $liveClosures, $publications));
        // A minted defaults duplicate belongs exclusively to this entry: it is released
        // eagerly when the shared body arrays themselves are not freed below
        $releasesDefaults = $isMintedDefault
            && !$isLastHolder
            && !self::isDefaultsTableShared($entryFunction, $defaultsTable, $liveClosures, $publications);

        $rawOpArray = Core::cast('zend_op_array *', Core::addr($previousBody));
        if ($destroysLiveTable) {
            // Frees the materialized live static-variables table and clears the map slot
            Core::call('zend_destroy_static_vars', $rawOpArray);
        }
        if ($isMintedDefault) {
            if ($releasesDefaults) {
                Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
                $previousOpArray->static_variables = null;
            }
            // The record now belongs to the duplicate this swap minted, if it minted one
            if ((self::$mintedStaticDefaults[$entryAddress] ?? null) === $previousMintedRecord) {
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
     * Releases what the engine never will once the bucket of a published entry is deleted
     * from the only table holding it: the materialized live statics table (the engine
     * destroys those through class tables only), a minted statics defaults duplicate and
     * a minted run-time cache
     *
     * Nothing is released while a frame of the entry runs, while another class table still
     * publishes the structure (the engine reaches the statics through that table) or while a
     * fake closure still shares the resource.
     *
     * @internal called by ReflectionClass::removeMethods() before the bucket is deleted
     */
    public static function retireUnpublishedEntry(FunctionLikeInterface $entryFunction): void
    {
        if (!$entryFunction->isUserDefined()) {
            return;
        }
        $entryAddress = $entryFunction->getAddress();
        if (self::hasLiveFrame($entryAddress)) {
            return;
        }
        $publications = EntryPublications::survey($entryFunction);
        if ($publications->sharesOf($entryFunction) > 1) {
            return;
        }
        $opArray       = $entryFunction->getOpArrayPointer();
        $liveTable     = $entryFunction->getLiveStaticsPointer();
        $defaultsTable = $opArray->static_variables;
        $mintedRecord  = self::$mintedStaticDefaults[$entryAddress] ?? null;
        $mintedCache   = self::$mintedRunTimeCaches[$entryAddress]  ?? null;
        // The last holder's destroy_op_array (the bucket destructor) frees a minted defaults
        // table itself
        $isMintedDefault = $defaultsTable !== null && $mintedRecord === Core::addressOf($defaultsTable);
        $refCountPointer = $opArray->refcount;
        $isLastHolder    = $refCountPointer !== null && self::counterValue($refCountPointer) <= 1;

        // Unlike a committed swap, the entry still carries the body being retired, so a
        // destructor fired by a table release could make a fake closure over it that shares
        // the remaining resources: the closures are gathered again after any release that
        // may run user code, and the run-time cache goes last (such a destructor may still
        // call the method, which reads its inline caches from the block)
        $liveClosures = ($liveTable !== null || ($isMintedDefault && !$isLastHolder) || $mintedCache !== null)
            ? self::liveClosureFunctions()
            : [];
        $destroysLiveTable = $liveTable !== null
            && !self::isLiveStaticsBorrowed($entryFunction, $liveTable, $liveClosures, $publications);
        if ($destroysLiveTable) {
            Core::call('zend_destroy_static_vars', Core::cast('zend_op_array *', $entryFunction->getEntryPointer()));
            $liveClosures = self::liveClosureFunctions();
        }

        if ($isMintedDefault) {
            if (!$isLastHolder && !self::isDefaultsTableShared($entryFunction, $defaultsTable, $liveClosures, $publications)) {
                Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $defaultsTable));
                $opArray->static_variables = null;
                $liveClosures              = self::liveClosureFunctions();
            }
            unset(self::$mintedStaticDefaults[$entryAddress]);
        }

        if ($mintedCache !== null) {
            unset(self::$mintedRunTimeCaches[$entryAddress]);
            self::releaseRetiredRunTimeCache($entryFunction, $mintedCache, $liveClosures, $publications);
            $opArray->run_time_cache__ptr = null;
        }
    }

    /**
     * Whether a copy of the entry still reads the materialized live statics table: a
     * fake closure borrows the table of the function it was made over (zend_create_closure_ex
     * initializes its slot to it), an engine clone copied the slot with the structure
     *
     * @param HashTableStruct          $liveTable
     * @param list<ReflectionFunction> $liveClosures The functions of the live closures, see liveClosureFunctions()
     */
    private static function isLiveStaticsBorrowed(
        FunctionLikeInterface $entryFunction,
        object $liveTable,
        array $liveClosures,
        EntryPublications $publications,
    ): bool {
        $tableAddress = Core::addressOf($liveTable);
        foreach ($liveClosures as $closureFunction) {
            $closureSlot = $closureFunction->getOpArrayPointer()->static_variables_ptr__ptr;
            if ($closureSlot !== null && Core::addressOf($closureSlot) === $tableAddress) {
                return true;
            }
        }
        foreach ($publications->clonesOf($entryFunction) as [, $clone]) {
            $cloneSlot = $clone->getOpArrayPointer()->static_variables_ptr__ptr;
            if ($cloneSlot !== null && Core::addressOf($cloneSlot) === $tableAddress) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a copy of the entry still points at the minted statics defaults table
     *
     * An engine clone of the entry (its struct copy in a subclass table) is given a duplicate
     * of its own first, recorded under the clone's address like any minted table. A fake
     * closure copied the pointer too and frees the table when it turns out to be the last
     * holder of the body: the table then stays with the closure (and leaks when it is not).
     *
     * @param HashTableStruct          $defaultsTable
     * @param list<ReflectionFunction> $liveClosures The functions of the live closures, see liveClosureFunctions()
     */
    private static function isDefaultsTableShared(
        FunctionLikeInterface $entryFunction,
        object $defaultsTable,
        array $liveClosures,
        EntryPublications $publications,
    ): bool {
        $tableAddress = Core::addressOf($defaultsTable);
        foreach ($publications->clonesOf($entryFunction) as [, $clone]) {
            $cloneOpArray  = $clone->getOpArrayPointer();
            $cloneDefaults = $cloneOpArray->static_variables;
            if ($cloneDefaults === null || Core::addressOf($cloneDefaults) !== $tableAddress) {
                continue;
            }
            $ownDefaults = Core::call('zend_array_dup', $cloneDefaults);
            assert($ownDefaults instanceof CData);
            $cloneOpArray->static_variables                   = Core::cast(HashTableStruct::class, $ownDefaults);
            self::$mintedStaticDefaults[$clone->getAddress()] = Core::addressOf($ownDefaults);
        }
        foreach ($liveClosures as $closureFunction) {
            $closureDefaults = $closureFunction->getOpArrayPointer()->static_variables;
            if ($closureDefaults !== null && Core::addressOf($closureDefaults) === $tableAddress) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gathers the zend_function of every live closure object as a pointer-level view, in one
     * walk over the object store
     *
     * Only user-function closures are of interest (an internal function's closure carries
     * no op_array, and no user-function view can be built over it), so the list is narrowed
     * to those before any view is made. The views point into the closure
     * objects: the list describes the live closures only until user code runs again (a
     * destructor fired by a table release may create or drop closures), so the callers take
     * every decision that reads it BEFORE they destroy anything.
     *
     * @return list<ReflectionFunction>
     */
    private static function liveClosureFunctions(): array
    {
        if (self::$closureClassAddress === null) {
            $closureClass = Core::$executor->classTable->find('closure');
            assert($closureClass !== null);
            self::$closureClassAddress = Core::addressOf($closureClass->getRawClass());
        }
        $liveClosures = [];
        foreach (Core::$executor->objectStore->instancesOf(self::$closureClassAddress) as $closureObject) {
            $closure = Core::cast(zend_closure::class, $closureObject->getRawValue());
            // The type is read off the union before any view is built over it: a closure
            // over an internal function (strlen(...), Closure::fromCallable('strlen')) keeps
            // its name in the internal_function member, where a zend_function view has none
            if (($closure->func->type & Core::ZEND_USER_FUNCTION) === 0) {
                continue;
            }
            $liveClosures[] = ReflectionFunction::fromCData(Core::addr($closure->func));
        }

        return $liveClosures;
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
     * Checks if any user-function frame of the current VM call stack reads its inline
     * caches from the given cache block (a fake closure of the entry running right now,
     * for instance)
     */
    private static function hasLiveFrameUsingCache(int $cacheAddress): bool
    {
        $frame = Core::$executor->getExecutionState();
        while (true) {
            $frameFunction = $frame->getFunctionEntry();
            if ($frameFunction !== null && $frameFunction->isUserDefined() && $frame->getRunTimeCacheAddress() === $cacheAddress) {
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
     * @param CData|null             $previousMintedCache Cache record to reinstate for the entry (the one
     *                                                    the previous body still refers to), if any
     * @param EntryPublications|null $publications        Survey taken for the swap (see swapUserFunctionBody())
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
        ?EntryPublications $publications = null,
    ): void {
        // The fresh run-time cache was minted by the swap and is not published anywhere
        $mintedCache = self::$mintedRunTimeCaches[$entryAddress] ?? null;
        if ($mintedCache !== null) {
            self::releaseRetiredRunTimeCache(
                $entryFunction,
                $mintedCache,
                self::liveClosureFunctions(),
                $publications ?? EntryPublications::survey($entryFunction),
            );
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
