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

namespace ZEngine\Reflection;

use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ZEngine\Core;
use ZEngine\Type\ReferenceCountedInterface;
use ZEngine\Type\ReferenceCountedTrait;
use ZEngine\Type\ReferenceEntry;

/**
 * Class ReflectionValue represents a value in PHP
 *
 * struct _zval_struct {
 *   zend_value        value;            // value
 *   union {
 *     struct {
 *       zend_uchar    type;            // active type
 *       zend_uchar    type_flags;
 *       union {
 *         uint16_t  extra;        // not further specified
 *       } u;
 *     } v;
 *     uint32_t type_info;
 *   } u1;
 *   union {
 *     uint32_t     next;                 // hash collision chain
 *     uint32_t     cache_slot;           // cache slot (for RECV_INIT)
 *     uint32_t     opline_num;           // opline number (for FAST_CALL)
 *     uint32_t     lineno;               // line number (for ast nodes)
 *     uint32_t     num_args;             // arguments number for EX(This)
 *     uint32_t     fe_pos;               // foreach position
 *     uint32_t     fe_iter_idx;          // foreach iterator index
 *     uint32_t     access_flags;         // class constant access flags
 *     uint32_t     property_guard;       // single property guard
 *     uint32_t     constant_flags;       // constant flags
 *     uint32_t     extra;                // not further specified
 *   } u2;
 * } zval;
 *
 * typedef union _zend_value {
 *   zend_long         lval;                // long value
 *   double            dval;                // double value
 *   zend_refcounted  *counted;
 *   zend_string      *str;
 *   zend_array       *arr;
 *   zend_object      *obj;
 *   zend_resource    *res;
 *   zend_reference   *ref;
 *   zend_ast_ref     *ast;
 *   zval             *zv;
 *   void             *ptr;
 *   zend_class_entry *ce;
 *   zend_function    *func;
 *   struct {
 *     uint32_t w1;
 *     uint32_t w2;
 *   } ww;
 * } zend_value;
 */
class ReflectionValue implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;

    /* regular data types */
    public const IS_UNDEF        = 0;
    public const IS_NULL         = 1;
    public const IS_FALSE        = 2;
    public const IS_TRUE         = 3;
    public const IS_LONG         = 4;
    public const IS_DOUBLE       = 5;
    public const IS_STRING       = 6;
    public const IS_ARRAY        = 7;
    public const IS_OBJECT       = 8;
    public const IS_RESOURCE     = 9;
    public const IS_REFERENCE    = 10;
    public const IS_CONSTANT_AST = 11; /* constant expressions */

    /**
     * Fake types used only for type hinting.
     * These are allowed to overlap with the types below.
     */
    public const IS_CALLABLE       = 12;
    public const IS_ITERABLE       = 13;
    public const IS_VOID           = 14;
    public const IS_STATIC         = 15;
    public const IS_MIXED          = 16;

    /* internal types */
    public const IS_INDIRECT  = 12;
    public const IS_PTR       = 13;
    public const IS_ALIAS_PTR = 14;
    public const _IS_ERROR    = 15;

    /* used for casts */
    public const _IS_BOOL   = 17;
    public const _IS_NUMBER = 18;

    private const Z_TYPE_FLAGS_MASK = 0xFF00;

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
     * @param mixed $value Any value to be reflected
     */
    public function __construct($value)
    {
        // Trick here is to look at internal structures and steal pointer to our value from current frame
        $selfExecutionState = Core::$executor->getExecutionState();
        $valueEntry         = $selfExecutionState->getArgument(0);
        $newEntry           = self::newEntry($valueEntry->getType(), $valueEntry->getRawValue()[0]);
        $valueEntry->copy($newEntry->getRawValue());
        $this->pointer = $newEntry->getRawValue();
    }

    /**
     * Creates a reflection from the zval structure
     *
     * @param CData $valueEntry Pointer to the structure
     */
    public static function fromValueEntry(CData $valueEntry): ReflectionValue
    {
        /** @var ReflectionValue $reflectionValue */
        $reflectionValue = (new NativeReflectionClass(self::class))->newInstanceWithoutConstructor();
        $reflectionValue->pointer = $valueEntry;

        return $reflectionValue;
    }

    /**
     * Creates a new entry from it's type and value
     *
     * @param int   $type Value type
     * @param CData $value Value, should be zval-compatible
     *
     * @return ReflectionValue
     */
    public static function newEntry(int $type, CData $value, bool $isPersistent = false): ReflectionValue
    {
        // Allocate non-owned Zval
        $entry = Core::new('zval', false, $isPersistent);

        $entry->u1->type_info = $type;
        $entry->value->zv     = Core::cast('zval', $value);

        return self::fromValueEntry(Core::addr($entry));
    }

    /**
     * Returns value type
     *
     * See defined constants IS_XXXX in this class
     */
    public function getType(): int
    {
        return $this->pointer->u1->type_info;
    }

    /**
     * Returns "native" value for userland
     *
     * @param mixed $returnValue
     */
    public function getNativeValue(&$returnValue): void
    {
        $reference = new ReferenceEntry($returnValue);
        $dstZval   = $reference->getValue()->pointer;

        $this->copy($dstZval);
    }

    /**
     * Change the existing value of entry to another one
     *
     * @param mixed $newValue Value to change to
     */
    public function setNativeValue($newValue): void
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $selfExecutionState->getArgument(0)->copy($this->pointer);
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
     * Type-friendly getter to return indirect value directly
     */
    public function getIndirectValue(): self
    {
        if ($this->pointer->u1->v->type !== self::IS_INDIRECT) {
            throw new \UnexpectedValueException('Indirect entry available only for the type IS_INDIRECT');
        }

        return self::fromValueEntry($this->pointer->value->zv);
    }

    /**
     * Type-friendly getter to return zend_class_entry directly
     */
    public function getRawClass(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR && $this->pointer->u1->v->type !== self::IS_INDIRECT) {
            throw new \UnexpectedValueException('Class entry available only for the type IS_PTR or IS_INDIRECT');
        }

        return $this->pointer->value->ce;
    }

    /**
     * Type-friendly getter to return zend_function/zend_internal_function directly
     */
    public function getRawFunction(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Function entry available only for the type IS_PTR');
        }

        $function = $this->pointer->value->func;
        // If we have an internal function, then we should cast it to the zend_internal_function
        if ($function->type === Core::ZEND_INTERNAL_FUNCTION) {
            $function = Core::cast('zend_internal_function *', $function);
        }

        return $function;
    }

    /**
     * Type-friendly getter to return zend_string directly
     */
    public function getRawString(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_STRING) {
            throw new \UnexpectedValueException('String entry available only for the type IS_STRING');
        }

        return $this->pointer->value->str;
    }

    /**
     * Type-friendly getter to return zend_array directly
     */
    public function getRawArray(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_ARRAY) {
            throw new \UnexpectedValueException('Array entry is available only for the type IS_ARRAY');
        }

        return $this->pointer->value->arr;
    }

    /**
     * Type-friendly getter to return zend_object directly
     */
    public function getRawObject(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_OBJECT) {
            throw new \UnexpectedValueException('Object entry available only for the type IS_OBJECT');
        }

        return $this->pointer->value->obj;
    }

    /**
     * Type-friendly getter to return zend_resource directly
     */
    public function getRawResource(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_RESOURCE) {
            throw new \UnexpectedValueException('Resource entry available only for the type IS_RESOURCE');
        }

        return $this->pointer->value->res;
    }

    /**
     * Type-friendly getter to return zend_resource directly
     */
    public function getRawReference(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_REFERENCE) {
            throw new \UnexpectedValueException('Reference entry available only for the type IS_REFERENCE');
        }

        return $this->pointer->value->ref;
    }

    /**
     * Type-friendly getter to return pointer
     */
    public function getRawPointer(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Pointer entry available only for the type IS_PTR');
        }

        return $this->pointer->value->ptr;
    }

    /**
     * Returns the raw zval structure
     */
    public function getRawValue(): CData
    {
        return $this->pointer;
    }

    /**
     * Performs copying of current value to another one
     *
     * @param CData $dstZval Address to copy value to
     *
     *@see zend_types.h:ZVAL_COPY(z, v) macro
     */
    public function copy(CData $dstZval): void
    {
        $typeInfo = $this->getType();
        $gc       = $this->pointer->value->counted;

        // Content of ZVAL_COPY_VALUE_EX
        if (PHP_INT_SIZE === 4) {                       // if SIZEOF_SIZE_T == 4
            $w2 = $this->pointer->value->ww->w2;        // uint32_t _w2 = v->value.ww.w2;
            $dstZval->value->counted = $gc;             // Z_COUNTED_P(z) = gc;
            $dstZval->value->ww->w2  = $w2;             // z->value.ww.w2 = _w2;
            $dstZval->u1->type_info  = $typeInfo;       // Z_TYPE_INFO_P(z) = t;
        } elseif (PHP_INT_SIZE === 8) {
            $dstZval->value->counted = $gc;             // Z_COUNTED_P(z) = gc;
            $dstZval->u1->type_info  = $typeInfo;       // Z_TYPE_INFO_P(z) = t;
        } else {
            throw new \UnexpectedValueException('Unknown SIZEOF_SIZE_T');
        }

        if ($this->isTypeInfoRefCounted($typeInfo)) {
            $this->incrementReferenceCount();
        }
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

        // We should use only low byte to get the name of constant
        $valueCode &= 0xFF;
        if (!isset(self::$constantNames[$valueCode])) {
            throw new \UnexpectedValueException('Unknown code ' . $valueCode . '. New version of PHP?');
        }

        return self::$constantNames[$valueCode];
    }

    /**
     * Returns var_dump friendly representation of value, otherwise there will be a segfault
     */
    public function __debugInfo(): array
    {
        // TODO: I don't know now how to hijack a return value, so use argument as value holder now
        $this->getNativeValue($nativeValue);

        return [
            'type'  => self::name($this->pointer->u1->v->type),
            'value' => $nativeValue
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getGC(): CData
    {
        return $this->pointer->value->counted->gc;
    }

    /**
     * Checks if the current value is refcounted or not
     *
     * @param int $typeInfo Value type information
     * @see zend_types.h:Z_TYPE_INFO_REFCOUNTED(t) macro
     */
    private function isTypeInfoRefCounted(int $typeInfo): bool
    {
        return ($typeInfo & self::Z_TYPE_FLAGS_MASK) != 0;
    }
}
