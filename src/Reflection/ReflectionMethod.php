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

use FFI;
use FFI\CData;
use ReflectionMethod as NativeReflectionMethod;
use ZEngine\Core;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

class ReflectionMethod extends NativeReflectionMethod
{
    use FunctionLikeTrait;

    public function __construct(string $className, string $methodName)
    {
        parent::__construct($className, $methodName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry  = $classEntryValue->getRawClass();
        $methodTable = new HashTable(FFI::addr($classEntry->function_table));

        $methodEntryValue = $methodTable->find(strtolower($methodName));
        if ($methodEntryValue === null) {
            throw new \ReflectionException("Method {$methodName} was not found in the class.");
        }
        $this->pointer = $methodEntryValue->getRawFunction();
    }

    /**
     * Creates a reflection from the zend_function structure
     *
     * @param CData $functionEntry Pointer to the structure
     *
     * @return ReflectionMethod
     */
    public static function fromCData(CData $functionEntry): ReflectionMethod
    {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $functionName     = StringEntry::fromCData($functionEntry->common->function_name);
        $scopeName        = StringEntry::fromCData($functionEntry->common->scope->name);
        call_user_func(
            [$reflectionMethod, 'parent::__construct'],
            $scopeName->getStringValue(),
            $functionName->getStringValue()
        );
        $reflectionMethod->pointer = $functionEntry;

        return $reflectionMethod;
    }

    /**
     * Declares function as final/non-final
     */
    public function setFinal(bool $isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_FINAL);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_FINAL));
        }
    }

    /**
     * Declares function as abstract/non-abstract
     */
    public function setAbstract(bool $isAbstract = true): void
    {
        if ($isAbstract) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_ABSTRACT);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_ABSTRACT));
        }
    }

    /**
     * Declares method as public
     */
    public function setPublic(): void
    {
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_PPP_MASK));
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_PUBLIC);
    }

    /**
     * Declares method as protected
     */
    public function setProtected(): void
    {
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_PPP_MASK));
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_PROTECTED);
    }

    /**
     * Declares method as private
     */
    public function setPrivate(): void
    {
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_PPP_MASK));
        $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_PRIVATE);
    }

    /**
     * Declares method as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        if ($isStatic) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_STATIC);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_STATIC));
        }
    }

    /**
     * Gets the declaring class
     *
     * @throws \InvalidArgumentException If scope is not available
     */
    public function getDeclaringClass(): ReflectionClass
    {
        if ($this->pointer->common->scope === null) {
            throw new \InvalidArgumentException('Not in a class scope');
        }

        return ReflectionClass::fromCData($this->pointer->common->scope);
    }

    /**
     * Changes the declaring class name for this method
     *
     * @param string $className New class name for this method
     */
    public function setDeclaringClass(string $className): void
    {
        $lcName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($lcName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} was not found");
        }
        $this->pointer->common->scope = $classEntryValue->getRawClass();
    }

    /**
     * Returns the method prototype or null if no prototype for this method
     */
    public function getPrototype(): ?ReflectionMethod
    {
        if ($this->pointer->common->prototype === null) {
            return null;
        }

        return static::fromCData($this->pointer->common->prototype);
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'  => $this->getName(),
            'class' => $this->getDeclaringClass()->getName()
        ];
    }

    /**
     * Returns the hash key for function or method
     */
    protected function getHash(): string
    {
        return $this->class . '::' . $this->name;
    }
}
