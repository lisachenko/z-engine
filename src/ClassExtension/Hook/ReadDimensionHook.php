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
 * Receiving hook for object dimension read operation ($value = $object[$offset])
 */
class ReadDimensionHook extends AbstractDimensionHook
{
    protected const HOOK_FIELD = 'read_dimension';

    /**
     * Hook access type (BP_VAR_R, BP_VAR_IS, ...)
     */
    protected int $type;

    /**
     * Internal pointer of retval (for native callback only)
     */
    private CData $rv;

    /**
     * typedef zval *(*zend_object_read_dimension_t)(zend_object *object, zval *offset, int type, zval *rv);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): CData
    {
        [$object, $offset, $type, $rv] = $rawArguments;
        assert($object instanceof CData && ($offset === null || $offset instanceof CData));
        assert(is_int($type) && $rv instanceof CData);
        $this->object = $object;
        $this->offset = $offset;
        $this->type   = $type;
        $this->rv     = $rv;

        $result = ($this->userHandler)($this);

        // Return the result through the engine-provided retval slot: the slot is uninitialized
        // scratch memory owned by the caller and the VM consumes the reference left in it,
        // so nothing leaks - unlike a heap zval container which nobody would ever free
        $refValue = new ReflectionValue($result);
        $refValue->copy($rv);
        $refValue->release();

        return $rv;
    }

    /**
     * Returns the access type
     */
    public function getAccessType(): int
    {
        return $this->type;
    }

    /**
     * Proceeds with default handler
     */
    public function proceed(): mixed
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        // @phpstan-ignore callable.nonCallable (engine function pointers are callable CData)
        $result = ($this->originalHandler)($this->object, $this->offset, $this->type, $this->rv);
        assert($result instanceof CData);

        ReflectionValue::fromValueEntry($result)->getNativeValue($phpResult);

        return $phpResult;
    }
}
