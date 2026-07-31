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
use ReflectionMethod as NativeReflectionMethod;
use ZEngine\Core;
use ZEngine\Type\ClosureEntry;
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
        $methodTable = new HashTable(Core::addr($classEntry->function_table));

        $methodEntryValue = $methodTable->find(strtolower($methodName));
        if ($methodEntryValue === null) {
            throw new \ReflectionException("Method {$methodName} was not found in the class.");
        }
        $this->pointer = $methodEntryValue->getRawFunction();
    }

    /**
     * Creates a reflection from the zend_function/zend_internal_function structure
     *
     * Engine-managed __call trampolines (ZEND_ACC_CALL_VIA_TRAMPOLINE) are not registered
     * in any method table, so the native reflection state cannot be initialized for them:
     * the returned wrapper is low-level only (like fromClosureEntry() results) and is meant
     * to round-trip through the hook APIs, not to be introspected natively.
     *
     * @param CData $functionEntry Pointer to the structure
     *
     * @return ReflectionMethod
     */
    public static function fromCData(CData $functionEntry): ReflectionMethod
    {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $isTrampoline     = false;
        if ($functionEntry->type !== Core::ZEND_INTERNAL_FUNCTION) {
            $commonPointer = $functionEntry->common;
            assert($commonPointer instanceof CData);
            $functionNamePtr = $commonPointer->function_name;
            $scopeNamePtr    = $commonPointer->scope->name;
            $functionFlags   = $commonPointer->fn_flags;
            assert(is_int($functionFlags));
            $isTrampoline = ($functionFlags & Core::ZEND_ACC_CALL_VIA_TRAMPOLINE) !== 0;
        } else {
            $functionNamePtr = $functionEntry->function_name;
            $scopeNamePtr    = $functionEntry->scope->name;
        }

        if (!$isTrampoline) {
            $scopeName    = StringEntry::fromCData($scopeNamePtr);
            $functionName = StringEntry::fromCData($functionNamePtr);
            Core::callParentConstructor(
                $reflectionMethod,
                static::class,
                $scopeName->getStringValue(),
                $functionName->getStringValue(),
            );
        }
        $reflectionMethod->pointer = $functionEntry;

        return $reflectionMethod;
    }

    /**
     * Creates a reflection for a property hook function (PHP 8.4+)
     *
     * Property hook bodies are real zend_function entries, but the engine does not publish
     * them in the class function table - they are only reachable via zend_property_info.hooks.
     * The native ReflectionMethod constructor resolves methods through the function table, so
     * the hook is published there under its mangled name ("$prop::get"/"$prop::set") just for
     * the duration of the native construction and then unpublished again. The transient entry
     * is removed with the table destructor disabled, so the hook function itself is untouched.
     *
     * @param CData $functionEntry Pointer to the hook zend_function structure
     */
    public static function fromHookCData(CData $functionEntry): ReflectionMethod
    {
        if ($functionEntry->type === Core::ZEND_INTERNAL_FUNCTION) {
            $functionEntry = Core::cast('zend_internal_function *', $functionEntry);
            $commonPointer = $functionEntry;
        } else {
            $commonPointer = $functionEntry->common;
        }
        assert($commonPointer instanceof CData);
        $functionNamePtr = $commonPointer->function_name;
        assert($functionNamePtr instanceof CData);
        $lowerName = strtolower(StringEntry::fromCData($functionNamePtr)->getStringValue());

        $scope = $commonPointer->scope;
        assert($scope instanceof CData);
        $functionTable = Core::addr($scope->function_table);
        $methodTable   = new HashTable($functionTable);
        if ($methodTable->find($lowerName) !== null) {
            // Already published (eg by a future engine version) - use the regular path
            return static::fromCData($functionEntry);
        }

        // The temporary container is released right after the engine copied it into a bucket
        $rawFunction = Core::cast('zend_function *', $functionEntry)[0];
        assert($rawFunction instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $methodTable->add($lowerName, $valueEntry);
        $valueEntry->release();
        try {
            return static::fromCData($functionEntry);
        } finally {
            // Unpublish the transient entry without destroying the hook function: the table
            // destructor (zend_function_dtor) is disabled around the delete, so the bucket
            // removal releases nothing - the hook stays owned by zend_property_info.hooks
            $previousDestructor         = $functionTable->pDestructor;
            $functionTable->pDestructor = null;
            $methodTable->delete($lowerName);
            $functionTable->pDestructor = $previousDestructor;
        }
    }

    /**
     * Binds the zend_function embedded into a closure to the given class as a method
     *
     * All function surgery goes through the wrapper API: the entry is renamed, attached to
     * the class scope and stripped of its ZEND_ACC_CLOSURE flag. The caller stays responsible
     * for the closure lifetime (the embedded zend_function lives inside the closure object)
     * and for publishing the function in the class method table.
     *
     * Note: the native reflection state of the returned wrapper is not initialized (the
     * method is not registered in the class yet), so only the low-level API is usable on it.
     * Re-wrap the function after publishing to get a fully functional reflection, like
     * ReflectionClass::addMethod() does.
     *
     * @internal
     */
    public static function fromClosureEntry(
        ClosureEntry $closureEntry,
        string $className,
        string $methodName,
    ): ReflectionMethod {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod          = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionMethod->pointer = Core::cast('zend_function *', Core::addr($closureEntry->getRawFunction()));

        $reflectionMethod->setFunctionName($methodName);
        $reflectionMethod->setDeclaringClass($className);
        $reflectionMethod->setClosureFlag(false);

        return $reflectionMethod;
    }

    /**
     * Declares function as final/non-final
     */
    public function setFinal(bool $isFinal = true): void
    {
        if ($isFinal) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_FINAL;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_FINAL);
        }
    }

    /**
     * Declares function as abstract/non-abstract
     */
    public function setAbstract(bool $isAbstract = true): void
    {
        if ($isAbstract) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_ABSTRACT;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_ABSTRACT);
        }
    }

    /**
     * Declares method as public
     */
    public function setPublic(): void
    {
        $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_PUBLIC;
    }

    /**
     * Declares method as protected
     */
    public function setProtected(): void
    {
        $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_PROTECTED;
    }

    /**
     * Declares method as private
     */
    public function setPrivate(): void
    {
        $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_PRIVATE;
    }

    /**
     * Declares method as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        if ($isStatic) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_STATIC;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_STATIC);
        }
    }

    /**
     * Gets the declaring class
     *
     * @throws \InvalidArgumentException If scope is not available
     */
    public function getDeclaringClass(): ReflectionClass
    {
        if ($this->getCommonPointer()->scope === null) {
            throw new \InvalidArgumentException('Not in a class scope');
        }

        return ReflectionClass::fromCData($this->getCommonPointer()->scope);
    }

    /**
     * Changes the declaring class name for this method
     *
     * @param string $className New class name for this method
     * @internal
     */
    public function setDeclaringClass(string $className): void
    {
        $lcName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($lcName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} was not found");
        }
        $this->getCommonPointer()->scope = $classEntryValue->getRawClass();
    }

    /**
     * Returns the method prototype or null if no prototype for this method
     */
    #[\ReturnTypeWillChange]
    public function getPrototype(): ?ReflectionMethod
    {
        if ($this->getCommonPointer()->prototype === null) {
            return null;
        }

        return static::fromCData($this->getCommonPointer()->prototype);
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

    /**
     * Returns the hash key for function or method
     */
    protected function getHash(): string
    {
        return $this->class . '::' . $this->name;
    }
}
