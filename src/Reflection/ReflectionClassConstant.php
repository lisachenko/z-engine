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
use ReflectionClassConstant as NativeReflectionClassConstant;
use ZEngine\Core;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

/**
 * Class ReflectionClassConstant
 *
 * typedef struct _zend_class_constant {
 *     zval value; // access flags are stored in reserved: zval.u2.access_flags
 *     zend_string *doc_comment;
 *     HashTable *attributes;
 *     zend_class_entry *ce;
 * } zend_class_constant;
 */
class ReflectionClassConstant extends NativeReflectionClassConstant
{
    private CData $pointer;

    public function __construct(string $className, string $constantName)
    {
        parent::__construct($className, $constantName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry     = $classEntryValue->getRawClass();
        $constantsTable = HashTable::fromCData(Core::addr($classEntry->constants_table));

        $constantEntry = $constantsTable->find($constantName);
        if ($constantEntry === null) {
            throw new \ReflectionException("Constant {$constantName} was not found in the class.");
        }
        $constantPointer = $constantEntry->getRawPointer();
        $this->pointer   = Core::cast('zend_class_constant *', $constantPointer);
    }

    /**
     * Creates a reflection from the zend_class_constant structure
     *
     * @param CData $constantEntry Pointer to the structure
     *
     * @return ReflectionClassConstant
     */
    public static function fromCData(CData $constantEntry, string $constantName): ReflectionClassConstant
    {
        /** @var ReflectionClassConstant $reflectionConstant */
        $reflectionConstant = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $className          = StringEntry::fromCData($constantEntry->ce->name);
        Core::callParentConstructor(
            $reflectionConstant,
            static::class,
            $className->getStringValue(),
            $constantName,
        );
        $reflectionConstant->pointer = $constantEntry;

        return $reflectionConstant;
    }

    /**
     * Returns the shaped view of a raw zend_class_constant structure
     *
     * The declared shape (see phpstan.dist.neon typeAliases and AGENTS.md) is the
     * single narrowing point for class-constant field access. This is a static view:
     * constants of classes that are not published under their own name (eg hot-swap
     * donor entries) cannot be wrapped through fromCData(), which resolves the
     * native reflection state by name.
     *
     * @param CData $constantEntry zend_class_constant pointer
     *
     * @return ZendClassConstantShape
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public static function viewConstantEntry(CData $constantEntry): object
    {
        /** @var ZendClassConstantShape $shapedEntry */
        $shapedEntry = self::asStructView($constantEntry);

        return $shapedEntry;
    }

    /**
     * Widens a CData handle to plain `object` so a shape @var can be declared on it
     *
     * FFI\CData is final: a shape alias (stdClass&object{...}) is not a subtype of
     * the CData native type, so the narrowing must go through the object supertype.
     */
    private static function asStructView(CData $struct): object
    {
        return $struct;
    }

    /**
     * Declares constant as public
     */
    public function setPublic(): void
    {
        $this->pointer->value->u2->constant_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->value->u2->constant_flags |= Core::ZEND_ACC_PUBLIC;
    }

    /**
     * Declares constant as protected
     */
    public function setProtected(): void
    {
        $this->pointer->value->u2->constant_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->value->u2->constant_flags |= Core::ZEND_ACC_PROTECTED;
    }

    /**
     * Declares constant as private
     */
    public function setPrivate(): void
    {
        $this->pointer->value->u2->constant_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->value->u2->constant_flags |= Core::ZEND_ACC_PRIVATE;
    }

    /**
     * Gets the declaring class
     */
    public function getDeclaringClass(): ReflectionClass
    {
        return ReflectionClass::fromCData($this->pointer->ce);
    }

    /**
     * Changes the declaring class name for this property
     *
     * @param string $className New class name for this property
     * @internal
     */
    public function setDeclaringClass(string $className): void
    {
        $lcName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($lcName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} was not found");
        }
        $this->pointer->ce = $classEntryValue->getRawClass();
    }

    /**
     * Returns a reflection value for this constant
     */
    public function getReflectionValue(): ReflectionValue
    {
        return ReflectionValue::fromValueEntry($this->pointer->value);
    }

    /**
     * Returns the engine attributes table of this constant or null if the constant has no attributes
     *
     * Each element of the returned table is an IS_PTR value pointing to a zend_attribute:
     * wrap it with ReflectionAttributeEntry::fromValueEntry() for structured access.
     *
     * @return HashTable|ReflectionValue[]|null
     */
    public function getAttributesTable(): ?HashTable
    {
        $attributes = $this->pointer->attributes;
        if ($attributes === null) {
            return null;
        }
        assert($attributes instanceof CData);

        return HashTable::fromCData($attributes);
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'  => $this->getName(),
            'class' => $this->getDeclaringClass()->getName(),
        ];
    }
}
