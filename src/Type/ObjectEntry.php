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

class ObjectEntry
{
    private HashTable $properties;

    private CData $pointer;

    public function __construct(object $instance)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $pointer = $valueArgument->getRawObject();
        $this->initLowLevelStructures($pointer);
    }

    /**
     * Creates an object entry from the zend_object structure
     *
     * @param CData $pointer Pointer to the structure
     */
    public static function fromCData(CData $pointer): ObjectEntry
    {
        /** @var ObjectEntry $objectEntry */
        $objectEntry = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $objectEntry->initLowLevelStructures($pointer);

        return $objectEntry;
    }

    /**
     * Returns the class reflection for current object
     */
    public function getClass(): ReflectionClass
    {
        return ReflectionClass::fromCData($this->pointer->ce);
    }

    /**
     * Returns an object handle, this should be equal to spl_object_id
     *
     * @see spl_object_id()
     */
    public function getHandle(): int
    {
        return $this->pointer->handle;
    }

    /**
     * Returns an internal reference counter value
     */
    public function getReferenceCount(): int
    {
        return $this->pointer->gc->refcount;
    }

    /**
     * This method returns a dumpable representation of internal value to prevent segfault
     */
    public function __debugInfo(): array
    {
        $info = [
            'class'    => $this->getClass()->getName(),
            'handle'   => $this->getHandle(),
            'refcount' => $this->getReferenceCount()
        ];
        if (isset($this->properties)) {
            $info['properties'] = $this->properties;
        }

        return $info;
    }

    /**
     * Performs low-level initialization of object
     */
    private function initLowLevelStructures(CData $pointer): void
    {
        $this->pointer = $pointer;
        if ($this->pointer->properties !== null) {
            $this->properties = new HashTable($this->pointer->properties);
        }
    }
}
