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

namespace ZEngine\System;

use ArrayAccess;
use Countable;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_object;
use ZEngine\Type\ObjectEntry;

final class ObjectStore implements Countable, ArrayAccess
{
    /**
     * @see zend_objects_API.h:OBJ_BUCKET_INVALID macro
     */
    private const int OBJ_BUCKET_INVALID = 1 << 0;

    /**
     * Holds an internal pointer to the EG(objects_store)
     */
    private CData $pointer;
    /**
     * @param \FFI\CData $pointer
     */

    public function __construct(object $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function count(): int
    {
        return $this->pointer->top - 1;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function offsetExists($offset): bool
    {
        $isValidOffset = ($offset >= 0) && ($offset < $this->pointer->top);
        $isExists      = $isValidOffset && $this->isObjectValid($this->pointer->object_buckets[$offset]);

        return $isExists;
    }

    /**
     * Returns an object from the storage by it's id or null if this object was released
     *
     * @param int $offset Identifier of object
     *
     * @see spl_object_id()
     */
    #[\Override]
    public function offsetGet($offset): ?ObjectEntry
    {
        if (!\is_int($offset)) {
            throw new \InvalidArgumentException('Object identifier should be an integer');
        }
        if ($offset < 0 || $offset > $this->pointer->top - 1) {
            // We use -2 because exception object also increments index by one
            throw new \OutOfBoundsException("Index {$offset} is out of bounds 0.." . ($this->pointer->top - 2));
        }
        $object = $this->pointer->object_buckets[$offset];

        // Object can be invalid, for that case we should return null
        if (!$this->isObjectValid($object)) {
            return null;
        }
        assert($object instanceof CData);

        $objectEntry = ObjectEntry::fromCData($object);

        return $objectEntry;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Object store is read-only structure');
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function offsetUnset($offset): void
    {
        throw new \LogicException('Object store is read-only structure');
    }

    /**
     * Returns the free head (aka next handle)
     */
    public function nextHandle(): int
    {
        return $this->pointer->free_list_head;
    }

    /**
     * Registers an object in the store, assigning it a fresh handle
     *
     * Wraps zend_objects_store_put: pops the free list or grows the bucket array with the
     * engine's own request allocator, then writes the new handle into the object. Required
     * for objects allocated outside zend_object_std_init (eg persistent clones) that must
     * become visible to the engine for the current request.
     *
     * @param CData|zend_object $object zend_object* to register
     *
     * @return int The handle assigned by the engine (== spl_object_id)
     * @internal
     */
    public function put(object $object): int
    {
        /** @var zend_object $entry Narrowed to the stub view at the owning boundary */
        $entry = $object;
        Core::call('zend_objects_store_put', $entry);

        return $entry->handle;
    }

    /**
     * Detaches existing object from the object store
     *
     * <span style="color:red; font-weight: bold">Warning!</span> This call doesn't invokes object destructors,
     * only detaches an object from the store.
     *
     * @see zend_objects_API.h:SET_OBJ_INVALID macro
     * @internal
     */
    public function detach(int $offset): void
    {
        if ($offset < 0 || $offset > $this->pointer->top - 1) {
            // We use -2 because exception object also increments index by one
            throw new \OutOfBoundsException("Index {$offset} is out of bounds 0.." . ($this->pointer->top - 2));
        }
        $rawPointer        = Core::cast('uintptr_t', $this->pointer->object_buckets[$offset]);
        $invalidPointer    = $rawPointer->cdata | self::OBJ_BUCKET_INVALID;
        $rawPointer->cdata = $invalidPointer;

        $this->pointer->object_buckets[$offset] = Core::cast('zend_object *', $rawPointer);
    }

    /**
     * Detaches an object from the store AND returns its slot to the free list
     *
     * Mirrors the slot bookkeeping of zend_objects_store_del: the bucket receives the
     * tagged number of the previous free head and becomes the new head, so a later
     * put() reuses the slot instead of growing the bucket array. Like detach(), this
     * never invokes destructors or frees the object itself.
     *
     * @see zend_objects_API.h:SET_OBJ_BUCKET_NUMBER macro
     * @internal
     */
    public function recycle(int $offset): void
    {
        if ($offset < 0 || $offset > $this->pointer->top - 1) {
            // We use -2 because exception object also increments index by one
            throw new \OutOfBoundsException("Index {$offset} is out of bounds 0.." . ($this->pointer->top - 2));
        }
        // Prepare every FFI temporary FIRST: each CData is itself a PHP object whose
        // allocation pops this very free list, so creating one between reading and
        // writing free_list_head would stale the captured head and orphan slots
        $taggedNumber = Core::new('uintptr_t');
        $bucketValue  = Core::cast('zend_object *', $taggedNumber);
        $buckets      = Core::cast('zend_object **', $this->pointer->object_buckets);

        // No CData allocations below this line (scalar reads/writes only)
        $taggedNumber->cdata = ($this->pointer->free_list_head << 1) | self::OBJ_BUCKET_INVALID;
        $buckets[$offset]    = $bucketValue;

        $this->pointer->free_list_head = $offset;
    }

    /**
     * Checks if the given object pointer is valid or not
     *
     * @see zend_objects_API.h:IS_OBJ_VALID macro
     * @param \FFI\CData|null $objectPointer
     */
    private function isObjectValid(?object $objectPointer): bool
    {
        if ($objectPointer === null) {
            return false;
        }

        $rawPointer = Core::cast('uintptr_t', $objectPointer);
        $isValid    = ($rawPointer->cdata & self::OBJ_BUCKET_INVALID) === 0;

        return $isValid;
    }
}
