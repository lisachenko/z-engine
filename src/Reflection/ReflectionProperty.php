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
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

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
    private CData $pointer;

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
        $this->pointer   = Core::cast('zend_property_info *', $propertyPointer);
    }

    /**
     * Creates a reflection from the zend_property_info structure
     *
     * @param CData $propertyEntry Pointer to the structure
     */
    public static function fromCData(CData $propertyEntry): ReflectionProperty
    {
        /** @var ReflectionProperty $reflectionProperty */
        $reflectionProperty = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $propertyName       = StringEntry::fromCData($propertyEntry->name);
        Core::callParentConstructor(
            $reflectionProperty,
            static::class,
            $propertyName->getStringValue(),
        );
        $reflectionProperty->pointer = $propertyEntry;

        return $reflectionProperty;
    }

    /**
     * Returns the shaped view of a raw zend_property_info structure
     *
     * The declared shape (see phpstan.dist.neon typeAliases and AGENTS.md) is the
     * single narrowing point for property-info field access. This is a static view:
     * property infos of classes that are not published under their own name (eg
     * hot-swap donor entries) cannot be wrapped through fromCData(), which resolves
     * the native reflection state by name.
     *
     * @param CData $propertyInfo zend_property_info pointer
     *
     * @return ZendPropertyInfoShape
     *
     * @internal shared with the hot-swap machinery (HotSwap/ClassDelta)
     */
    public static function viewPropertyInfo(CData $propertyInfo): object
    {
        /** @var ZendPropertyInfoShape $shapedInfo */
        $shapedInfo = self::asStructView($propertyInfo);

        return $shapedInfo;
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
     * Returns an offset of this property
     */
    public function getOffset(): int
    {
        return $this->pointer->offset;
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
        $hookFunction = $hooks[$kind];
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
     * Declares property as public
     */
    public function setPublic(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PUBLIC;
    }

    /**
     * Declares property as protected
     */
    public function setProtected(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PROTECTED;
    }

    /**
     * Declares property as private
     */
    public function setPrivate(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PRIVATE;
    }

    /**
     * Declares property as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        if ($isStatic) {
            $this->pointer->flags |= Core::ZEND_ACC_STATIC;
        } else {
            $this->pointer->flags &= (~Core::ZEND_ACC_STATIC);
        }
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
