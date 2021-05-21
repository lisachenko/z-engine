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
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;

/**
 * Class ResourceEntry represents a resource instance in PHP
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

    private CData $pointer;

    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Only resource type is accepted');
        }
        $reflectionValue = new ReflectionValue($resource);
        $this->pointer   = $reflectionValue->getRawResource();
    }

    /**
     * Creates a resource entry from the zend_resource structure
     */
    public static function fromCData(CData $pointer): ResourceEntry
    {
        /** @var ResourceEntry $resourceEntry */
        $resourceEntry = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $resourceEntry->pointer = $pointer;

        return $resourceEntry;
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
            'data'     => $this->getRawData()
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
