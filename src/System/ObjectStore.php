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
use ZEngine\Type\ObjectEntry;

final class ObjectStore implements Countable, ArrayAccess
{
    /**
     * @see zend_objects_API.h:OBJ_BUCKET_INVALID macro
     */
    private const OBJ_BUCKET_INVALID = 1<<0;

    /**
     * Holds an internal pointer to the EG(objects_store)
     */
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return $this->pointer->top - 1;
    }

    /**
     * @inheritDoc
     */
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

        $objectEntry = ObjectEntry::fromCData($object);

        return $objectEntry;
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Object store is read-only structure');
    }

    /**
     * @inheritDoc
     */
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
        $rawPointer        = Core::cast('zend_uintptr_t', $this->pointer->object_buckets[$offset]);
        $invalidPointer    = $rawPointer->cdata | self::OBJ_BUCKET_INVALID;
        $rawPointer->cdata = $invalidPointer;

        $this->pointer->object_buckets[$offset] = Core::cast('zend_object *', $rawPointer);
    }

    /**
     * Checks if the given object pointer is valid or not
     *
     * @see zend_objects_API.h:IS_OBJ_VALID macro
     */
    private function isObjectValid(?CData $objectPointer): bool
    {
        if ($objectPointer === null) {
            return false;
        }

        $rawPointer = Core::cast('zend_uintptr_t', $objectPointer);
        $isValid    = ($rawPointer->cdata & self::OBJ_BUCKET_INVALID) === 0;

        return $isValid;
    }
}
