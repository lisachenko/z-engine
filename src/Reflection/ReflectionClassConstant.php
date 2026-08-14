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
use ReflectionClassConstant as NativeReflectionClassConstant;
use ZEngine\Core;
use ZEngine\Generated\zend_class_constant;
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
    use AccessFlagsTrait;

    /**
     * @var zend_class_constant Typed view of the wrapped constant entry; the runtime value
     *                          is the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

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
        $this->pointer   = Core::cast(zend_class_constant::class, $constantPointer);
    }

    /**
     * Creates a reflection from the zend_class_constant structure
     *
     * @param CData|zend_class_constant $constantEntry Pointer to the structure
     *
     * @return ReflectionClassConstant
     */
    public static function fromCData(object $constantEntry, string $constantName): ReflectionClassConstant
    {
        /** @var ReflectionClassConstant $reflectionConstant */
        $reflectionConstant = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_class_constant $constantEntry Narrowed to the stub view at the owning boundary */
        $classEntry = $constantEntry->ce;
        assert($classEntry !== null);
        $classNamePointer = $classEntry->name;
        assert($classNamePointer !== null);
        Core::callParentConstructor(
            $reflectionConstant,
            static::class,
            StringEntry::fromCData($classNamePointer)->getStringValue(),
            $constantName,
        );
        $reflectionConstant->pointer = $constantEntry;

        return $reflectionConstant;
    }

    /**
     * Creates a low-level reflection over a raw zend_class_constant structure
     *
     * Unlike fromCData() this does NOT initialize the native reflection state, so it
     * works for constants of classes that are not published under their own name (eg
     * hot-swap donor entries residing only as structures in memory). The pointer-level
     * API (equals(), getReflectionValue(), getRawValue(), declaring-class access) is
     * usable, native introspection (getName()/getValue()) is not.
     *
     * @internal used by the hot-swap machinery (ClassDelta)
     *
     * @param CData|zend_class_constant $constantEntry
     */
    public static function fromRawEntry(object $constantEntry): ReflectionClassConstant
    {
        /** @var ReflectionClassConstant $reflectionConstant */
        $reflectionConstant = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_class_constant $constantEntry Narrowed at the boundary (may be a struct or a pointer view) */
        $reflectionConstant->pointer = $constantEntry;

        return $reflectionConstant;
    }

    /**
     * Returns the raw zend_class_constant pointer this reflection wraps
     *
     * The pointer is a live view into engine memory; prefer the typed accessors
     * (getReflectionValue(), equals(), getDeclaringClass()) over poking fields.
     *
     * @return zend_class_constant
     */
    public function getRawValue(): object
    {
        return $this->pointer;
    }

    /**
     * Structurally compares this constant with another one for the hot-swap delta
     *
     * Equal means the same access flags (visibility, final) AND a structurally equal
     * value (ReflectionValue::equals(), a conservative scalar comparison). This is not
     * a full identity check - it is exactly what the delta needs to decide whether a
     * constant's stored value changed.
     */
    public function equals(ReflectionClassConstant $other): bool
    {
        if ($this->getAccessFlags() !== $other->getAccessFlags()) {
            return false;
        }

        return $this->getReflectionValue()->equals($other->getReflectionValue());
    }

    /**
     * Returns the packed access-flags word (visibility + final) of this constant
     */
    public function getAccessFlags(): int
    {
        return $this->pointer->value->u2->constant_flags;
    }

    /**
     * Mints an immortal zend_class_constant container adopting this (donor) constant,
     * rebased onto the given target class
     *
     * The container is a malloc-backed tracked block that owns its own payload
     * references (value, doc comment, attributes): the engine releases them when the
     * target class is destroyed, and it never frees the container itself (it assumes
     * arena storage). Publish the returned reflection into the class constants table
     * with getRawValue(); undo with releaseContainer().
     *
     * @internal used by the hot-swap machinery (ClassDelta "added constant")
     */
    public function adoptForClass(ReflectionClass $target): ReflectionClassConstant
    {
        $container = Core::trackedNew('zend_class_constant', true);
        Core::memcpy($container, $this->pointer, Core::sizeof($container));
        // The engine releases the payload of constants whose ce matches the class
        // being destroyed - the adopted constant belongs to the target class now
        $container->ce = $target->getRawValue();

        $adopted = self::fromRawEntry($container);
        // The container owns its own payload references
        $adopted->getReflectionValue()->addReference();
        $docComment = $container->doc_comment;
        if ($docComment !== null) {
            assert($docComment instanceof CData);
            StringEntry::fromCData($docComment)->copy();
        }
        $adopted->referenceAttributes(1);

        return $adopted;
    }

    /**
     * Releases everything an adopted container took and frees the container block
     *
     * @internal rollback of adoptForClass()
     */
    public function releaseContainer(): void
    {
        $this->getReflectionValue()->destroy();
        $docComment = $this->pointer->doc_comment;
        if ($docComment !== null) {
            assert($docComment instanceof CData);
            StringEntry::fromCData($docComment)->releaseReference();
        }
        $this->referenceAttributes(-1);
        Core::untrackAndFree(Core::addr($this->pointer));
    }

    /**
     * Adjusts the refcount of the attributes table by the given delta (no-op when the
     * constant has no attributes or the table is immutable)
     */
    private function referenceAttributes(int $delta): void
    {
        $attributes = $this->pointer->attributes;
        if ($attributes === null) {
            return;
        }
        assert($attributes instanceof CData);
        $attributesTable = HashTable::fromCData($attributes);
        if ($attributesTable->isImmutable()) {
            return;
        }
        if ($delta > 0) {
            $attributesTable->incrementReferenceCount();
        } else {
            $attributesTable->decrementReferenceCount();
        }
    }

    /**
     * A class constant keeps its access flags in the u2 union of its own zval value
     *
     * @see AccessFlagsTrait for setPublic()/setProtected()/setPrivate()
     */
    protected function replaceAccessFlags(int $clearMask, int $setMask): void
    {
        $flagsHolder = $this->pointer->value->u2;
        assert($flagsHolder instanceof CData);
        $flags = $flagsHolder->constant_flags;
        assert(is_int($flags));

        $flagsHolder->constant_flags = ($flags & (~$clearMask)) | $setMask;
    }

    /**
     * Gets the declaring class
     */
    #[\Override]
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
