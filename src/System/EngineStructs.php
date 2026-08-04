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

namespace ZEngine\System;

use FFI\CData;

/**
 * The single narrowing point between raw FFI handles and typed engine structs
 *
 * FFI materializes struct fields dynamically, so PHPStan sees every CData property
 * as untyped. Instead of re-asserting field types at every call site, the engine
 * structs used by internal helpers are described ONCE as named object shapes
 * (`parameters.typeAliases` in phpstan.dist.neon, see AGENTS.md "Engine structs
 * are typed by shape") and these accessors declare them on their return values.
 * Every shape accessor is a zero-cost identity view: the deliberate `@var` inside
 * each body is the one place where the unverifiable FFI-to-shape transition
 * happens.
 *
 * FFI\CData is final, so the shapes cannot intersect it: keep the RAW handle in
 * scope for FFI hand-offs (Core::addr()/memcpy()/cast(), engine calls) and use
 * the shaped view for field access only. Property names on shaped values are
 * checked by PHPStan against the alias (the config-level CData ignores do not
 * apply to shapes), so a typo against the generated engine.h layout fails the
 * analysis instead of reading garbage memory.
 *
 * @internal
 */
final class EngineStructs
{
    /**
     * This is an utility class, no instances needed
     */
    private function __construct() {}

    /**
     * Widens a CData handle to plain `object` so a shape @var can be declared on it
     *
     * FFI\CData is final: a shape alias (stdClass&object{...}) is not a subtype of
     * the CData native type, so the narrowing must go through the object supertype.
     */
    private static function asObject(CData $struct): object
    {
        return $struct;
    }

    /**
     * Views a pointer as a zend_function entry (user function union view)
     *
     * @return ZendFunctionShape
     */
    public static function functionEntry(CData $functionEntry): object
    {
        /** @var ZendFunctionShape $shaped */
        $shaped = self::asObject($functionEntry);

        return $shaped;
    }

    /**
     * Views a pointer (or embedded struct) as a zend_op_array
     *
     * @return ZendOpArrayShape
     */
    public static function opArray(CData $opArray): object
    {
        /** @var ZendOpArrayShape $shaped */
        $shaped = self::asObject($opArray);

        return $shaped;
    }

    /**
     * Views a pointer as a zend_class_entry
     *
     * @return ZendClassEntryShape
     */
    public static function classEntry(CData $classEntry): object
    {
        /** @var ZendClassEntryShape $shaped */
        $shaped = self::asObject($classEntry);

        return $shaped;
    }

    /**
     * Views a pointer as a zend_class_constant
     *
     * @return ZendClassConstantShape
     */
    public static function classConstant(CData $classConstant): object
    {
        /** @var ZendClassConstantShape $shaped */
        $shaped = self::asObject($classConstant);

        return $shaped;
    }

    /**
     * Views a pointer (or embedded struct) as a zval
     *
     * @return ZvalShape
     */
    public static function zval(CData $valueEntry): object
    {
        /** @var ZvalShape $shaped */
        $shaped = self::asObject($valueEntry);

        return $shaped;
    }

    /**
     * Views a pointer as a zend_property_info
     *
     * @return ZendPropertyInfoShape
     */
    public static function propertyInfo(CData $propertyInfo): object
    {
        /** @var ZendPropertyInfoShape $shaped */
        $shaped = self::asObject($propertyInfo);

        return $shaped;
    }

    /**
     * Views a pointer as an engine HashTable (GC header access)
     *
     * @return ZendHashTableShape
     */
    public static function hashTable(CData $hashTable): object
    {
        /** @var ZendHashTableShape $shaped */
        $shaped = self::asObject($hashTable);

        return $shaped;
    }

    /**
     * Views a pointer as a zend_closure
     *
     * @return ZendClosureShape
     */
    public static function closure(CData $closureEntry): object
    {
        /** @var ZendClosureShape $shaped */
        $shaped = self::asObject($closureEntry);

        return $shaped;
    }

    /**
     * Reads an engine counter cell (eg the uint32_t behind op_array.refcount)
     *
     * Offset reads on CData cannot be expressed as an object shape, so the numeric
     * narrowing for counter dereferences is centralized here instead.
     */
    public static function counterValue(CData $counterPointer): int
    {
        $counterValue = $counterPointer[0];
        assert(is_int($counterValue));

        return $counterValue;
    }

    /**
     * Reads one zval slot of an engine zval table (default properties, literals)
     *
     * The raw handle is returned (wrap it with zval() for field access): slot
     * values travel into FFI primitives that require CData arguments.
     */
    public static function zvalAt(CData $zvalTable, int $index): CData
    {
        $valueEntry = $zvalTable[$index];
        assert($valueEntry instanceof CData);

        return $valueEntry;
    }

    /**
     * Reads one pointer/struct slot of a C array behind a CData handle
     */
    public static function cdataAt(CData $list, int $index): CData
    {
        $item = $list[$index];
        assert($item instanceof CData);

        return $item;
    }
}
