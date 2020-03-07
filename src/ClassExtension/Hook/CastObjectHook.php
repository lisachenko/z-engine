<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for casting object to another type
 */
class CastObjectHook extends AbstractHook
{
    protected const HOOK_FIELD = 'cast_object';

    /**
     * Object instance to perform casting
     */
    protected CData $object;

    /**
     * Holds a return value
     */
    protected CData $returnValue;

    /**
     * Cast type
     */
    protected int $type;

    /**
     * typedef int (*zend_object_cast_t)(zval *readobj, zval *retval, int type);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        [$this->object, $this->returnValue, $this->type] = $rawArguments;

        $result = ($this->userHandler)($this);
        ReflectionValue::fromValueEntry($this->returnValue)->setNativeValue($result);

        return Core::SUCCESS;
    }

    /**
     * Returns the cast type
     *
     * @see ReflectionValue class constants, like ReflectionValue::IS_DOUBLE
     */
    public function getCastType(): int
    {
        return $this->type;
    }

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        ReflectionValue::fromValueEntry($this->object)->getNativeValue($objectInstance);

        return $objectInstance;
    }

    /**
     * Returns result of casting (eg from call to proceed)
     */
    public function getResult()
    {
        ReflectionValue::fromValueEntry($this->returnValue)->getNativeValue($result);

        return $result;
    }

    /**
     * Proceeds with object casting
     */
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }
        $result = ($this->originalHandler)($this->object, $this->returnValue, $this->type);

        return $result;
    }
}
