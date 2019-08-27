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

namespace ZEngine;

use FFI\CData;

class ValueEntry
{
    /* regular data types */
    public const IS_UNDEF     = 0;
    public const IS_NULL      = 1;
    public const IS_FALSE     = 2;
    public const IS_TRUE      = 3;
    public const IS_LONG      = 4;
    public const IS_DOUBLE    = 5;
    public const IS_STRING    = 6;
    public const IS_ARRAY     = 7;
    public const IS_OBJECT    = 8;
    public const IS_RESOURCE  = 9;
    public const IS_REFERENCE = 10;

    /* constant expressions */
    public const IS_CONSTANT_AST = 11;

    /* internal types */
    public const IS_INDIRECT = 13;
    public const IS_PTR      = 14;
    public const _IS_ERROR   = 15;

    /* fake types used only for type hinting (Z_TYPE(zv) can not use them) */
    public const _IS_BOOL    = 16;
    public const IS_CALLABLE = 17;
    public const IS_ITERABLE = 18;
    public const IS_VOID     = 19;
    public const _IS_NUMBER  = 20;

    private CData $pointer;

    /**
     * Reversed class constants, containing names by number
     *
     * @var string[]
     */
    private static array $constantNames = [];

    public function __construct(CData $value)
    {
        $this->pointer = $value;
    }

    public function getRawData(): ?CData
    {
        return $this->pointer->value;
    }

    /**
     * Returns "native" value for userland
     *
     * @return mixed
     */
    public function getNativeValue()
    {
        // TODO: Is it possible to hijack current function execution data to return fake value?
        switch ($this->pointer->u1->v->type) {
            case self::IS_TRUE:
                return true;
            case self::IS_FALSE:
                return false;
            case self::IS_UNDEF:
            case self::IS_NULL:
                return null;
            case self::IS_LONG:
                return $this->pointer->value->lval;
            case self::IS_DOUBLE:
                return $this->pointer->value->dval;
            case self::IS_STRING:
                return (string)(new StringEntry($this->pointer->value->str));
            case self::IS_ARRAY:
                return new HashTable($this->pointer->value->arr);
            case self::IS_OBJECT:
                // TODO: is it possible to find an object by id in PHP or fake current execution state result?
                return new ObjectEntry($this->pointer->value->obj);
            case self::IS_PTR:
                return $this->pointer->value->ptr;
            case self::IS_INDIRECT:
                return new ValueEntry($this->pointer->value->zv);
            default:
                throw new \UnexpectedValueException("Unexpected type: " . self::name($this->pointer->u1->v->type));
        }
    }

    /**
     * Change the existing value of entry to another one
     *
     * @param $newValue
     */
    public function setNativeValue($newValue)
    {
        $newType = gettype($newValue);
        switch ($newType) {
            case 'integer':
                $this->pointer->u1->v->type->cdata = self::IS_LONG;
                $this->pointer->value->lval->cdata = $newValue;
                break;
            case 'double':
                $this->pointer->u1->v->type->cdata = self::IS_DOUBLE;
                $this->pointer->value->dval->cdata = $newValue;
                break;
            case 'boolean':
                $this->pointer->u1->v->type->cdata = ($newValue === true ? self::IS_TRUE : self::IS_FALSE);
                $this->pointer->value->lval->cdata = ($newValue === true ? 1 : 0);
                break;
            case 'NULL':
                $this->pointer->u1->v->type->cdata = self::IS_NULL;
                $this->pointer->value->ptr = null;
                break;
            default:
                throw new \UnexpectedValueException("Unexpected type: {$newType}");
        }
    }

    /**
     * This method returns zval.u2.extra field value and used in different places
     *
     * hash collision chain
     * cache slot (for RECV_INIT)
     * opline number (for FAST_CALL)
     * line number (for ast nodes)
     * arguments number for EX(This)
     * foreach position
     * foreach iterator index
     * class constant access flags
     * single property guard
     * constant flags
     */
    public function getExtraValue(): int
    {
        return $this->pointer->u2->extra;
    }

    /**
     * Type-friendly getter to work with class-entry Zval-s
     */
    public function getClass(): ReflectionClass
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Class entry available only for the type IS_PTR');
        }

        return ReflectionClass::fromClassEntry($this->pointer->value->ce);
    }

    /**
     * Type-friendly getter to work with function-entry Zval-s
     */
    public function getFunctionEntry(): FunctionEntry
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Function entry available only for the type IS_PTR');
        }

        return new FunctionEntry($this->pointer->value->func);
    }

    /**
     * Returns the type name of code
     *
     * @param int $valueCode Integer value of type
     */
    public static function name(int $valueCode): string
    {
        if (empty(self::$constantNames)) {
            self::$constantNames = array_flip((new \ReflectionClass(self::class))->getConstants());
        }

        if (!isset(self::$constantNames[$valueCode])) {
            throw new \UnexpectedValueException('Unknown code ' . $valueCode . '. New version of PHP?');
        }

        return self::$constantNames[$valueCode];
    }

    public function __debugInfo()
    {
        return [
            'type'  => self::name($this->pointer->u1->v->type),
            'value' => $this->getNativeValue()
        ];
    }
}
