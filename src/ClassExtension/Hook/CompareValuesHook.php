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
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for performing operation on object
 */
class CompareValuesHook extends AbstractHook
{
    protected const HOOK_FIELD = 'compare';

    /**
     * Holds a return value
     */
    protected CData $returnValue;

    /**
     * First operand
     */
    protected CData $op1;

    /**
     * Second operand
     */
    protected CData $op2;

    /**
     * typedef int (*zend_object_compare_t)(zval *object1, zval *object2);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        [$this->op1, $this->op2] = $rawArguments;

        $result = ($this->userHandler)($this);

        return $result;
    }

    /**
     * Returns first operand
     */
    public function getFirst(): mixed
    {
        ReflectionValue::fromValueEntry($this->op1)->getNativeValue($value);

        return $value;
    }

    /**
     * Returns second operand
     */
    public function getSecond(): mixed
    {
        ReflectionValue::fromValueEntry($this->op2)->getNativeValue($value);

        return $value;
    }

    /**
     * Proceeds with object comparison
     *
     * @return int The engine ordering verdict (-1, 0, 1) or Core::FAILURE for uncomparable values
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->op1, $this->op2);
        assert(is_int($result));

        return $result;
    }
}
