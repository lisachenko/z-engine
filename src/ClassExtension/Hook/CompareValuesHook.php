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

use ZEngine\Generated\zval;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for performing operation on object
 */
final class CompareValuesHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'compare';

    /**
     * First operand
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw
     *           FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $op1;

    /**
     * Second operand
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $op2;

    /**
     * typedef int (*zend_object_compare_t)(zval *object1, zval *object2);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): int
    {
        /**
         * @var zval $op1 Narrowed to the stub views at the engine callback boundary
         * @var zval $op2
         */
        [$op1, $op2] = $rawArguments;
        $this->op1   = $op1;
        $this->op2   = $op2;

        $result = ($this->userHandler)($this);
        assert(is_int($result));

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
