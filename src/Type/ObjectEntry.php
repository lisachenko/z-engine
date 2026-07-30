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
    use ReleasableTrait;

    private HashTable $properties;

    private CData $pointer;

    /**
     * Weak binding to the source PHP object for dangling-access detection (weakFor() entries only)
     */
    private ?\WeakReference $weakSource = null;

    /**
     * Creates an owning entry: holds one reference on the object for the wrapper lifetime
     */
    public function __construct(object $instance)
    {
        $refValue = new ReflectionValue($instance);
        $pointer  = $refValue->getRawObject();
        $this->initLowLevelStructures($pointer);
        // Take our own reference while the temporary reflection value still holds one
        $this->incrementReferenceCount();
        $this->ownsReference = true;
        $refValue->release();
    }

    /**
     * Creates an object entry from the zend_object structure (borrowed, does not addref)
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
     * Creates a borrowed entry with a weak binding to the source object
     *
     * The entry does not extend the object lifetime (no addref), but unlike a plain borrowed
     * fromCData() entry it can detect that the object has been destroyed: every access to the
     * underlying memory throws instead of dereferencing a dangling pointer.
     */
    public static function weakFor(object $instance): ObjectEntry
    {
        $refValue = new ReflectionValue($instance);

        $objectEntry             = static::fromCData($refValue->getRawObject());
        $objectEntry->weakSource = \WeakReference::create($instance);

        $refValue->release();

        return $objectEntry;
    }

    /**
     * Returns the class reflection for current object
     */
    public function getClass(): ReflectionClass
    {
        $this->assertObjectAlive();

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
        $this->assertObjectAlive();
        $classEntryValue = Core::$executor->classTable->find(strtolower($newClass));
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$newClass} was not found");
        }
        // Class entries are not refcounted engine structures, so replacing the pointer
        // requires no release of the previous entry and no addref of the new one
        $this->pointer->ce = $classEntryValue->getRawClass();
    }

    /**
     * Returns an object handle, this should be equal to spl_object_id
     *
     * @see spl_object_id()
     */
    public function getHandle(): int
    {
        $this->assertObjectAlive();

        return $this->pointer->handle;
    }

    /**
     * Changes object internal handle to another one
     * @internal
     */
    public function setHandle(int $newHandle): void
    {
        $this->assertObjectAlive();
        $this->pointer->handle = $newHandle;
    }

    /**
     * Returns a PHP instance of object, associated with this entry
     */
    public function getNativeValue(): object
    {
        $this->assertObjectAlive();
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $this->pointer[0]);
        $entry->getNativeValue($realObject);
        $entry->release();

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
            'refcount' => $this->getReferenceCount(),
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
        $this->assertObjectAlive();

        return $this->pointer->gc;
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
     * Performs low-level initialization of object
     */
    private function initLowLevelStructures(CData $pointer): void
    {
        $this->pointer = $pointer;
        if ($this->pointer->properties !== null) {
            $this->properties = new HashTable($this->pointer->properties);
        }
    }

    /**
     * Guards weakly-bound entries against dereferencing a destroyed object
     */
    private function assertObjectAlive(): void
    {
        if ($this->weakSource !== null && $this->weakSource->get() === null) {
            throw new \RuntimeException('The underlying object has been destroyed, this entry is dangling');
        }
    }
}
