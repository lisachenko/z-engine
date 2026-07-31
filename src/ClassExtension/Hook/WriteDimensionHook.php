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
     */
    protected CData $value;

    /**
     * typedef void (*zend_object_write_dimension_t)(zend_object *object, zval *offset, zval *value);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$object, $offset, $value] = $rawArguments;
        assert($object instanceof CData && ($offset === null || $offset instanceof CData));
        assert($value instanceof CData);
        $this->object = $object;
        $this->offset = $offset;
        $this->value  = $value;

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
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        // @phpstan-ignore callable.nonCallable (engine function pointers are callable CData)
        ($this->originalHandler)($this->object, $this->offset, $this->value);
    }
}
