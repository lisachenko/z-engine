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

use FFI\CData;
use ZEngine\Constants\Defines;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

/**
 * Class OpLine represents one operation that should be performed by the engine
 *
 * struct _zend_op {
 *   const void *handler;
 *   znode_op op1;
 *   znode_op op2;
 *   znode_op result;
 *   uint32_t extended_value;
 *   uint32_t lineno;
 *   zend_uchar opcode;
 *   zend_uchar op1_type;
 *   zend_uchar op2_type;
 *   zend_uchar result_type;
 * };
 */
class OpLine
{
    /**
     * Unused operand
     */
    public const IS_UNUSED = Defines::IS_UNUSED;

    /**
     * This opcode node type is used for literal values in PHP code.
     *
     * For example, the integer literal 1 or string literal 'Hello, World!' will both be of this type.
     */
    public const IS_CONST = Defines::IS_CONST;

    /**
     * This opcode node type is used for temporary variables.
     *
     * These are typically used to store an intermediate result of a larger operation (making them short-lived).
     * They can be an IS_TYPE_REFCOUNTED type (as of PHP 7), but not an IS_REFERENCE type
     * (since temporary values cannot be used as references).
     *
     * For example, the return value of $a++ will be of this type.
     */
    public const IS_TMP_VAR = Defines::IS_TMP_VAR;

    /**
     * This opcode node type is used for complex variables in PHP code.
     *
     * For example, the variable $obj->a is considered to be a complex variable, however the variable $a is not
     * (it is instead an IS_CV type).
     */
    public const IS_VAR = Defines::IS_VAR;

    /**
     * This opcode node type is used for simple variables in PHP code.
     *
     * For example, the variable $a is considered to be a simple variable,
     * however the variable $obj->a is not (it is instead an IS_VAR type).
     */
    public const IS_CV = Defines::IS_CV;

    /**
     * Execution context (if present).
     *
     * It is used for resolving all temporary variables that are stored in
     */
    private ?ExecutionData $context;

    /**
     * Stores the _zend_op * structure pointer
     */
    private CData $opline;

    public function __construct(CData $opline, ExecutionData $context = null)
    {
        $this->opline  = $opline;
        $this->context = $context;
    }

    /**
     * Returns a raw pointer to the opcode handler
     */
    public function getHandler(): CData
    {
        return $this->opline->handler;
    }

    public function getOp1Type(): int
    {
        return $this->opline->op1_type;
    }

    public function getOp2Type(): int
    {
        return $this->opline->op2_type;
    }

    public function getOp1(): ?ReflectionValue
    {
        $value = $this->getValuePointer($this->opline->op1, $this->opline->op1_type);

        return $value;
    }

    public function getOp2(): ?ReflectionValue
    {
        $value = $this->getValuePointer($this->opline->op2, $this->opline->op2_type);

        return $value;
    }

    public function getResult(): ?ReflectionValue
    {
        $value = $this->getValuePointer($this->opline->result, $this->opline->result_type);

        return $value;
    }

    /**
     * Returns a defined code for this entry
     */
    public function getCode(): int
    {
        return $this->opline->opcode;
    }

    /**
     * Directly replace an internal code with another one.
     *
     * <span style="color:red; font-weight:bold">DANGER!</span> This can corrupt memory/engine state.
     *
     * @param int $newCode
     * @internal
     */
    public function setCode(int $newCode): void
    {
        $this->opline->opcode->cdata = $newCode;
    }

    /**
     * Returns user-friendly name of the opCode
     */
    public function getName(): string
    {
        $opCodeName = OpCode::name($this->opline->opcode);

        return $opCodeName;
    }

    /**
     * Returns the line in the code for which this opCode was generated
     */
    public function getLine(): int
    {
        return $this->opline->lineno;
    }

    /**
     * Sets a new line for this entry
     *
     * @param int $newLine New line in the file
     * @internal
     */
    public function setLine(int $newLine): void
    {
        $this->opline->lineno = $newLine;
    }

    /**
     * Returns the type name of operand
     *
     * @param int $opType Integer value of opType
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
        $op1TypeName = self::typeName($this->opline->op1_type);
        $op2TypeName = self::typeName($this->opline->op2_type);
        $resTypeName = self::typeName($this->opline->result_type);

        return [
            $humanCode => [
                'op1'    => [$op1TypeName => $this->getOp1()],
                'op2'    => [$op2TypeName => $this->getOp2()],
                'result' => [$resTypeName => $this->getResult()],
                'line'   => $this->getLine()
            ]
        ];
    }

    /**
     * This utility function returns a pointer to value for given op_node and it's type
     *
     * @param CData $node   Instance of op1/op2/result node
     * @param int   $opType operation code type, eg IS_CONST, IS_CV...
     *
     * @return ReflectionValue|null Extracted value or null, if value could not be resolved (eg. not in runtime)
     *
     * @see zend_execute.c:zend_get_zval_ptr
     */
    private function getValuePointer(CData $node, int $opType): ?ReflectionValue
    {
        $pointer = null;

        switch ($opType) {
            case self::IS_CONST:
                $pointer = self::getRuntimeConstant($this->opline, $node);
                break;
            case self::IS_TMP_VAR:
            case self::IS_VAR:
            case self::IS_CV:
            case self::IS_UNUSED: // For some opcodes IS_UNUSED still used, in most cases it points to an IS_UNDEF value
                // All these types requires context to be present, otherwise we can't resolve such nodes
                if (isset($this->context)) {
                    $pointer = $this->context->getCallVariable($node->var);
                }
                break;
            default:
               throw new \InvalidArgumentException('Received invalid opcode type: ' . $opType);
        }
        $value = isset($pointer) ? ReflectionValue::fromValueEntry($pointer) : null;

        return $value;
    }

    /**
     * Returns value for a runtime-constant with IS_CONST type
     *
     * @see zend_compile.h:RT_CONSTANT macro definition
     *
     * @return CData zval* pointer
     */
    private static function getRuntimeConstant(CData $opline, CData $node): CData
    {
        // ((zval*)(((char*)(opline)) + (int32_t)(node).constant))
        $pointer  = Core::cast('char *', $opline) + $node->constant;
        $value    = Core::cast('zval *', $pointer);

        return $value;
    }
}
