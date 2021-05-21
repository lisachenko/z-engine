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
    public function getFirst()
    {
        ReflectionValue::fromValueEntry($this->op1)->getNativeValue($value);

        return $value;
    }

    /**
     * Returns second operand
     */
    public function getSecond()
    {
        ReflectionValue::fromValueEntry($this->op2)->getNativeValue($value);

        return $value;
    }

    /**
     * Proceeds with object comparison
     */
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }
        $result = ($this->originalHandler)($this->op1, $this->op2);

        return $result;
    }
}
