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
        $classEntry      = $classEntryValue->getRawClass();
        $constantsTable  = new HashTable(Core::addr($classEntry->constants_table));

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
        call_user_func(
            [$reflectionConstant, 'parent::__construct'],
            $className->getStringValue(),
            $constantName
        );
        $reflectionConstant->pointer = $constantEntry;

        return $reflectionConstant;
    }

    private function setPermission(int $level): void
    {
        if(version_compare(PHP_VERSION, "8.1.0", "<")) {
            $this->pointer->value->u2->access_flags &= (~Core::ZEND_ACC_PPP_MASK);
            $this->pointer->value->u2->access_flags |= $level;
        } else {
            $this->pointer->value->u2->constant_flags &= (~Core::ZEND_ACC_PPP_MASK);
            $this->pointer->value->u2->constant_flags |= $level;
        }
    }

    /**
     * Declares constant as public
     */
    public function setPublic(): void
    {
        $this->setPermission(Core::ZEND_ACC_PUBLIC);
    }

    /**
     * Declares constant as protected
     */
    public function setProtected(): void
    {
        $this->setPermission(Core::ZEND_ACC_PROTECTED);
    }

    /**
     * Declares constant as private
     */
    public function setPrivate(): void
    {
        $this->setPermission(Core::ZEND_ACC_PRIVATE);
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
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'   => $this->getName(),
            'class'  => $this->getDeclaringClass()->getName(),
        ];
    }
}
