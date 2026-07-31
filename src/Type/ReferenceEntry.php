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
 * Class ReferenceEntry represents a reference instance in PHP
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - `new ReferenceEntry($var)` (by-ref argument) is an OWNING construction: the wrapper
 *    holds one reference on the zend_reference for its own lifetime; released automatically
 *    on destruction or via release().
 *  - fromCData() is BORROWED (no addref): valid only while the referencing variables keep
 *    the zend_reference alive.
 *  - getValue() returns a BORROWED ReflectionValue over the embedded val zval - writes
 *    through it hit the shared slot directly, and its lifetime is tied to the reference.
 *  - A reference released to refcount zero through this wrapper is destroyed by the engine
 *    (rc_dtor_func -> zend_reference_destroy); typed-property references carry a sources
 *    list the engine expects to be empty at that point, so never drop the last reference
 *    of a reference still bound to typed properties.
 *
 * struct _zend_reference {
 *     zend_refcounted_h              gc;
 *     zval                           val;
 *     zend_property_info_source_list sources;
 * };
 */
class ReferenceEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;
    use ReleasableTrait;

    private CData $pointer;

    /**
     * Creates an owning entry: holds one reference on the zend_reference for the wrapper lifetime
     */
    public function __construct(&$reference)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $pointer       = $valueArgument->getRawReference();
        $this->pointer = $pointer;
        $this->incrementReferenceCount();
        $this->ownsReference = true;
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
     * Creates a reference entry from the zend_reference structure (borrowed, does not addref)
     *
     * @param CData $pointer Pointer to the structure
     */
    public static function fromCData(CData $pointer): ReferenceEntry
    {
        /** @var ReferenceEntry $referenceEntry */
        $referenceEntry          = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $referenceEntry->pointer = $pointer;

        return $referenceEntry;
    }

    /**
     * Returns the internal value, stored for this reference
     */
    public function getValue(): ReflectionValue
    {
        return ReflectionValue::fromValueEntry($this->pointer->val);
    }

    /**
     * This method returns a dumpable representation of internal value to prevent segfault
     */
    public function __debugInfo(): array
    {
        $info = [
            'refcount' => $this->getReferenceCount(),
            'value'    => $this->getValue(),
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
