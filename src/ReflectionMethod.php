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

namespace ZEngine;

use FFI;
use FFI\CData;
use ReflectionMethod as NativeReflectionMethod;

class ReflectionMethod extends NativeReflectionMethod
{
    private CData $pointer;

    public function __construct(string $className, string $methodName)
    {
        parent::__construct($className, $methodName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry  = $classEntryValue->getRawData()->ce;
        $methodTable = new HashTable(FFI::addr($classEntry->function_table));

        $methodEntryValue = $methodTable->find(strtolower($methodName));
        if ($methodEntryValue === null) {
            throw new \ReflectionException("Method {$methodName} was not found in the class.");
        }
        $this->pointer = $methodEntryValue->getRawData()->func;
    }

    /**
     * Creates a reflection from the zend_function structure
     *
     * @param CData $functionEntry Pointer to the structure
     *
     * @return ReflectionMethod
     */
    public static function fromFunctionEntry(CData $functionEntry): ReflectionMethod
    {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $functionName     = new StringEntry($functionEntry->common->function_name);
        $scopeName        = new StringEntry($functionEntry->common->scope->name);
        call_user_func([$reflectionMethod, 'parent::__construct'], (string) $scopeName, (string) $functionName);
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
     * Declares method as deprecated/non-deprecated
     */
    public function setDeprecated(bool $isDeprecated = true): void
    {
        if ($isDeprecated) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_DEPRECATED);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_DEPRECATED));
        }
    }

    /**
     * Declares method as variadic/non-variadic
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setVariadic(bool $isVariadic = true): void
    {
        if ($isVariadic) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_VARIADIC);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_VARIADIC));
        }
    }

    /**
     * Declares method as generator/non-generator
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Low-level API, can bring a segmentation fault
     * @internal
     */
    public function setGenerator(bool $isGenerator = true): void
    {
        if ($isGenerator) {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags | Core::ZEND_ACC_GENERATOR);
        } else {
            $this->pointer->common->fn_flags = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_GENERATOR));
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

        return ReflectionClass::fromClassEntry($this->pointer->common->scope);
    }

    /**
     * Returns the method prototype or null if no prototype for this method
     */
    public function getPrototype(): ?ReflectionMethod
    {
        if ($this->pointer->common->prototype === null) {
            return null;
        }

        return static::fromFunctionEntry($this->pointer->common->prototype);
    }

    /**
     * Redefines an existing method in the class with closure
     */
    public function redefine(\Closure $newCode): void
    {
        $this->ensureCompatibleClosure($newCode);

        $selfExecutionState = Core::$executor->getExecutionState();
        $newCodeEntry       = $selfExecutionState->getArgument(0)->getRawData()->obj;
        $newCodeEntry       = Core::cast('zend_closure *', $newCodeEntry);
        FFI::memcpy($this->pointer, FFI::addr($newCodeEntry->func), FFI::sizeof($newCodeEntry->func));
    }

    /**
     * Returns the iterable generator of opcodes for this function
     *
     * @return iterable|OpCodeLine[]
     */
    public function getOpCodes(): iterable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Opcodes are available only for user-defined functions');
        }
        $opcodeEntryGenerator = function () {
            $opcodeIndex  = 0;
            $totalOpcodes = $this->pointer->op_array->last;
            while ($opcodeIndex < $totalOpcodes) {
                $opCode = new OpCodeLine(
                    FFI::addr($this->pointer->op_array->opcodes[$opcodeIndex++])
                );
                yield $opCode;
            }
        };

        return $opcodeEntryGenerator();
    }

    /**
     * Returns the total number of literals
     */
    public function getNumberOfLiterals(): int
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $lastLiteral = $this->pointer->op_array->last_literal;

        return $lastLiteral;
    }

    /**
     * Returns one single literal's value by it's index
     *
     * @param int $index
     *
     * @return ReflectionValue
     */
    public function getLiteral(int $index): ReflectionValue
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $lastLiteral = $this->pointer->op_array->last_literal;
        if ($index > $lastLiteral) {
            throw new \OutOfBoundsException("Literal index {$index} is out of bounds, last is {$lastLiteral}");
        }
        $literal = $this->pointer->op_array->literals[$index];

        return ReflectionValue::fromValueEntry($literal);
    }

    /**
     * Returns list of literals, associated with this entry
     *
     * @return ReflectionValue[]
     */
    public function getLiterals(): iterable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $literalValueGenerator = function () {
            $literalIndex  = 0;
            $totalLiterals = $this->pointer->op_array->last_literal;
            while ($literalIndex < $totalLiterals) {
                $item = $this->pointer->op_array->literals[$literalIndex];
                $literalIndex++;
                yield ReflectionValue::fromValueEntry($item);
            }
        };

        return $literalValueGenerator();
    }

    public function __debugInfo()
    {
        return [
            'class' => $this->getDeclaringClass()->getName(),
            'name'  => $this->getName()
        ];
    }

    /**
     * Checks if the given closure signature is compatible to original one (number of arguments, type hints, etc)
     *
     * @throws \ReflectionException if closure signature is not compatible with current function/method
     */
    private function ensureCompatibleClosure(\Closure $newCode): void
    {
        /** @var \ReflectionFunction[] $reflectionPair */
        $reflectionPair = [$this, new \ReflectionFunction($newCode)];
        $signatures     = [];
        foreach ($reflectionPair as $index => $reflectionFunction) {
            $signature = 'function ';
            if ($reflectionFunction->returnsReference()) {
                $signature .= '&';
            }
            $signature .= '(';
            $parameters = [];
            foreach ($reflectionFunction->getParameters() as $reflectionParameter) {
                $parameter = '';
                if ($reflectionParameter->hasType()) {
                    $type = $reflectionParameter->getType();
                    if ($type->allowsNull()) {
                        $parameter .= '?';
                    }
                    $parameter .= $type->getName() . ' ';
                }
                if ($reflectionParameter->isPassedByReference()) {
                    $parameter .= '&';
                }
                if ($reflectionParameter->isVariadic()) {
                    $parameter .= '...';
                }
                $parameter .= '$';
                $parameter .= $reflectionParameter->getName();
                $parameters[] = $parameter;
            }
            $signature .= join(', ', $parameters);
            $signature .= ')';
            if ($reflectionFunction->hasReturnType()) {
                $signature .= ': ';
                $type       = $reflectionFunction->getReturnType();
                if ($type->allowsNull()) {
                    $signature .= '?';
                }
                $signature .= $type->getName();
            }
            $signatures[] = $signature;
        }

        if ($signatures[0] !== $signatures[1]) {
            throw new \ReflectionException(
                'Given function signature: "' . $signatures[1] . '"' .
                ' should be compatible with original "' . $signatures[0] . '"'
            );
        }
    }
}
