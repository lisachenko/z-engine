<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Type;

use FFI;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\OpCode;

class OpLine
{
    /**
     * Unused operand
     */
    public const IS_UNUSED = 0;

    /**
     * This opcode node type is used for literal values in PHP code.
     *
     * For example, the integer literal 1 or string literal 'Hello, World!' will both be of this type.
     */
    public const IS_CONST = (1<<0);

    /**
     * This opcode node type is used for temporary variables.
     *
     * These are typically used to store an intermediate result of a larger operation (making them short-lived).
     * They can be an IS_TYPE_REFCOUNTED type (as of PHP 7), but not an IS_REFERENCE type
     * (since temporary values cannot be used as references).
     *
     * For example, the return value of $a++ will be of this type.
     */
    public const IS_TMP_VAR = (1<<1);

    /**
     * This opcode node type is used for complex variables in PHP code.
     *
     * For example, the variable $obj->a is considered to be a complex variable, however the variable $a is not
     * (it is instead an IS_CV type).
     */
    public const IS_VAR = (1<<2);

    /**
     * This opcode node type is used for simple variables in PHP code.
     *
     * For example, the variable $a is considered to be a simple variable,
     * however the variable $obj->a is not (it is instead an IS_VAR type).
     */
    public const IS_CV = (1<<3);

    public CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * Returns a raw pointer to the opcode handler
     */
    public function getHandler(): CData
    {
        return $this->pointer->handler;
    }

    /**
     * Returns the first operand type
     */
    public function getOp1Type(): int
    {
        return $this->pointer->op1_type;
    }

    /**
     * Returns the second operand type
     */
    public function getOp2Type(): int
    {
        return $this->pointer->op2_type;
    }

    /**
     * @return mixed|ReflectionValue
     */
    public function getOp1()
    {
        $value = $this->dumpOpValue($this->pointer->op1, $this->pointer->op1_type);

        return $value;
    }

    /**
     * @return mixed|ReflectionValue
     */
    public function getOp2()
    {
        $value = $this->dumpOpValue($this->pointer->op2, $this->pointer->op2_type);

        return $value;
    }

    /**
     * @return mixed|ReflectionValue
     */
    public function getResult()
    {
        $value = $this->dumpOpValue($this->pointer->result, $this->pointer->result_type);

        return $value;
    }

    /**
     * Returns a defined code for this entry
     */
    public function getCode(): int
    {
        return $this->pointer->opcode;
    }

    /**
     * Directly replace an internal code with another one.
     *
     * <span style="color:red; font-weight:bold">DANGER!</span> This can corrupt memory/engine state.
     *
     * @param int $newCode
     */
    public function setCode(int $newCode): void
    {
        $this->pointer->opcode->cdata = $newCode;
    }

    /**
     * Returns user-friendly name of the opCode
     */
    public function getName(): string
    {
        $opCodeName = OpCode::name($this->pointer->opcode);

        return $opCodeName;
    }

    /**
     * Returns the line in the code for which this opCode was generated
     */
    public function getLine(): int
    {
        return $this->pointer->lineno;
    }

    /**
     * Sets a new line for this entry
     *
     * @param int $newLine New line in the file
     */
    public function setLine(int $newLine): void
    {
        $this->pointer->lineno->cdata = $newLine;
    }

    /**
     * Returns the type name of operand
     *
     * @param int $opType Integer value of opType
     *
     * @return string
     */
    public static function typeName(int $opType): string
    {
        static $opTypeNames;
        if (!isset($opTypeNames)) {
            $opTypeNames = array_flip((new \ReflectionClass(self::class))->getConstants());
        }

        return $opTypeNames[$opType] ?? 'UNKNOWN';
    }

    /**
     * Returns a user-friendly representation of opCode line
     */
    public function __debugInfo(): array
    {
        $humanCode   = $this->getName();
        $op1TypeName = self::typeName($this->pointer->op1_type);
        $op2TypeName = self::typeName($this->pointer->op2_type);
        $resTypeName = self::typeName($this->pointer->result_type);

        return [
            $humanCode => [
                $op1TypeName => $this->getOp1(),
                $op2TypeName => $this->getOp2(),
            ],
            'line'     => $this->getLine(),
            'result'   => [$resTypeName => $this->getResult()]
        ];
    }

    /**
     * This utility function dumps a value from opCode node
     *
     * @param CData $node Instance of op1/op2/result node
     * @param int   $opType operation code type, eg IS_CONST, IS_CV...
     *
     * @return mixed Extracted value
     */
    private function dumpOpValue(CData $node, int $opType)
    {
        $value = null;

        switch ($opType) {
            case self::IS_UNUSED:
                $value = null;
                break;
            case self::IS_CONST:
                // # define RT_CONSTANT(opline, node) \
                // ((zval*)(((char*)(opline)) + (int32_t)(node).constant))
                $pointer  = FFI::cast('void *', $this->pointer) + $node->constant;
                $value    = ReflectionValue::fromValueEntry(Core::cast('zval *', $pointer));

                break;
            case self::IS_TMP_VAR:
                // Just return a string with $node->var converted offset
                $value = '~' . ($node->var - 96) / 16;
                break;
            case self::IS_VAR:
                // #define ZEND_CALL_VAR(call, n) \
                // ((zval*)(((char*)(call)) + ((int)(n))))
                // #define EX_VAR_NUM(n)			ZEND_CALL_VAR_NUM(execute_data, n)
                // #define EX_VAR_TO_NUM(n) \
                // ((uint32_t)(ZEND_CALL_VAR(NULL, n) - ZEND_CALL_VAR_NUM(NULL, 0)))
                // Just return a string with $node->var converted offset
                return '!' . ($node->var - 96) / 16;
            case self::IS_CV:
                // TODO: How to use a pointer to the op_array->vars field in FFI? It doesn't work, PHP unwraps it...
//                $value = $this->opArray->vars[($node->var - 80) / 16];
//                $value = '$' . (string) new StringEntry($value);
                return '$' . ($node->var - 80) / 16;
                break;
            default:
                $value = $node;
        }
        return $value;
    }
}
