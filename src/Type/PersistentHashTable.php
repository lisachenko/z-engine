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
     * Allocation class for the inherited constructor: malloc-backed, outlives the request
     */
    protected static function isPersistentAllocation(): bool
    {
        return true;
    }

    /**
     * GC header for the inherited constructor: the cycle collector must never buffer or
     * scan a persistent table, and every engine (re)allocation of the data block must go
     * through pemalloc(..., 1)
     */
    protected static function gcTypeInfo(): int
    {
        return Core::engineConstant('GC_ARRAY')
            | Core::engineConstant('GC_PERSISTENT')
            | Core::engineConstant('GC_NOT_COLLECTABLE');
    }

    /**
     * Upserts a value under a persistent interned string key
     *
     * The key is minted as a persistent interned string so the engine stores it without
     * addref and no request-lifetime string can leak into the table. The engine copies
     * the source zval into its bucket; the container stays with the caller.
     */
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
        $result = Core::call(
            'zend_hash_add_or_update',
            $this->pointer,
            $key->getRawValue(),
            $value->getRawValue(),
            self::HASH_UPDATE,
        );
        if ($result === null) {
            throw new \RuntimeException('Can not store an item with key ' . $key->getStringValue());
        }
    }

    /**
     * Upserts a value under an integer key
     */
    public function addIndex(int $key, ReflectionValue $value): void
    {
        $result = Core::call(
            'zend_hash_index_add_or_update',
            $this->pointer,
            $key,
            $value->getRawValue(),
            self::HASH_UPDATE,
        );
        if ($result === null) {
            throw new \RuntimeException("Can not store an item with index {$key}");
        }
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
    public function destroy(): void
    {
        // Sealed tables sit at the immutable refcount of 2; the engine asserts <= 1
        $this->pointer->gc->refcount = 1;

        Core::call('zend_hash_destroy', $this->pointer);

        // Drop the block from z-engine's tracked registry before the memory goes away:
        // a stale entry would make isTrackedBlock() lie about a recycled address and could
        // turn a later untrackAndFree() into a double free
        Core::untrack($this->pointer);
        Core::persistentFree($this->pointer);
    }

}
