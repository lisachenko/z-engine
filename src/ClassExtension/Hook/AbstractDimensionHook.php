<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Abstract object dimension ($object[$offset]) operational hook
 */
abstract class AbstractDimensionHook extends AbstractHook
{
    /**
     * Object instance
     */
    protected CData $object;

    /**
     * Dimension offset (zval pointer), NULL for append operations like $object[] = $value
     */
    protected ?CData $offset;

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Returns the dimension offset or null for append operations ($object[] = $value)
     */
    public function getOffset(): mixed
    {
        if ($this->offset === null) {
            return null;
        }
        ReflectionValue::fromValueEntry($this->offset)->getNativeValue($offset);

        return $offset;
    }
}
