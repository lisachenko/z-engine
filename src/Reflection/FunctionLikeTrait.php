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
use ZEngine\Core;
use ZEngine\Type\OpLine;

trait FunctionLikeTrait
{
    private CData $pointer;

     /**
     * Declares method as deprecated/non-deprecated
     */
    public function setDeprecated(bool $isDeprecated = true): void
    {
        if ($isDeprecated) {
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_DEPRECATED;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_DEPRECATED);
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
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_VARIADIC;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_VARIADIC);
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
            $this->getCommonPointer()->fn_flags |= Core::ZEND_ACC_GENERATOR;
        } else {
            $this->getCommonPointer()->fn_flags &= (~Core::ZEND_ACC_GENERATOR);
        }
    }

    /**
     * Redefines an existing method in the class with closure
     */
    public function redefine(\Closure $newCode): void
    {
        $this->ensureCompatibleClosure($newCode);

        if (!$this->isInternal()) {
            $selfExecutionState = Core::$executor->getExecutionState();
            $newCodeEntry       = $selfExecutionState->getArgument(0)->getRawObject();
            $newCodeEntry       = Core::cast('zend_closure *', $newCodeEntry);

            // Copy only common op_array part from original one to keep name, scope, etc
            Core::memcpy($newCodeEntry->func, $this->pointer[0], Core::sizeof($newCodeEntry->func->common));

            // Replace original method with redefined closure
            Core::memcpy($this->pointer, Core::addr($newCodeEntry->func), Core::sizeof($newCodeEntry->func));
        } else {
            // For internal function we can simply adjust a handler
            $this->pointer->handler = function (CData $executeData, CData $returnValue) use ($newCode): void {
                $rawValue   = ReflectionValue::fromValueEntry($returnValue);
                $stackTrace = debug_backtrace(0, 2);
                $result     = $newCode(...$stackTrace[1]['args']);
                $rawValue->setNativeValue($result);
            };
        }
    }

    /**
     * @inheritDoc
     */
    public function isUserDefined(): bool
    {
        return (bool) ($this->pointer->type & Core::ZEND_USER_FUNCTION);
    }

    /**
     * Returns the iterable generator of opcodes for this function
     *
     * @return iterable|OpLine[]
     */
    public function getOpCodes(): iterable
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Opcodes are available only for user-defined functions');
        }
        $opCodes      = [];
        $opcodeIndex  = 0;
        $totalOpcodes = $this->pointer->op_array->last;
        while ($opcodeIndex < $totalOpcodes) {
            $opCode = new OpLine(
                Core::addr($this->pointer->op_array->opcodes[$opcodeIndex++])
            );
            $opCodes[] = $opCode;
        }

        return $opCodes;
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

    /**
     * Returns the hash key for function or method
     */
    abstract protected function getHash(): string;

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

    /**
     * Returns a pointer to the common structure (to work natively with zend_function and zend_internal_function)
     */
    private function getCommonPointer(): CData
    {
        // For zend_internal_function we have same fields directly in current structure
        if ($this->isInternal()) {
            $pointer = $this->pointer;
        } else {
            // zend_function uses "common" struct to store all important fields
            $pointer = $this->pointer->common;
        }

        return $pointer;
    }
}
