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

/**
 * Receiving hook for object dimension unset operation (unset($object[$offset]))
 */
class UnsetDimensionHook extends AbstractDimensionHook
{
    protected const HOOK_FIELD = 'unset_dimension';

    /**
     * typedef void (*zend_object_unset_dimension_t)(zend_object *object, zval *offset);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$object, $offset] = $rawArguments;
        assert($object instanceof CData && ($offset === null || $offset instanceof CData));
        $this->object = $object;
        $this->offset = $offset;

        ($this->userHandler)($this);
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
        ($this->originalHandler)($this->object, $this->offset);
    }
}
