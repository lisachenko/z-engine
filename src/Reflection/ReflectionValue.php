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
use ZEngine\Generated\zend_array;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_refcounted_h;
use ZEngine\Generated\zend_reference;
use ZEngine\Generated\zend_resource;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zend_value;
use ZEngine\Generated\zval;
use ZEngine\Type\ReferenceCountedInterface;
use ZEngine\Type\ReferenceCountedTrait;
use ZEngine\Type\ReferenceEntry;
use ZEngine\Type\ReleasableTrait;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * Class ReflectionValue represents a value in PHP
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - `new ReflectionValue($value)` is an OWNING construction: it allocates its own 16-byte
 *    zval container and takes exactly one reference on refcounted payloads. Both are dropped
 *    automatically on destruction, or eagerly via the idempotent release(); any access after
 *    release() throws instead of touching freed memory.
 *  - fromValueEntry() is a BORROWED view over an engine-owned zval: it owns nothing,
 *    release() is a no-op, and the caller must guarantee the pointed zval stays valid.
 *  - newEntry() returns a wrapper that owns only its container - the payload reference still
 *    belongs to the caller. Call acquireReference() before handing the zval to an engine
 *    function with ownership semantics (one that may release or replace the value inside,
 *    eg zend_prepare_string_for_scanning), and release() to free whatever is owned.
 *  - transferReferenceOwnership() hands the owned payload reference over to an engine sink
 *    that will release it later (hashtable bucket, class entry field, AST node); after the
 *    transfer this wrapper no longer drops the reference.
 *  - copy() follows ZVAL_COPY semantics (takes a reference on refcounted payloads).
 *    setNativeValue() releases the previous destination content like an engine assignment;
 *    initializeNativeValue() writes into UNINITIALIZED engine output slots (cast_object
 *    retval, do_operation result) where interpreting the previous bytes would crash.
 *  - Engine memory is never freed through the FFI allocator: payload releases are routed
 *    through zval_ptr_dtor/rc_dtor_func, so interned, immutable and persistent payloads
 *    keep their engine semantics. Because every owning wrapper holds its own reference,
 *    aliasing two wrappers over one pointer can never double-free.
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
    use ReleasableTrait;

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
    public const IS_CALLABLE = 12;
    public const IS_ITERABLE = 13;
    public const IS_VOID     = 14;
    public const IS_STATIC   = 15;
    public const IS_MIXED    = 16;
    public const IS_NEVER    = 17;

    /* internal types */
    public const IS_INDIRECT  = 12;
    public const IS_PTR       = 13;
    public const IS_ALIAS_PTR = 14;
    public const _IS_ERROR    = 15;

    /* used for casts */
    public const _IS_BOOL   = 18;
    public const _IS_NUMBER = 19;

    private const Z_TYPE_FLAGS_MASK = 0xFF00;

    /**
     * Stores the pointer to the zval structure associated with this variable
     *
     * @var zval Typed view; the runtime value is the raw FFI\CData handle
     *           (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

    /**
     * Reversed class constants, containing names by number
     *
     * @var string[]
     */
    private static array $constantNames = [];

    /**
     * ReflectionValue constructor.
     *
     * The constructed instance owns its zval container and exactly one reference on the
     * payload (when the payload is refcounted); both are dropped by release()/__destruct.
     *
     * @param mixed $value Any value to be reflected
     */
    public function __construct(mixed $value)
    {
        // Trick here is to look at internal structures and steal pointer to our value from current frame
        $selfExecutionState = Core::$executor->getExecutionState();
        $valueEntry         = $selfExecutionState->getArgument(0);

        $container     = Core::new(zval::class, false);
        $this->pointer = Core::cast(zval::class, Core::addr($container));
        // copy() takes an own reference on refcounted payloads, exactly like ZVAL_COPY
        $valueEntry->copy($this->pointer);

        $this->ownsContainer = true;
        $this->ownsReference = $this->isTypeInfoRefCounted($this->getType());
    }

    /**
     * Creates a reflection from the zval structure
     *
     * @param CData|zval $valueEntry Pointer to the structure
     */
    public static function fromValueEntry(object $valueEntry): ReflectionValue
    {
        /** @var ReflectionValue $reflectionValue */
        $reflectionValue = (new NativeReflectionClass(self::class))->newInstanceWithoutConstructor();
        /** @var zval $valueEntry Narrowed to the stub view at the owning boundary */
        $reflectionValue->pointer = $valueEntry;

        return $reflectionValue;
    }

    /**
     * Creates a new entry from it's type and value
     *
     * @param int          $type Value type (base type constant, any type flags are recomputed from the payload)
     * @param CData|object $value Value, should be zval-compatible (statically stub-typed views are accepted)
     *
     * @return ReflectionValue
     */
    public static function newEntry(int $type, object $value, bool $isPersistent = false): ReflectionValue
    {
        // Allocate non-owned Zval
        $entry = Core::new(zval::class, false, $isPersistent);

        // The payload write goes through a raw view: zend_value member writes are
        // FFI-level struct-to-pointer conversions the typed stubs do not model
        Core::cast('zval *', Core::addr($entry))->value->zv = Core::cast('zval', $value);
        $entry->u1->type_info                               = self::buildTypeInfo($type, $entry);

        $reflectionValue = self::fromValueEntry(Core::addr($entry));
        // The container is ours to free, the payload reference still belongs to the caller
        $reflectionValue->ownsContainer = true;

        return $reflectionValue;
    }

    /**
     * Computes the full zval type_info word (base type plus type flags) for a payload
     *
     * Callers historically passed bare type constants (eg IS_STRING instead of IS_STRING_EX), which
     * made every refcount-aware consumer (copy(), isTypeInfoRefCounted()) silently skip refcounting.
     * The flags are derived from the payload's GC header, exactly like the engine's IS_*_EX macros do.
     *
     * @see zend_types.h:IS_STRING_EX/IS_ARRAY_EX/IS_OBJECT_EX macro family
     */
    /**
     * @param CData|zval $zvalEntry
     */
    private static function buildTypeInfo(int $type, object $zvalEntry): int
    {
        $baseType = $type & Core::engineConstant('Z_TYPE_MASK');

        // Only real data types in the IS_STRING..IS_CONSTANT_AST range are backed by a GC header
        if ($baseType < self::IS_STRING || $baseType > self::IS_CONSTANT_AST) {
            return $baseType;
        }

        // Immutable payloads (interned strings, immutable arrays, SHM data) are copied without refcounting
        /** @var zval $zvalEntry Narrowed to the stub view at the owning boundary */
        $counted = $zvalEntry->value->counted;
        assert($counted !== null);
        $gcTypeInfo = $counted->gc->u->type_info;
        if (($gcTypeInfo & ReferenceCountedInterface::GC_IMMUTABLE) !== 0) {
            return $baseType;
        }

        $typeFlags = Core::engineConstant('IS_TYPE_REFCOUNTED');
        if ($baseType === self::IS_ARRAY || $baseType === self::IS_OBJECT) {
            $typeFlags |= Core::engineConstant('IS_TYPE_COLLECTABLE');
        }

        return $baseType | ($typeFlags << Core::engineConstant('Z_TYPE_FLAGS_SHIFT'));
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
        $this->assertNotReleased();
        $reference = new ReferenceEntry($returnValue);
        $dstZval   = $reference->getValue()->pointer;

        self::copyAndReleasePrevious($this, $dstZval);
    }

    /**
     * Change the existing value of entry to another one
     *
     * The previous content of this entry is properly released, exactly like an engine
     * variable assignment would do. For uninitialized engine output slots (which contain
     * garbage, not a value) use initializeNativeValue() instead.
     *
     * @param mixed $newValue Value to change to
     */
    public function setNativeValue($newValue): void
    {
        $this->assertNotReleased();
        $selfExecutionState = Core::$executor->getExecutionState();

        self::copyAndReleasePrevious($selfExecutionState->getArgument(0), $this->pointer);
    }

    /**
     * Writes a value into an uninitialized engine output slot
     *
     * Unlike setNativeValue() this does not release the previous slot content: engine
     * handler result slots (cast_object retval, do_operation result) are uninitialized
     * scratch memory, and interpreting the garbage in them as a value would crash.
     *
     * @param mixed $newValue Value to write
     */
    public function initializeNativeValue($newValue): void
    {
        $this->assertNotReleased();
        $selfExecutionState = Core::$executor->getExecutionState();

        $selfExecutionState->getArgument(0)->copy($this->pointer);
    }

    /**
     * Copies a value over a destination zval, releasing whatever the destination held before
     *
     * The previous content is saved aside and released only after the copy took its own
     * reference, so a self-assignment of a refcount-1 payload cannot use freed memory.
     */
    /**
     * @param CData|zval $dstZval
     */
    private static function copyAndReleasePrevious(ReflectionValue $source, object $dstZval): void
    {
        // The destination may arrive as an embedded zval struct (16 bytes) or as a zval
        // pointer (8 bytes). FFI::typeof is avoided on purpose: probing a CData's kind and
        // then referencing it again leaks the FFI type structure, see Core::cast
        if (Core::sizeof($dstZval) === Core::sizeOfType(zval::class)) {
            $dstZval = Core::addr($dstZval);
        }

        $previousValue = Core::new(zval::class);
        Core::memcpy($previousValue, StructArray::at($dstZval), Core::sizeOfType(zval::class));

        $source->copy($dstZval);

        Core::call('zval_ptr_dtor', Core::addr($previousValue));
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

        $indirect = $this->pointer->value->zv;
        assert($indirect !== null);

        return self::fromValueEntry($indirect);
    }

    /**
     * Type-friendly getter to return zend_class_entry directly
     */
    /**
     * @return zend_class_entry
     */
    public function getRawClass(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Class entry available only for the type IS_PTR');
        }
        $classEntry = $this->pointer->value->ce;
        assert($classEntry !== null);

        return $classEntry;
    }

    /**
     * Type-friendly getter to return zend_function/zend_internal_function directly
     */
    /**
     * @return zend_function|zend_internal_function
     */
    public function getRawFunction(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Function entry available only for the type IS_PTR');
        }

        $function = $this->pointer->value->func;
        assert($function !== null);
        // If we have an internal function, then we should cast it to the zend_internal_function
        if ($function->type === Core::ZEND_INTERNAL_FUNCTION) {
            return Core::cast(zend_internal_function::class, $function);
        }

        return $function;
    }

    /**
     * Type-friendly getter to return zend_string directly
     */
    /**
     * @return zend_string
     */
    public function getRawString(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_STRING) {
            throw new \UnexpectedValueException('String entry available only for the type IS_STRING');
        }
        $string = $this->pointer->value->str;
        assert($string !== null);

        return $string;
    }

    /**
     * Type-friendly getter to return zend_array directly
     */
    /**
     * @return zend_array
     */
    public function getRawArray(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_ARRAY) {
            throw new \UnexpectedValueException('Array entry is available only for the type IS_ARRAY');
        }
        $array = $this->pointer->value->arr;
        assert($array !== null);

        return $array;
    }

    /**
     * Type-friendly getter to return zend_object directly
     */
    /**
     * @return zend_object
     */
    public function getRawObject(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_OBJECT) {
            throw new \UnexpectedValueException('Object entry available only for the type IS_OBJECT');
        }
        $entry = $this->pointer->value->obj;
        assert($entry !== null);

        return $entry;
    }

    /**
     * Type-friendly getter to return zend_resource directly
     */
    /**
     * @return zend_resource
     */
    public function getRawResource(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_RESOURCE) {
            throw new \UnexpectedValueException('Resource entry available only for the type IS_RESOURCE');
        }
        $resource = $this->pointer->value->res;
        assert($resource !== null);

        return $resource;
    }

    /**
     * Type-friendly getter to return zend_resource directly
     */
    /**
     * @return zend_reference
     */
    public function getRawReference(): object
    {
        if ($this->pointer->u1->v->type !== self::IS_REFERENCE) {
            throw new \UnexpectedValueException('Reference entry available only for the type IS_REFERENCE');
        }
        $reference = $this->pointer->value->ref;
        assert($reference !== null);

        return $reference;
    }

    /**
     * Follows a reference to the value it points to (ZVAL_DEREF equivalent)
     *
     * Safe to call unconditionally, exactly like the C macro: for a non-reference value
     * this is an identity operation and returns the SAME instance. For an IS_REFERENCE
     * zval it returns a BORROWED view over the zend_reference's embedded `val` slot -
     * nothing is addref'd or released, so the result stays valid only while the
     * zend_reference itself is kept alive (by the referencing variables or by this
     * wrapper's own payload reference).
     *
     * @see zend_types.h:ZVAL_DEREF(z) macro
     */
    public function dereference(): self
    {
        if ($this->getBaseType() !== self::IS_REFERENCE) {
            return $this;
        }

        // Borrowed view over the inner val slot, same as ReferenceEntry::getValue()
        $reference = $this->valueUnion()->ref;
        assert($reference !== null);

        return self::fromValueEntry($reference->val);
    }

    /**
     * Type-friendly getter to return pointer
     */
    public function getRawPointer(): CData
    {
        if ($this->pointer->u1->v->type !== self::IS_PTR) {
            throw new \UnexpectedValueException('Pointer entry available only for the type IS_PTR');
        }
        $pointer = $this->pointer->value->ptr;
        assert($pointer !== null);

        return $pointer;
    }

    /**
     * Returns the raw zval structure
     *
     * The pointer is a live view into engine memory: reads reflect the current zval
     * state and writes go straight to the engine. Prefer the typed accessors
     * (getType(), getNativeValue(), equals()) over poking fields on the result.
     */
    /**
     * @return zval
     */
    public function getRawValue(): object
    {
        $this->assertNotReleased();

        return $this->pointer;
    }

    /**
     * Returns the numeric address of the underlying zval, for pointer identity checks
     */
    public function getAddress(): int
    {
        $this->assertNotReleased();

        return Core::addressOf($this->pointer);
    }

    /**
     * Repoints the IS_PTR payload of this zval at the given raw pointer
     *
     * Used to redirect a function/class table bucket at a writable copy without running
     * the bucket destructor - the previous pointer is simply overwritten.
     *
     * @internal used by the copy-out-of-SHM path
     */
    /**
     * @param CData|object $pointer Runtime value is always CData; statically stub-typed views are accepted
     */
    public function setPointer(object $pointer): void
    {
        $this->assertNotReleased();
        $this->valueUnion()->ptr = Core::cast('void *', $pointer);
    }

    /**
     * Structurally compares this zval with another one for the hot-swap delta
     *
     * This is a CONSERVATIVE scalar comparison: two values are equal only when they
     * carry the same base type and, for scalars, the same payload; every non-scalar
     * payload (array, object, constant expression) is treated as different, because a
     * missed change would keep stale state while a spurious "different" only costs a
     * redundant swap. Constant-flag comparison is left to the owning wrapper
     * (ReflectionClassConstant::equals()).
     */
    public function equals(ReflectionValue $other): bool
    {
        $this->assertNotReleased();
        $type = $this->getBaseType();
        if ($type !== $other->getBaseType()) {
            return false;
        }
        $thisValue  = $this->valueUnion();
        $otherValue = $other->valueUnion();
        switch ($type) {
            case self::IS_UNDEF:
            case self::IS_NULL:
            case self::IS_FALSE:
            case self::IS_TRUE:
                return true;
            case self::IS_LONG:
                return $thisValue->lval === $otherValue->lval;
            case self::IS_DOUBLE:
                return $thisValue->dval === $otherValue->dval;
            case self::IS_STRING:
                $thisString  = $thisValue->str;
                $otherString = $otherValue->str;
                assert($thisString !== null && $otherString !== null);

                return StringEntry::fromCData($thisString)->getStringValue()
                    === StringEntry::fromCData($otherString)->getStringValue();
            default:
                // Arrays, objects and constant expressions: conservatively different
                return false;
        }
    }

    /**
     * Returns the base type byte (IS_LONG, IS_STRING, ...) of this zval
     */
    public function getBaseType(): int
    {
        $this->assertNotReleased();

        return $this->pointer->u1->v->type;
    }

    /**
     * Returns the shaped value union (zend_value) of this zval
     *
     * @return zend_value
     */
    private function valueUnion(): object
    {
        return $this->pointer->value;
    }

    /**
     * Copies the donor value over this zval slot, taking its own engine reference, and
     * returns a byte-exact snapshot of the previous slot content
     *
     * The snapshot is NOT released here: the caller keeps it so a rollback can restore
     * the slot verbatim, and releases it (destroy()) once the swap is committed. This is
     * the reference-safe primitive the hot-swap delta uses to replace default property,
     * static and constant values in place.
     */
    public function replaceWith(ReflectionValue $donor): ReflectionValue
    {
        $this->assertNotReleased();
        $zvalSize = Core::sizeof(Core::type('zval'));
        $snapshot = Core::new('zval');
        Core::memcpy($snapshot, $this->pointer, $zvalSize);
        Core::memcpy($this->pointer, $donor->getRawValue(), $zvalSize);
        // The slot now holds its own reference on the (possibly shared) payload
        Core::call('zval_add_ref', $this->zvalPointer());

        return self::fromValueEntry($snapshot);
    }

    /**
     * Restores this zval slot from a snapshot taken by replaceWith(), releasing whatever
     * the slot holds now (rollback path)
     */
    public function restoreFrom(ReflectionValue $snapshot): void
    {
        $this->assertNotReleased();
        Core::call('zval_ptr_dtor', $this->zvalPointer());
        Core::memcpy($this->pointer, $snapshot->getRawValue(), Core::sizeof(Core::type('zval')));
    }

    /**
     * Releases the engine reference this slot holds with full engine semantics
     *
     * Used to drop a snapshot after a committed swap; interned/immutable payloads stay
     * untouched, refcounted payloads go through zval_ptr_dtor.
     */
    public function destroy(): void
    {
        $this->assertNotReleased();
        Core::call('zval_ptr_dtor', $this->zvalPointer());
    }

    /**
     * Takes one engine reference on the payload of this zval (ZVAL_COPY-style addref)
     *
     * Unlike acquireReference() this does not flip the wrapper's ownership bit - it is a
     * bare engine addref for slots the caller manages by hand (adopted constant values).
     */
    public function addReference(): void
    {
        $this->assertNotReleased();
        Core::call('zval_add_ref', $this->zvalPointer());
    }

    /**
     * Returns a zval POINTER for engine calls, whether this wrapper holds an embedded
     * zval struct (a table slot / constant value) or a zval pointer (a container)
     */
    /**
     * @return CData|zval
     */
    private function zvalPointer(): object
    {
        if (Core::sizeof($this->pointer) === Core::sizeOfType(zval::class)) {
            return Core::addr($this->pointer);
        }

        return $this->pointer;
    }

    /**
     * Takes an own reference on the payload, making this wrapper responsible for one release
     *
     * Required when the zval is handed to an engine function with ownership semantics (one
     * that may release or replace the value inside, eg zend_prepare_string_for_scanning):
     * a bare aliasing container would make the engine release a reference nobody gave it.
     */
    public function acquireReference(): self
    {
        $this->assertNotReleased();
        if (!$this->ownsReference && $this->isTypeInfoRefCounted($this->getType())) {
            Core::call('zval_add_ref', $this->pointer);
            $this->ownsReference = true;
        }

        return $this;
    }

    /**
     * Records that this wrapper now owns the reference sitting in its zval, without taking
     * a new one
     *
     * Used after an engine call replaces the payload in place with a FRESH refcounted value
     * the wrapper must release exactly once - eg zend_prepare_string_for_scanning(), which
     * swaps the source string for a padded scanner copy (a brand new rc=1 zend_string).
     * Unlike acquireReference() this adds no reference: the engine already produced the
     * fresh value. It exists because acquireReference() leaves ownsReference false when the
     * ORIGINAL payload was interned (non-refcounted), which would leak the fresh copy the
     * engine put in its place.
     */
    public function claimReference(): self
    {
        $this->assertNotReleased();
        assert($this->isTypeInfoRefCounted($this->getType()));
        $this->ownsReference = true;

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function doRelease(bool $ownsReference, bool $ownsContainer): void
    {
        if ($ownsReference) {
            // Full engine release semantics for the payload reference this wrapper held
            Core::call('zval_ptr_dtor', $this->pointer);
        }
        if ($ownsContainer) {
            Core::free($this->pointer);
        }
    }

    /**
     * Performs copying of current value to another one
     *
     * @param CData|zval $dstZval Address to copy value to
     *
     *@see zend_types.h:ZVAL_COPY(z, v) macro
     */
    public function copy(object $dstZval): void
    {
        $this->assertNotReleased();
        /** @var zval $dst Narrowed to the stub view (the struct and pointer forms share it) */
        $dst      = $dstZval;
        $typeInfo = $this->getType();
        $gc       = $this->pointer->value->counted;

        // Content of ZVAL_COPY_VALUE_EX
        if (PHP_INT_SIZE === 4) {                       // if SIZEOF_SIZE_T == 4
            $w2                  = $this->pointer->value->ww->w2;        // uint32_t _w2 = v->value.ww.w2;
            $dst->value->counted = $gc;                 // Z_COUNTED_P(z) = gc;
            $dst->value->ww->w2  = $w2;                 // z->value.ww.w2 = _w2;
            $dst->u1->type_info  = $typeInfo;           // Z_TYPE_INFO_P(z) = t;
        } elseif (PHP_INT_SIZE === 8) {
            $dst->value->counted = $gc;                 // Z_COUNTED_P(z) = gc;
            $dst->u1->type_info  = $typeInfo;           // Z_TYPE_INFO_P(z) = t;
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
            'value' => $nativeValue,
        ];
    }

    /**
     * @inheritDoc
     *
     * @return zend_refcounted_h
     */
    protected function getGC(): object
    {
        if (!$this->isTypeInfoRefCounted($this->getType())) {
            throw new \LogicException(
                'Cannot access the reference counter of a non-refcounted value of type '
                . self::name($this->pointer->u1->v->type),
            );
        }

        $counted = $this->pointer->value->counted;
        assert($counted !== null);

        return $counted->gc;
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
