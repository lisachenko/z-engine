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
use IteratorAggregate;
use Traversable;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;

/**
 * Class HashTable provides general access to the internal array objects, aka hash-table
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The wrapper is always a BORROWED view over an engine-owned hashtable (no addref).
 *  - add() makes the ENGINE copy the source zval into its own bucket: the temporary
 *    container passed in stays with the caller and must still be released by the caller
 *    (the bucket owns the payload reference that copy() took).
 *  - find() and iteration yield BORROWED ReflectionValue wrappers over bucket zvals: they
 *    are valid only until the bucket is deleted or the table is rehashed, and reading them
 *    never changes refcounts.
 *  - delete() lets the engine destructor release the bucket payload - nothing on the PHP
 *    side may release it again.
 *
 * struct _zend_array {
 *     zend_refcounted_h gc;
 *     union {
 *         struct {
 *             zend_uchar    flags;
 *             zend_uchar    _unused;
 *             zend_uchar    nIteratorsCount;
 *             zend_uchar    _unused2;
 *         } v;
 *         uint32_t flags;
 *     } u;
 *     uint32_t          nTableMask;
 *     Bucket           *arData;
 *     uint32_t          nNumUsed;
 *     uint32_t          nNumOfElements;
 *     uint32_t          nTableSize;
 *     uint32_t          nInternalPointer;
 *     zend_long         nNextFreeElement;
 *     dtor_func_t       pDestructor;
 * };
 */
class HashTable implements IteratorAggregate, ReferenceCountedInterface
{
    use ReferenceCountedTrait;

    protected const HASH_UPDATE          = (1 << 0);
    protected const HASH_ADD             = (1 << 1);
    protected const HASH_UPDATE_INDIRECT = (1 << 2);
    protected const HASH_ADD_NEW         = (1 << 3);
    protected const HASH_ADD_NEXT        = (1 << 4);

    /**
     * Corresponds to the HASH_FLAG_PACKED flag in zend_types.h
     */
    protected const HASH_FLAG_PACKED = (1 << 2);

    protected CData $pointer;

    public function __construct(CData $hashInstance)
    {
        $this->pointer = $hashInstance;
    }

    /**
     * Retrieve an external iterator
     *
     * @return Traversable An instance of an object implementing <b>Iterator</b> or <b>Traversable</b>
     */
    public function getIterator(): Traversable
    {
        $iterator = function () {
            $isPacked = (bool) ($this->pointer->u->flags & self::HASH_FLAG_PACKED);
            $numUsed  = $this->pointer->nNumUsed;
            for ($index = 0; $index < $numUsed; $index++) {
                if ($isPacked) {
                    // Since PHP 8.2 packed arrays store plain zvals with
                    // implicit integer keys instead of Bucket structures
                    $value = $this->pointer->arPacked[$index];
                    if ($value->u1->v->type === ReflectionValue::IS_UNDEF) {
                        continue;
                    }
                    yield $index => ReflectionValue::fromValueEntry($value);
                } else {
                    $item = $this->pointer->arData[$index];
                    if ($item->val->u1->v->type === ReflectionValue::IS_UNDEF) {
                        continue;
                    }
                    $key = $item->key !== null ? StringEntry::fromCData($item->key)->getStringValue() : null;
                    yield $key => ReflectionValue::fromValueEntry($item->val);
                }
            }
        };

        return $iterator();
    }

    /**
     * Performs search by key in the hashtable
     *
     * @param string $key Key to find
     *
     * @return ReflectionValue|null Value or null if not found
     */
    public function find(string $key): ?ReflectionValue
    {
        $stringEntry = new StringEntry($key);
        $pointer     = Core::call('zend_hash_find', $this->pointer, $stringEntry->getRawValue());

        if ($pointer !== null) {
            $pointer = ReflectionValue::fromValueEntry($pointer);
        }

        return $pointer;
    }

    /**
     * Deletes a value by key from the hashtable
     *
     * @param string $key Key in the hash to delete
     * @internal
     */
    public function delete(string $key): void
    {
        $stringEntry = new StringEntry($key);
        $result      = Core::call('zend_hash_del', $this->pointer, $stringEntry->getRawValue());
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not delete an item with key {$key}");
        }
    }

    /**
     * Deletes a value by integer key from the hashtable
     *
     * Same ownership contract as delete(): the engine destructor releases the bucket
     * payload, nothing on the PHP side may release it again.
     *
     * @param int $key Integer key in the hash to delete
     * @internal
     */
    public function deleteIndex(int $key): void
    {
        $result = Core::call('zend_hash_index_del', $this->pointer, $key);
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not delete an item with index {$key}");
        }
    }

    /**
     * Adds new value to the HashTable
     */
    public function add(string $key, ReflectionValue $value): void
    {
        $stringEntry = new StringEntry($key);
        $result      = Core::call(
            'zend_hash_add_or_update',
            $this->pointer,
            $stringEntry->getRawValue(),
            $value->getRawValue(),
            self::HASH_ADD_NEW,
        );
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not add an item with key {$key}");
        }
    }

    /**
     * Performs search by integer key in the hashtable
     *
     * Same borrowing contract as find(): the returned wrapper is valid until the bucket
     * is deleted or the table is rehashed, and reading it never changes refcounts.
     *
     * @return ReflectionValue|null Value or null if not found
     */
    public function findIndex(int $key): ?ReflectionValue
    {
        $pointer = Core::call('zend_hash_index_find', $this->pointer, $key);

        if ($pointer !== null) {
            $pointer = ReflectionValue::fromValueEntry($pointer);
        }

        return $pointer;
    }

    /**
     * Adds new value with an integer key to the HashTable
     *
     * Same ownership contract as add(): the engine copies the source zval into its own
     * bucket, the temporary container stays with the caller.
     */
    public function addIndex(int $key, ReflectionValue $value): void
    {
        $result = Core::call(
            'zend_hash_index_add_or_update',
            $this->pointer,
            $key,
            $value->getRawValue(),
            self::HASH_ADD_NEW,
        );
        if ($result === null) {
            throw new \RuntimeException("Can not add an item with index {$key}");
        }
    }

    /**
     * Returns raw C value entry (HashTable *)
     * @internal
     */
    public function getRawValue(): CData
    {
        return $this->pointer;
    }

    public function __debugInfo()
    {
        return iterator_to_array($this->getIterator());
    }

    /**
     * This method should return an instance of zend_refcounted_h
     */
    protected function getGC(): CData
    {
        return $this->pointer->gc;
    }
}
