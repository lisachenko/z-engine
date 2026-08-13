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

use ZEngine\Generated\zend_object;
use ZEngine\Generated\zval;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Abstract object dimension ($object[$offset]) operational hook
 *
 * The engine hands the raw callback arguments over through an FFI trampoline, so every
 * pointer arrives untyped: each concrete hook narrows them once in handle() onto the
 * fields below, which carry the generated struct-stub views (see AGENTS.md, "Engine
 * structs are typed by generated stub classes").
 */
abstract class AbstractDimensionHook extends AbstractHook
{
    /**
     * Object instance
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Dimension offset (zval pointer), NULL for append operations like $object[] = $value
     *
     * @var zval|null Typed view of the engine handle; the runtime value is the raw
     *                FFI\CData pointer
     */
    protected ?object $offset;

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
