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

namespace ZEngine\Reflection;

use FFI\CData;
use ReflectionProperty as NativeReflectionProperty;
use ZEngine\Core;
use ZEngine\Generated\zend_property_info;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * Class ReflectionProperty
 *
 * typedef struct _zend_property_info {
 *     uint32_t offset; // property offset for object properties or property index for static properties
 *     uint32_t flags;
 *     zend_string *name;
 *     zend_string *doc_comment;
 *     HashTable *attributes;
 *     zend_class_entry *ce;
 *     zend_type type;
 *     const zend_property_info *prototype;
 *     zend_function **hooks;
 * } zend_property_info;
 */
class ReflectionProperty extends NativeReflectionProperty
{
    use AccessFlagsTrait;

    /**
     * @var zend_property_info Typed view of the wrapped property info; the runtime value
     *                         is the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

    public function __construct(string $className, string $propertyName)
    {
        parent::__construct($className, $propertyName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry      = $classEntryValue->getRawClass();
        $propertiesTable = HashTable::fromCData(Core::addr($classEntry->properties_info));

        $propertyEntry = $propertiesTable->find(strtolower($propertyName));
        if ($propertyEntry === null) {
            throw new \ReflectionException("Property {$propertyName} was not found in the class.");
        }
        $propertyPointer = $propertyEntry->getRawPointer();
        $this->pointer   = Core::cast(zend_property_info::class, $propertyPointer);
    }

    /**
     * Creates a reflection from the zend_property_info structure
     *
     * @param CData|zend_property_info $propertyEntry Pointer to the structure
     */
    public static function fromCData(object $propertyEntry): ReflectionProperty
    {
        /** @var ReflectionProperty $reflectionProperty */
        $reflectionProperty = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_property_info $propertyEntry Narrowed to the stub view at the owning boundary */
        $propertyNamePointer = $propertyEntry->name;
        assert($propertyNamePointer !== null);
        Core::callParentConstructor(
            $reflectionProperty,
            static::class,
            StringEntry::fromCData($propertyNamePointer)->getStringValue(),
        );
        $reflectionProperty->pointer = $propertyEntry;

        return $reflectionProperty;
    }

    /**
     * Creates a low-level reflection over a raw zend_property_info structure
     *
     * Unlike fromCData() this does NOT initialize the native reflection state, so it
     * works for property infos of classes that are not published under their own name
     * (eg hot-swap donor entries residing only as structures in memory). Only the
     * pointer-level API (getOffset()/getFlags()/getTypeMask()/getSurface()) is usable,
     * native introspection is not.
     *
     * @internal used by the hot-swap machinery (HotSwap/ClassDelta)
     *
     * @param CData|zend_property_info $propertyInfo
     */
    public static function fromRawEntry(object $propertyInfo): ReflectionProperty
    {
        /** @var ReflectionProperty $reflectionProperty */
        $reflectionProperty = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_property_info $propertyInfo Narrowed at the boundary (may be a struct or a pointer view) */
        $reflectionProperty->pointer = $propertyInfo;

        return $reflectionProperty;
    }

    /**
     * Returns the storage offset of this property within the object/static table
     */
    public function getOffset(): int
    {
        $offset = $this->pointer->offset;
        assert(is_int($offset));

        return $offset;
    }

    /**
     * Returns the ZEND_ACC_* flags word of this property declaration
     */
    public function getFlags(): int
    {
        $flags = $this->pointer->flags;
        assert(is_int($flags));

        return $flags;
    }

    /**
     * Returns the type mask (zend_type.type_mask) of this property's declared type
     */
    public function getTypeMask(): int
    {
        $type = $this->pointer->type;
        assert($type instanceof CData);
        $typeMask = $type->type_mask;
        assert(is_int($typeMask));

        return $typeMask;
    }

    /**
     * Checks if this property is declared static
     */
    public function isStatic(): bool
    {
        return ($this->getFlags() & Core::ZEND_ACC_STATIC) !== 0;
    }

    /**
     * Checks if this property is virtual (hooked, no backing storage slot)
     */
    public function isVirtual(): bool
    {
        return ($this->getFlags() & Core::ZEND_ACC_VIRTUAL) !== 0;
    }

    /**
     * Returns the comparable declaration shape (flags, storage offset, type mask)
     *
     * Two properties with equal surfaces occupy the same slot layout, which is what the
     * hot-swap compatibility guard checks before allowing a default-value-only swap.
     *
     * @return array{int, int, int}
     */
    public function getSurface(): array
    {
        return [$this->getFlags(), $this->getOffset(), $this->getTypeMask()];
    }

    /**
     * Checks if this property declares at least one property hook (PHP 8.4+)
     */
    public function hasHooks(): bool
    {
        return $this->pointer->hooks !== null;
    }

    /**
     * Returns the engine-level reflection of one property hook or null if that hook is not declared
     *
     * The hooks array of zend_property_info is indexed by the hook kind; the wrapped
     * zend_function is the real hook body compiled into the class (also published in the
     * class function table under the mangled "$prop::get"/"$prop::set" name).
     *
     * The parameter is intentionally wider than the native getHook(): both the native
     * PropertyHookType enum and the raw engine kind (Core::ZEND_PROPERTY_HOOK_GET or
     * Core::ZEND_PROPERTY_HOOK_SET, the index into zend_property_info.hooks) are accepted.
     *
     * @param \PropertyHookType|int $kind Hook kind
     */
    public function getHook(\PropertyHookType|int $kind): ?ReflectionMethod
    {
        if ($kind instanceof \PropertyHookType) {
            $kind = $kind === \PropertyHookType::Get
                ? Core::ZEND_PROPERTY_HOOK_GET
                : Core::ZEND_PROPERTY_HOOK_SET;
        }
        if ($kind !== Core::ZEND_PROPERTY_HOOK_GET && $kind !== Core::ZEND_PROPERTY_HOOK_SET) {
            throw new \InvalidArgumentException(
                'Hook kind must be Core::ZEND_PROPERTY_HOOK_GET or Core::ZEND_PROPERTY_HOOK_SET',
            );
        }
        $hooks = $this->pointer->hooks;
        if ($hooks === null) {
            return null;
        }
        assert($hooks instanceof CData);
        // The hooks block is a fixed-size zend_function * array indexed by hook kind, so it
        // is read through the bounds-checked view rather than by raw pointer index
        $hookTable    = new StructArray($hooks, Core::ZEND_PROPERTY_HOOK_COUNT);
        $hookFunction = $hookTable[$kind];
        if ($hookFunction === null) {
            return null;
        }
        assert($hookFunction instanceof CData);

        return ReflectionMethod::fromHookCData($hookFunction);
    }

    /**
     * Returns the engine attributes table of this property or null if the property has no attributes
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
     * Declares property as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        $this->setAccessFlag(Core::ZEND_ACC_STATIC, $isStatic);
    }

    /**
     * A property keeps its access flags in the flags field of its zend_property_info
     *
     * @see AccessFlagsTrait for setPublic()/setProtected()/setPrivate()
     */
    protected function replaceAccessFlags(int $clearMask, int $setMask): void
    {
        $flags = $this->pointer->flags;
        assert(is_int($flags));

        $this->pointer->flags = ($flags & (~$clearMask)) | $setMask;
    }

    /**
     * Gets the declaring class
     */
    public function getDeclaringClass(): ReflectionClass
    {
        $classEntry = $this->pointer->ce;
        assert($classEntry instanceof CData);

        return ReflectionClass::fromCData($classEntry);
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
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'   => $this->getName(),
            'offset' => $this->getOffset(),
            'type'   => $this->getType(),
            'class'  => $this->getDeclaringClass()->getName(),
        ];
    }
}
