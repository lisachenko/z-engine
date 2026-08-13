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
use ZEngine\Core;
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
     * @return \FFI\CData
     */
    public function handle(...$rawArguments): object
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
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->object, $this->offset, $this->type, $this->rv);
        if ($result === null) {
            // The engine handler raised an exception and produced no value (it returns NULL then);
            // mirror the VM which treats a NULL retval as null and lets the exception propagate
            return null;
        }
        assert($result instanceof CData);

        ReflectionValue::fromValueEntry($result)->getNativeValue($phpResult);

        // Mirror the VM consumption contract (see zend_execute.c): a handler that returns the
        // engine-provided rv slot leaves an OWNED reference in it (e.g. zend_std_read_dimension
        // materializes the offsetGet() result there), which the caller must consume. Our caller -
        // handle() - overwrites the slot with the user handler result instead, so the slot's
        // reference has to be dropped here or it leaks. Any other returned pointer is borrowed
        // (the VM copies from it with an addref, which getNativeValue() above already did)
        if (Core::addressOf($result) === Core::addressOf($this->rv)) {
            Core::call('zval_ptr_dtor', $result);
        }

        return $phpResult;
    }
}
