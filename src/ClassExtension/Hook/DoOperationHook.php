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

use ZEngine\Core;
use ZEngine\Generated\zval;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for performing operation on object
 */
class DoOperationHook extends AbstractHook
{
    protected const HOOK_FIELD = 'do_operation';

    /**
     * Operation opcode
     */
    protected int $opCode;

    /**
     * Holds a return value
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw
     *           FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $returnValue;

    /**
     * First operand
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $op1;

    /**
     * Second operand
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $op2;

    /**
     * typedef int (*zend_object_do_operation_t)(zend_uchar opcode, zval *result, zval *op1, zval *op2);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        /**
         * @var int  $opCode Narrowed to the stub views at the engine callback boundary
         * @var zval $returnValue
         * @var zval $op1
         * @var zval $op2
         */
        [$opCode, $returnValue, $op1, $op2] = $rawArguments;
        $this->opCode                       = $opCode;
        $this->returnValue                  = $returnValue;
        $this->op1                          = $op1;
        $this->op2                          = $op2;

        $result = ($this->userHandler)($this);
        $target = ReflectionValue::fromValueEntry($this->returnValue);
        if (Core::addressOf($this->returnValue) === Core::addressOf($this->op1)) {
            // Compound assignment (eg $a += $b): the result slot is op1 and holds a live
            // value which must be released when it gets replaced
            $target->setNativeValue($result);
        } else {
            // Plain binary operation: the result slot is uninitialized scratch memory
            $target->initializeNativeValue($result);
        }

        return Core::SUCCESS;
    }

    /**
     * Returns an opcode
     */
    public function getOpcode(): int
    {
        return $this->opCode;
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
     * Returns result of casting (eg from call to proceed)
     */
    public function getResult(): mixed
    {
        ReflectionValue::fromValueEntry($this->returnValue)->getNativeValue($result);

        return $result;
    }

    /**
     * Proceeds with object custom operation
     *
     * @return int Core::SUCCESS when the original handler produced a value in the result slot,
     *             Core::FAILURE when the operation is not supported for these operands
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->opCode, $this->returnValue, $this->op1, $this->op2);
        assert(is_int($result));

        return $result;
    }
}
