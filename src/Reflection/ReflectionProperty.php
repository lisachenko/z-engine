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
 *     zend_class_entry *ce;
 *     zend_type type;
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
        $propertiesTable = new HashTable(Core::addr($classEntry->properties_info));

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
        call_user_func(
            [$reflectionProperty, 'parent::__construct'],
            $propertyName->getStringValue()
        );
        $reflectionProperty->pointer = $propertyEntry;

        return $reflectionProperty;
    }

    /**
     * Returns an offset of this property
     */
    public function getOffset(): int
    {
        return $this->pointer->offset;
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
            'class'  => $this->getDeclaringClass()->getName()
        ];
    }
}
