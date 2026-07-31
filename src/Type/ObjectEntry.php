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
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - `new ObjectEntry($object)` is an OWNING construction: the wrapper holds one reference
 *    and keeps the object alive for its own lifetime; released automatically on destruction
 *    or via release().
 *  - fromCData() is BORROWED (no addref): valid only while somebody else keeps the object
 *    alive - typically inside engine hook callbacks where the caller guarantees liveness.
 *  - weakFor() is BORROWED with a WeakReference guard: it does not extend the object
 *    lifetime, but every accessor throws once the object has been destroyed instead of
 *    dereferencing a dangling zend_object pointer. Prefer it over fromCData() whenever the
 *    source PHP object is at hand.
 *  - setClass() intentionally performs no refcounting: zend_class_entry structures are not
 *    refcounted engine values, so swapping the ce pointer transfers no ownership.
 *  - An object released to refcount zero through this wrapper is destroyed by the engine
 *    (rc_dtor_func -> objects store), never by the FFI allocator.
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
     * Returns the object's extra_flags word (IS_OBJ_* engine flags)
     */
    public function getExtraFlags(): int
    {
        $this->assertObjectAlive();

        return $this->pointer->extra_flags;
    }

    /**
     * Replaces the object's extra_flags word
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setExtraFlags(int $flags): void
    {
        $this->assertObjectAlive();
        $this->pointer->extra_flags = $flags;
    }

    /**
     * Returns a borrowed view of one inline property slot (properties_table[$index])
     *
     * The returned value owns neither the zval container nor a payload reference; it is
     * valid only while the object itself is alive.
     */
    public function getPropertySlot(int $index): ReflectionValue
    {
        $this->assertObjectAlive();
        $propertiesCount = $this->pointer->ce->default_properties_count;
        if ($index < 0 || $index >= $propertiesCount) {
            throw new \OutOfBoundsException("Property slot {$index} is out of bounds 0.." . ($propertiesCount - 1));
        }
        // properties_table is a flexible array member declared zval[1]: FFI bounds-checks
        // direct indexing, so slots past the first must go through pointer arithmetic
        $tableBase = Core::cast('zval *', Core::addr($this->pointer->properties_table[0]));

        return ReflectionValue::fromValueEntry(Core::addr($tableBase[$index]));
    }

    /**
     * Returns a raw pointer (zval *) to the first inline property slot, eg for snapshotting
     * the whole properties_table with memcpy
     * @internal
     */
    public function getPropertyTablePointer(): CData
    {
        $this->assertObjectAlive();

        return Core::addr($this->pointer->properties_table[0]);
    }

    /**
     * Returns the raw dynamic-properties HashTable pointer or null if it was never built
     * @internal
     */
    public function getDynamicPropertiesPointer(): ?CData
    {
        $this->assertObjectAlive();

        return $this->pointer->properties;
    }

    /**
     * Replaces the raw dynamic-properties HashTable pointer
     *
     * No refcounting is performed on either the previous or the new table - the caller
     * keeps ownership of both, exactly like setClass() does for class entries.
     * @internal
     */
    public function setDynamicPropertiesPointer(?CData $hashTable): void
    {
        $this->assertObjectAlive();
        $this->pointer->properties = $hashTable;
    }

    /**
     * Points the object at another zend_object_handlers block
     *
     * Handler blocks are not refcounted engine structures, so replacing the pointer
     * transfers no ownership (same contract as setClass()).
     * @internal
     */
    public function setHandlers(CData $handlers): void
    {
        $this->assertObjectAlive();
        $this->pointer->handlers = Core::cast('zend_object_handlers *', $handlers);
    }

    /**
     * Returns raw C value entry
     */
    public function getRawValue(): CData
    {
        $this->assertObjectAlive();

        return $this->pointer;
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
