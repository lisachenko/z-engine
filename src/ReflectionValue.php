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

use FFI;
use FFI\CData;
use ReflectionClass as NativeReflectionClass;

class ReflectionValue
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

    /**
     * Stores the pointer to zval structure, associated with this variable
     */
    private CData $pointer;

    /**
     * Reversed class constants, containing names by number
     *
     * @var string[]
     */
    private static array $constantNames = [];

    /**
     * ReflectionValue constructor.
     *
     * @TODO: Stack frame is destroyed after call to the constructor, so information will be lost outside this scope
     * @TODO: Temporary declared as private to find the way to extract original value
     *
     * @param mixed $value Any value to be reflected
     */
    private function __construct($value)
    {
        // Trick here is to look at internal structures and steal pointer to our value from current frame
        $selfExecutionState = Core::$executor->getExecutionState();
        $valueEntry         = $selfExecutionState->getArgument(0);
        $this->pointer      = $valueEntry->pointer;
    }

    /**
     * Creates a reflection from the zval structure
     *
     * @param CData $valueEntry Pointer to the structure
     */
    public static function fromValueEntry(CData $valueEntry): ReflectionValue
    {
        /** @var ReflectionValue $reflectionValue */
        $reflectionValue = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionValue->pointer = $valueEntry;

        return $reflectionValue;
    }

    /**
     * Returns raw C value entry
     */
    public function getRawValue(): ?CData
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
                return ReflectionValue::fromValueEntry($this->pointer->value->zv);
            default:
                throw new \UnexpectedValueException("Unexpected type: " . self::name($this->pointer->u1->v->type));
        }
    }

    /**
     * Change the existing value of entry to another one
     *
     * @param mixed $newValue Value to change to
     */
    public function setNativeValue($newValue): void
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $valueEntry         = $selfExecutionState->getArgument(0);

        $this->pointer->value = $valueEntry->pointer->value;
        $this->pointer->u1    = $valueEntry->pointer->u1;
        $this->pointer->u2    = $valueEntry->pointer->u2;
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
