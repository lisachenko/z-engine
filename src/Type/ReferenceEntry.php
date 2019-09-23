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
 * struct _zend_reference {
 *     zend_refcounted_h              gc;
 *     zval                           val;
 *     zend_property_info_source_list sources;
 * };
 */
class ReferenceEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;

    private CData $pointer;

    public function __construct(&$reference)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $pointer       = $valueArgument->getRawReference();
        $this->pointer = $pointer;
    }

    /**
     * Creates a resource entry from the zend_resource structure
     *
     * @param CData $pointer Pointer to the structure
     */
    public static function fromCData(CData $pointer): ReferenceEntry
    {
        /** @var ReferenceEntry $referenceEntry */
        $referenceEntry = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
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
            'value'    => $this->getValue()
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
