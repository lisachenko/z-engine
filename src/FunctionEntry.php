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

class FunctionEntry
{
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * Returns the name of function
     */
    public function getName(): string
    {
        return (string) (new StringEntry($this->pointer->common->function_name));
    }

    /**
     * Checks if a function is declared as final
     */
    public function isFinal(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_FINAL);
    }

    /**
     * Declares function as final/non-final
     *
     * @param bool $isFinal True to make class final/false to remove final flag
     */
    public function setFinal($isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->common->fn_flags->cdata = ($this->pointer->common->fn_flags | Core::ZEND_ACC_FINAL);
        } else {
            $this->pointer->common->fn_flags->cdata = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_FINAL));
        }
    }

    /**
     * Checks if a function is declared as abstract
     */
    public function isAbstract(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_ABSTRACT);
    }

    /**
     * Declares function as abstract/non-abstract
     *
     * @param bool $isAbstract True to make class final/false to remove final flag
     */
    public function setAbstract($isAbstract = true): void
    {
        if ($isAbstract) {
            $this->pointer->common->fn_flags->cdata = ($this->pointer->common->fn_flags | Core::ZEND_ACC_ABSTRACT);
        } else {
            $this->pointer->common->fn_flags->cdata = ($this->pointer->common->fn_flags & (~Core::ZEND_ACC_ABSTRACT));
        }
    }

    /**
     * Checks if a function is declared as private (only for methods)
     */
    public function isPrivate(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_PRIVATE);
    }

    /**
     * Checks if a function is declared as protected (only for methods)
     */
    public function isProtected(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_PROTECTED);
    }

    /**
     * Checks if a function is declared as public (only for methods)
     */
    public function isPublic(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_PUBLIC);
    }

    /**
     * Checks if a function is declared as static (only for methods)
     */
    public function isStatic(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_STATIC);
    }

    /**
     * Checks if a function is declared as variadic
     */
    public function isVariadic(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_VARIADIC);
    }

    /**
     * Checks if a function is declared as generator
     */
    public function isGenerator(): bool
    {
        return (bool) ($this->pointer->common->fn_flags & Core::ZEND_ACC_GENERATOR);
    }

    /**
     * Checks if the current function entry is in class scope (eg. method or bound closure)
     */
    public function isInClassScope(): bool
    {
        return $this->pointer->common->scope !== null;
    }

    /**
     * Returns the scope where current function/method is defined
     *
     * @throws \InvalidArgumentException If scope is not available
     */
    public function getScope(): ReflectionClass
    {
        if ($this->pointer->common->scope === null) {
            throw new \InvalidArgumentException('Not in a class scope');
        }
        return ReflectionClass::fromClassEntry($this->pointer->common->scope);
    }

    /**
     * Returns the function prototype (only for methods)
     */
    public function getPrototype(): FunctionEntry
    {
        return new FunctionEntry($this->pointer->common->prototype);
    }

    public function getNumberOfArguments(): int
    {
        return $this->pointer->common->num_args;
    }

    public function getNumberOfRequiredArguments(): int
    {
        return $this->pointer->common->required_num_args;
    }

    public function isUserDefined(): bool
    {
        return $this->pointer->type === CORE::ZEND_USER_FUNCTION;
    }

    public function isInternal(): bool
    {
        return $this->pointer->type === CORE::ZEND_INTERNAL_FUNCTION;
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
     * @return ValueEntry
     */
    public function getLiteral(int $index): ValueEntry
    {
        if (!$this->isUserDefined()) {
            throw new \LogicException('Literals are available only for user-defined functions');
        }
        $lastLiteral = $this->pointer->op_array->last_literal;
        if ($index > $lastLiteral) {
            throw new \OutOfBoundsException("Literal index {$index} is out of bounds, last is {$lastLiteral}");
        }
        $literal = $this->pointer->op_array->literals[$index];

        return new ValueEntry($literal);
    }

    /**
     * Returns list of literals, associated with this entry
     *
     * @return ValueEntry[]
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
                yield new ValueEntry($item);
            }
        };

        return $literalValueGenerator();
    }

    public function __debugInfo()
    {
        return [
            'name' => $this->getName()
        ];
    }
}
