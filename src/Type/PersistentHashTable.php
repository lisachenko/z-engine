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

namespace ZEngine\Type;

use ZEngine\Core;
use ZEngine\Generated\Bucket;
use ZEngine\Memory\Allocator;
use ZEngine\Reflection\ReflectionValue;

/**
 * A malloc-backed zend_array that outlives the request
 *
 * Emulates the engine's _zend_hash_init(ht, HT_MIN_SIZE, NULL, persistent = true) with
 * FFI-allocated persistent memory: the struct starts HASH_FLAG_UNINITIALIZED and the
 * first insert makes the engine real-init it. Because the GC header carries
 * GC_PERSISTENT, every engine (re)allocation of the data block goes through
 * pemalloc(..., 1) - plain malloc - and is therefore compatible with the FFI persistent
 * allocator that minted the struct itself.
 *
 * Memory ownership contract (extends the HashTable one, see docs/long-running.md):
 *
 *  - the constructor mints an immortal-by-design block: nothing releases the struct or
 *    the engine-grown data block before process end (destroy() is the explicit
 *    cross-request release); fromCData() wraps an existing persistent table, eg one
 *    recovered from module globals.
 *  - pDestructor is NULL: deleting or overwriting a bucket never releases the payload;
 *    the writer owns every value stored here and any replaced block stays allocated
 *    (bounded, reclaimed at process end).
 *  - add() interns every string key as a persistent interned string: the engine stores
 *    interned keys without addref, so no request-lifetime key can leak into the table.
 *  - add()/addIndex() are upserts here (HASH_UPDATE), unlike the add-new parent methods:
 *    persistent registries are refreshed in place across requests.
 *  - markImmutable() seals the table interned-style (GC_IMMUTABLE, refcount 2): the
 *    engine copies it into zvals without refcounting and copy-on-writes into request
 *    memory on mutation, which is the only safe shape for a persistent array reachable
 *    from userland values.
 */
final class PersistentHashTable extends HashTable
{
    /**
     * Number of uint32_t hash slots the engine reserves per bucket (HT_SIZE_TO_MASK)
     *
     * @see zend_types.h:HT_SIZE_TO_MASK - the mask is -(nTableSize + nTableSize), so the
     *      hash part holds twice as many slots as the table has buckets
     */
    private const int HASH_SLOTS_PER_BUCKET = 2;

    /**
     * Byte pattern of an empty hash slot: HT_INVALID_IDX is 0xFFFFFFFF, so the engine's
     * own HT_HASH_RESET is a memset of 0xFF over the whole hash part
     */
    private const string EMPTY_HASH_SLOT_BYTE = "\xFF";

    /**
     * Address of the externally allocated arData block, or null while the table uses
     * engine-allocated storage (the shared sentinel included)
     */
    private ?int $externalStorageAddress = null;

    /**
     * Number of buckets the installed external block was sized for (0 without one)
     */
    private int $externalCapacity = 0;

    /**
     * Allocation class for the inherited constructor: malloc-backed, outlives the request
     */
    #[\Override]
    protected static function isPersistentAllocation(): bool
    {
        return true;
    }

    /**
     * GC header for the inherited constructor: the cycle collector must never buffer or
     * scan a persistent table, and every engine (re)allocation of the data block must go
     * through pemalloc(..., 1)
     */
    #[\Override]
    protected static function gcTypeInfo(): int
    {
        return Core::engineConstant('GC_ARRAY')
            | Core::engineConstant('GC_PERSISTENT')
            | Core::engineConstant('GC_NOT_COLLECTABLE');
    }

    /**
     * Mints a table whose bucket storage lives in memory the CALLER allocated
     *
     * The pairing of the two seams: the struct comes from $allocator (an arena, say), the
     * arData block from an address the caller sized with externalStorageSize(). Because
     * the block is installed before the first insert, the engine never real-initializes
     * (and therefore never pemallocs) storage for this table at all.
     *
     * @param int            $address   Address of a zeroed block of externalStorageSize($capacity) bytes
     * @param int            $capacity  Number of buckets the block was sized for
     * @param Allocator|null $allocator Source of the struct block (see the constructor)
     */
    public static function withExternalStorage(int $address, int $capacity, ?Allocator $allocator = null): self
    {
        $table = new self($allocator);
        $table->installExternalStorage($address, $capacity);

        return $table;
    }

    /**
     * Byte size of the arData block a table of $capacity buckets needs
     *
     * The engine's own HT_SIZE_EX(nTableSize, HT_SIZE_TO_MASK(nTableSize)): a hash part of
     * two uint32_t slots per bucket, immediately followed by the Bucket area. Both parts
     * are one allocation, which is why arData points INTO it (past the hash part) rather
     * than at its start.
     *
     * @see zend_types.h:HT_SIZE_EX/HT_HASH_SIZE/HT_DATA_SIZE
     */
    public static function externalStorageSize(int $capacity): int
    {
        self::assertValidCapacity($capacity);

        return self::hashPartSize($capacity) + $capacity * Core::sizeOfType(Bucket::class);
    }

    /**
     * Installs a pre-sized, externally allocated arData block on an untouched table
     *
     * Port of zend_hash.c:zend_hash_real_init_mixed() with the pemalloc replaced by the
     * caller's block: nTableSize/nTableMask are set for $capacity, the hash part is reset
     * to HT_INVALID_IDX, arData is pointed past it and HASH_FLAG_UNINITIALIZED is cleared
     * (HASH_FLAG_STATIC_KEYS takes its place, exactly as the engine does - persistent
     * interned keys satisfy it, and the engine clears the flag itself if a non-interned
     * key ever arrives). The iterator count in the high flag bytes is left alone.
     *
     * Required state: the table must still be UNINITIALIZED, i.e. nothing has been inserted
     * yet - once the engine has real-initialized a table, its storage is engine-owned and
     * replacing it would leak (or double-free) that block.
     *
     * GROWTH IS THE HAZARD. The engine grows a full table by perealloc()ing HT_GET_DATA_ADDR,
     * which for an external block means a realloc of memory the process heap knows nothing
     * about. There is no engine hook to forbid that, so the guard is twofold:
     *
     *  - every insert THROUGH THIS WRAPPER checks the remaining capacity first and refuses
     *    the write that would trigger the resize, so growth never starts;
     *  - assertNoGrowth() verifies after the fact that arData and the capacity are still the
     *    installed ones, for the paths (engine C code writing into the same table) that this
     *    class cannot intercept.
     *
     * @param int $address  Address of a block of externalStorageSize($capacity) zeroed bytes
     * @param int $capacity Number of buckets the block was sized for, a power of two
     */
    public function installExternalStorage(int $address, int $capacity): void
    {
        if ($address === 0) {
            throw TypeOperationException::invalidStorageAddress();
        }
        self::assertValidCapacity($capacity);

        $isUninitialized = ($this->pointer->u->flags & Core::engineConstant('HASH_FLAG_UNINITIALIZED')) !== 0;
        if (!$isUninitialized || $this->pointer->nNumUsed !== 0) {
            throw TypeOperationException::storageAlreadyInitialized();
        }

        // HT_HASH_RESET: the whole hash part reads as HT_INVALID_IDX (0xFFFFFFFF) before
        // the first lookup walks it
        $hashPartSize = self::hashPartSize($capacity);
        Core::memcpy(
            Core::pointerAtAddress('char *', $address),
            str_repeat(self::EMPTY_HASH_SLOT_BYTE, $hashPartSize),
            $hashPartSize,
        );

        $this->pointer->nTableSize = $capacity;
        $this->pointer->nTableMask = self::maskFor($capacity);
        // HT_SET_DATA_ADDR: the buckets start right behind the hash part of the same block
        $this->pointer->arData = Core::pointerAtAddress(Bucket::class, $address + $hashPartSize);
        // Only the flags BYTE, so the iterator count in the neighbouring bytes survives -
        // the very reason the engine writes u.v.flags here instead of the whole word
        $this->pointer->u->v->flags    = Core::engineConstant('HASH_FLAG_STATIC_KEYS');
        $this->pointer->nNumUsed       = 0;
        $this->pointer->nNumOfElements = 0;

        $this->externalStorageAddress = $address;
        $this->externalCapacity       = $capacity;
    }

    /**
     * Whether this table stores its buckets in externally allocated memory
     */
    public function hasExternalStorage(): bool
    {
        return $this->externalStorageAddress !== null;
    }

    /**
     * Address of the installed external block, or null when the storage is engine-allocated
     */
    public function getExternalStorageAddress(): ?int
    {
        return $this->externalStorageAddress;
    }

    /**
     * Number of buckets the installed external block was sized for (0 without one)
     */
    public function getCapacity(): int
    {
        return $this->externalCapacity;
    }

    /**
     * Number of bucket slots still free in the installed external block
     *
     * Counts SLOTS, not elements: a deleted bucket keeps its slot until a rehash reclaims
     * it, and the engine's resize decision is made on nNumUsed for exactly that reason.
     * Meaningless (and reported as zero) without external storage.
     */
    public function getRemainingCapacity(): int
    {
        if ($this->externalStorageAddress === null) {
            return 0;
        }

        return max($this->externalCapacity - $this->pointer->nNumUsed, 0);
    }

    /**
     * Verifies that the engine has not moved or resized the installed bucket storage
     *
     * The after-the-fact half of the growth guard: a table backed by external memory must
     * still point at the very block that was installed, with the very capacity it was sized
     * for. A mismatch means engine code grew the table behind this wrapper's back - the
     * external block has been perealloc()ed and the arena is already inconsistent, so the
     * exception is a diagnosis, not a rescue.
     *
     * A no-op for tables whose storage is engine-allocated: there is nothing to protect.
     */
    public function assertNoGrowth(): void
    {
        if ($this->externalStorageAddress === null) {
            return;
        }
        $arData = $this->pointer->arData;
        // An initialized table always carries a data block
        assert($arData !== null);

        $expected = $this->externalStorageAddress + self::hashPartSize($this->externalCapacity);
        if (Core::addressOf($arData) !== $expected || $this->pointer->nTableSize !== $this->externalCapacity) {
            throw TypeOperationException::storageRelocated();
        }
    }

    /**
     * Upserts a value under a persistent interned string key
     *
     * The key is minted as a persistent interned string so the engine stores it without
     * addref and no request-lifetime string can leak into the table. The engine copies
     * the source zval into its bucket; the container stays with the caller.
     */
    #[\Override]
    public function add(string $key, ReflectionValue $value): void
    {
        $this->addInterned(StringEntry::persistentInterned($key), $value);
    }

    /**
     * Upserts a value under a CALLER-OWNED persistent interned string key
     *
     * Same engine semantics as add(), but the key entry is provided by the caller instead
     * of being minted internally. This is the building block for registries that must be
     * able to release their key strings later (eg on eviction): interned keys are never
     * freed by the engine, so a registry that mints keys it cannot enumerate would leak
     * one malloc block per insert. The caller keeps full ownership of the key block and
     * must keep it alive for as long as the bucket exists.
     *
     * @param StringEntry $key Persistent interned string minted via StringEntry::persistentInterned()
     */
    public function addInterned(StringEntry $key, ReflectionValue $value): void
    {
        $this->assertSlotAvailable(fn(): bool => $this->find($key->getStringValue()) !== null);

        $result = Core::call(
            'zend_hash_add_or_update',
            $this->pointer,
            $key->getRawValue(),
            $value->getRawValue(),
            self::HASH_UPDATE,
        );
        if ($result === null) {
            throw TypeOperationException::cannotStoreKey($key->getStringValue());
        }
        $this->assertNoGrowth();
    }

    /**
     * Upserts a value under an integer key
     */
    #[\Override]
    public function addIndex(int $key, ReflectionValue $value): void
    {
        $this->assertSlotAvailable(fn(): bool => $this->findIndex($key) !== null);

        $result = Core::call(
            'zend_hash_index_add_or_update',
            $this->pointer,
            $key,
            $value->getRawValue(),
            self::HASH_UPDATE,
        );
        if ($result === null) {
            throw TypeOperationException::cannotStoreIndex($key);
        }
        $this->assertNoGrowth();
    }

    /**
     * Seals the table interned-style: non-refcounted in zvals, copy-on-write on mutation
     *
     * After sealing, no further add()/addIndex() calls are allowed (the engine asserts
     * on writes into immutable arrays); refcount 2 follows the immutable convention so
     * engine separation paths always duplicate instead of mutating in place.
     */
    public function markImmutable(): void
    {
        $this->pointer->gc->u->type_info |= Core::engineConstant('GC_IMMUTABLE');
        $this->pointer->gc->refcount = 2;
    }

    /**
     * Dismantles the table completely: engine data block first, then the struct itself
     *
     * The counterpart of the constructor: it turns an "immortal by design" persistent table back
     * into free memory, which is what makes persistent registries droppable instead of
     * process-lifetime only.
     *
     * Caller contract - violating any of these corrupts the heap:
     *  - the caller must OWN the table (it minted it, or inherited ownership) and nothing,
     *    in this request or a later one, may reference the struct or its buckets afterwards;
     *  - the wrapper is dead after this call, as is every ReflectionValue previously
     *    obtained from find()/findIndex()/getIterator();
     *  - stored PAYLOADS are not touched: pDestructor is NULL on persistent tables by
     *    construction, so whoever wrote a value into the table still owns it. Release
     *    payloads (nested tables, persistent buffers) BEFORE destroying their container.
     *
     * Sealed (markImmutable()) tables are valid input: the refcount is dropped back to 1
     * first, so the engine's debug-build "nobody else holds this array" assertion in
     * zend_hash_destroy() is satisfied. That is the drop path for persisted user payloads.
     *
     * Both frees go through the persistent allocator: zend_hash_destroy() pefree()s the
     * engine-grown arData block because the GC header carries GC_PERSISTENT (an
     * uninitialized table keeps the shared sentinel and is skipped), and the struct block
     * itself was minted with malloc by the FFI persistent allocator.
     */
    #[\Override]
    public function destroy(): void
    {
        $this->assertOwnedMemory();

        // Sealed tables sit at the immutable refcount of 2; the engine asserts <= 1
        $this->pointer->gc->refcount = 1;

        Core::call('zend_hash_destroy', $this->pointer);

        // Drop the block from z-engine's tracked registry before the memory goes away:
        // a stale entry would make isTrackedBlock() lie about a recycled address and could
        // turn a later untrackAndFree() into a double free
        Core::untrack($this->pointer);
        Core::persistentFree($this->pointer);
    }

    /**
     * Refuses every release path once the table lives in memory z-engine does not own
     *
     * Both frees of destroy() assume z-engine's persistent allocator: zend_hash_destroy()
     * pefree()s HT_GET_DATA_ADDR (the installed external block) and the struct itself goes
     * through free(3). Either one over arena memory corrupts the owner's bookkeeping, so
     * such a table is released by dropping the region it lives in, never here.
     */
    #[\Override]
    protected function assertOwnedMemory(): void
    {
        parent::assertOwnedMemory();

        if ($this->externalStorageAddress !== null) {
            throw TypeOperationException::externalStorageInstalled();
        }
    }

    /**
     * Refuses an insert that would make the engine grow externally allocated storage
     *
     * The engine resizes as soon as an insert finds nNumUsed == nTableSize, so the write
     * that fills the last slot is still fine and only the NEXT one has to be stopped. An
     * upsert of a key that is already there consumes no slot at all, which is why the
     * existence probe is passed lazily: it only runs at the capacity boundary.
     *
     * @param callable(): bool $replacesExistingBucket Whether the pending write is an upsert
     */
    private function assertSlotAvailable(callable $replacesExistingBucket): void
    {
        if ($this->externalStorageAddress === null || $this->pointer->nNumUsed < $this->externalCapacity) {
            return;
        }
        if ($replacesExistingBucket()) {
            return;
        }

        throw TypeOperationException::storageCapacityExhausted($this->externalCapacity);
    }

    /**
     * Byte size of the hash part in front of the buckets (HT_HASH_SIZE of the table's mask)
     */
    private static function hashPartSize(int $capacity): int
    {
        return $capacity * self::HASH_SLOTS_PER_BUCKET * Core::sizeOfType('uint32_t');
    }

    /**
     * The engine's HT_SIZE_TO_MASK(nTableSize): -(2 * capacity), as an uint32_t
     */
    private static function maskFor(int $capacity): int
    {
        return (-($capacity + $capacity)) & 0xFFFFFFFF;
    }

    /**
     * A capacity the engine can address: a power of two, no smaller than HT_MIN_SIZE
     *
     * Mirrors zend_hash.c:zend_hash_check_size(), except that it refuses instead of
     * rounding up - an external block was sized by the caller, and silently pretending it
     * holds more buckets than it does is exactly the corruption this API exists to prevent.
     */
    private static function assertValidCapacity(int $capacity): void
    {
        $minimalCapacity = Core::engineConstant('HT_MIN_SIZE');
        if ($capacity < $minimalCapacity || ($capacity & ($capacity - 1)) !== 0) {
            throw TypeOperationException::invalidStorageCapacity($capacity, $minimalCapacity);
        }
    }
}
