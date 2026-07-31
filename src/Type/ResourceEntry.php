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
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;

/**
 * Class ResourceEntry represents a resource instance in PHP
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - `new ResourceEntry($resource)` is an OWNING construction: the wrapper holds one
 *    reference and keeps the zend_resource alive for its own lifetime; released
 *    automatically on destruction or via release().
 *  - fromCData() is BORROWED (no addref): valid only while another owner (the resource
 *    list, a PHP variable) keeps the resource alive.
 *  - setHandle()/setType() are raw structure writes with no bookkeeping: the resource list
 *    still indexes the entry under its ORIGINAL handle, so an aliased handle must be
 *    restored before the resource is closed - otherwise the close targets whichever
 *    unrelated list entry owns the aliased id.
 *  - A resource released to refcount zero through this wrapper is destroyed by the engine
 *    (rc_dtor_func), never by the FFI allocator.
 *
 * struct _zend_resource {
 *     zend_refcounted_h gc;
 *     int               handle; // TODO: may be removed ???
 *     int               type;
 *     void             *ptr;
 * };
 *
 * @link https://github.com/php/php-src/blob/master/Zend/zend_types.h
 */
class ResourceEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;
    use ReleasableTrait;

    private CData $pointer;

    /**
     * Creates an owning entry: holds one reference on the resource for the wrapper lifetime
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Only resource type is accepted');
        }
        $reflectionValue = new ReflectionValue($resource);
        $this->pointer   = $reflectionValue->getRawResource();
        // Take our own reference while the temporary reflection value still holds one
        $this->incrementReferenceCount();
        $this->ownsReference = true;
        $reflectionValue->release();
    }

    /**
     * Creates a resource entry from the zend_resource structure (borrowed, does not addref)
     */
    public static function fromCData(CData $pointer): ResourceEntry
    {
        /** @var ResourceEntry $resourceEntry */
        $resourceEntry          = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $resourceEntry->pointer = $pointer;

        return $resourceEntry;
    }

    /**
     * @inheritDoc
     */
    protected function doRelease(bool $ownsReference, bool $ownsContainer): void
    {
        if ($ownsReference) {
            $this->releaseReference();
        }
    }

    /**
     * Returns the internal type identifier for this resource
     */
    public function getType(): int
    {
        return $this->pointer->type;
    }

    /**
     * Returns a resource handle
     */
    public function getHandle(): int
    {
        return $this->pointer->handle;
    }

    /**
     * Returns the low-level raw data, associated with this resource
     */
    public function getRawData(): CData
    {
        return $this->pointer->ptr;
    }

    /**
     * Changes the internal type identifier for this resource
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setType(int $newType): void
    {
        $this->pointer->type = $newType;
    }

    /**
     * Changes object internal handle to another one
     * @internal
     */
    public function setHandle(int $newHandle): void
    {
        $this->pointer->handle = $newHandle;
    }

    /**
     * This method returns a dumpable representation of internal value to prevent segfault
     */
    public function __debugInfo(): array
    {
        $info = [
            'type'     => $this->getType(),
            'handle'   => $this->getHandle(),
            'refcount' => $this->getReferenceCount(),
            'data'     => $this->getRawData(),
        ];

        return $info;
    }

    /**
     * This method should return an instance of zend_refcounted_h
     */
    protected function getGC(): CData
    {
        return $this->pointer->gc;
    }
}
