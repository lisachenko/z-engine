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
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for object dimension write operation ($object[$offset] = $value)
 *
 * The offset is null for append operations ($object[] = $value)
 */
class WriteDimensionHook extends AbstractDimensionHook
{
    protected const HOOK_FIELD = 'write_dimension';

    /**
     * Value to write
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $value;

    /**
     * typedef void (*zend_object_write_dimension_t)(zend_object *object, zval *offset, zval *value);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        /**
         * @var zend_object $object Narrowed to the stub views at the engine callback boundary
         * @var zval|null   $offset
         * @var zval        $value
         */
        [$object, $offset, $value] = $rawArguments;
        $this->object              = $object;
        $this->offset              = $offset;
        $this->value               = $value;

        ($this->userHandler)($this);
    }

    /**
     * Returns value to write
     */
    public function getValue(): mixed
    {
        ReflectionValue::fromValueEntry($this->value)->getNativeValue($value);

        return $value;
    }

    /**
     * Proceeds with default handler
     */
    public function proceed(): void
    {
        $originalHandler = $this->getOriginalCallable();

        ($originalHandler)($this->object, $this->offset, $this->value);
    }
}
