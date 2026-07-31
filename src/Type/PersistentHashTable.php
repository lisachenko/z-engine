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

use FFI\CData;
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
 *  - create() mints an immortal-by-design block: nothing releases the struct or the
 *    engine-grown data block before process end.
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
 *
 * The per-process sentinel block stands in for the engine's non-exported
 * uninitialized_bucket constant (two HT_INVALID_IDX slots); it is shared by every
 * uninitialized persistent table and never freed.
 */
final class PersistentHashTable extends HashTable
{
    /**
     * @see zend_hash.c:uninitialized_bucket - two uint32_t slots holding HT_INVALID_IDX
     */
    private const HT_INVALID_IDX = 0xFFFFFFFF;

    /**
     * Process-lifetime stand-in for the engine's static uninitialized_bucket
     */
    private static ?CData $uninitializedBucket = null;

    /**
     * Mints a new empty persistent hashtable (mutable, engine-compatible)
     *
     * Field-for-field port of zend_hash.c:_zend_hash_init_int(ht, HT_MIN_SIZE, NULL, true).
     */
    public static function create(): self
    {
        $table   = Core::trackedNew('HashTable', true);
        $pointer = Core::cast('HashTable *', Core::addr($table));

        $pointer->gc->refcount     = 1;
        $pointer->gc->u->type_info = Core::engineConstant('GC_ARRAY')
            | Core::engineConstant('GC_PERSISTENT')
            | Core::engineConstant('GC_NOT_COLLECTABLE');
        $pointer->u->flags         = Core::engineConstant('HASH_FLAG_UNINITIALIZED');
        $pointer->nTableMask       = Core::engineConstant('HT_MIN_MASK');
        $pointer->arData           = self::uninitializedBucketData();
        $pointer->nNumUsed         = 0;
        $pointer->nNumOfElements   = 0;
        $pointer->nTableSize       = Core::engineConstant('HT_MIN_SIZE');
        $pointer->nInternalPointer = 0;
        $pointer->nNextFreeElement = PHP_INT_MIN;
        $pointer->pDestructor      = null;

        return new self($pointer);
    }

    /**
     * Wraps an existing persistent hashtable, eg one recovered from module globals
     */
    public static function fromCData(CData $pointer): self
    {
        return new self($pointer);
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
        $stringEntry = StringEntry::persistentInterned($key);
        $result      = Core::call(
            'zend_hash_add_or_update',
            $this->pointer,
            $stringEntry->getRawValue(),
            $value->getRawValue(),
            self::HASH_UPDATE,
        );
        if ($result === null) {
            throw new \RuntimeException("Can not store an item with key {$key}");
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
     * Returns raw C value entry (HashTable *)
     */
    public function getRawValue(): CData
    {
        return $this->pointer;
    }

    /**
     * Returns arData pointing right past the shared sentinel, as HT_SET_DATA_ADDR does
     *
     * @see zend_types.h:HT_SET_DATA_ADDR/HT_HASH_SIZE - for HT_MIN_MASK the hash part
     *      occupies two uint32_t slots, so arData points 8 bytes past the block start
     */
    private static function uninitializedBucketData(): CData
    {
        if (self::$uninitializedBucket === null) {
            $sentinel    = Core::trackedNew('uint32_t[2]', true);
            $sentinel[0] = self::HT_INVALID_IDX;
            $sentinel[1] = self::HT_INVALID_IDX;

            self::$uninitializedBucket = $sentinel;
        }

        return Core::cast('Bucket *', Core::cast('char *', self::$uninitializedBucket) + 8);
    }
}
