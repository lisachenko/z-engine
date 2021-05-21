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
use ReflectionClass as NativeReflectionClass;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;

/**
 * Class ObjectEntry represents an object instance in PHP
 *
 * struct _zend_object {
 *   zend_refcounted_h gc;
 *   uint32_t          handle;
 *   zend_class_entry *ce;
 *   const zend_object_handlers *handlers;
 *   HashTable        *properties;
 *   zval              properties_table[1];
 * };
 */
class ObjectEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;

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
        $objectEntry = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
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
     * Changes the class of object to another one
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setClass(string $newClass): void
    {
        $classEntryValue = Core::$executor->classTable->find(strtolower($newClass));
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$newClass} was not found");
        }
        $this->pointer->ce = $classEntryValue->getRawClass();
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
     * Changes object internal handle to another one
     * @internal
     */
    public function setHandle(int $newHandle): void
    {
        $this->pointer->handle = $newHandle;
    }

    /**
     * Returns a PHP instance of object, associated with this entry
     */
    public function getNativeValue(): object
    {
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $this->pointer[0]);
        $entry->getNativeValue($realObject);

        // TODO: Incapsulate memory management into ReflectionValue->release() method
        Core::free($entry->getRawValue());

        return $realObject;
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
     * This method should return an instance of zend_refcounted_h
     */
    protected function getGC(): CData
    {
        return $this->pointer->gc;
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
