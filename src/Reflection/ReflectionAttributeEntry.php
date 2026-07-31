<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Type\StringEntry;

/**
 * Class ReflectionAttributeEntry provides engine-level access to one attribute declaration
 *
 * Unlike the native ReflectionAttribute, this wrapper exposes the raw zend_attribute
 * structure as compiled into the class/function/property/constant attributes table,
 * including the pre-lowered name and the raw (unevaluated) argument zvals.
 *
 * typedef struct _zend_attribute {
 *     zend_string *name;
 *     zend_string *lcname;
 *     uint32_t flags;
 *     uint32_t lineno;
 *     uint32_t offset; // parameter offsets start at 1, everything else uses 0
 *     uint32_t argc;
 *     zend_attribute_arg args[1];
 * } zend_attribute;
 */
final class ReflectionAttributeEntry
{
    /**
     * Pointer to the zend_attribute structure (borrowed from the engine attributes table)
     */
    private CData $pointer;

    private function __construct(CData $attributePointer)
    {
        $this->pointer = $attributePointer;
    }

    /**
     * Creates an entry from a zend_attribute pointer (or a raw void pointer to it)
     *
     * @param CData $attributePointer Pointer to the zend_attribute structure
     */
    public static function fromCData(CData $attributePointer): self
    {
        return new self(Core::cast('zend_attribute *', $attributePointer));
    }

    /**
     * Creates an entry from an IS_PTR value stored in an engine attributes table
     *
     * @param ReflectionValue $valueEntry Element of a table returned by getAttributesTable()
     */
    public static function fromValueEntry(ReflectionValue $valueEntry): self
    {
        return self::fromCData($valueEntry->getRawPointer());
    }

    /**
     * Returns the attribute class name exactly as written in the source code
     */
    public function getName(): string
    {
        $name = $this->pointer->name;
        assert($name instanceof CData);

        return StringEntry::fromCData($name)->getStringValue();
    }

    /**
     * Returns the pre-lowered attribute class name used by the engine for lookups
     */
    public function getLoweredName(): string
    {
        $loweredName = $this->pointer->lcname;
        assert($loweredName instanceof CData);

        return StringEntry::fromCData($loweredName)->getStringValue();
    }

    /**
     * Returns the raw flags word of this attribute
     *
     * For attributes registered by extensions this holds the ZEND_ATTRIBUTE_TARGET_*
     * validation mask and the ZEND_ATTRIBUTE_IS_REPEATABLE bit; for attributes compiled
     * from userland code the engine reuses the field for the ZEND_ATTRIBUTE_PERSISTENT
     * and ZEND_ATTRIBUTE_STRICT_TYPES bits of the declaration site instead.
     */
    public function getTarget(): int
    {
        $flags = $this->pointer->flags;
        assert(is_int($flags));

        return $flags;
    }

    /**
     * Returns the line number where this attribute is declared
     */
    public function getLineNumber(): int
    {
        $line = $this->pointer->lineno;
        assert(is_int($line));

        return $line;
    }

    /**
     * Returns the parameter offset of this attribute (parameter offsets start at 1, everything else uses 0)
     */
    public function getOffset(): int
    {
        $offset = $this->pointer->offset;
        assert(is_int($offset));

        return $offset;
    }

    /**
     * Returns the number of arguments passed to this attribute
     */
    public function getArgumentCount(): int
    {
        $argumentCount = $this->pointer->argc;
        assert(is_int($argumentCount));

        return $argumentCount;
    }

    /**
     * Returns the raw attribute arguments as borrowed value wrappers
     *
     * Positional arguments are keyed by their position, named arguments by their name.
     * The values are the raw compiled zvals: plain literals stay literals, while any
     * non-trivial expression is stored as an IS_CONSTANT_AST value that the engine
     * only evaluates when the attribute is instantiated.
     *
     * @return array<int|string, ReflectionValue>
     */
    public function getArguments(): array
    {
        $arguments = [];
        // args[] is a flexible array member declared as one element, so it must be
        // walked through an unchecked pointer instead of the FFI bounds-checked array
        $rawArguments = $this->pointer->args;
        assert($rawArguments instanceof CData);
        $argumentPointer = Core::cast('zend_attribute_arg *', $rawArguments);
        for ($index = 0; $index < $this->getArgumentCount(); $index++) {
            $argument = $argumentPointer[$index];
            assert($argument instanceof CData);
            $argumentName  = $argument->name;
            $argumentValue = $argument->value;
            assert($argumentValue instanceof CData);
            if ($argumentName !== null) {
                assert($argumentName instanceof CData);
                $key = StringEntry::fromCData($argumentName)->getStringValue();
            } else {
                $key = $index;
            }
            $arguments[$key] = ReflectionValue::fromValueEntry($argumentValue);
        }

        return $arguments;
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'     => $this->getName(),
            'line'     => $this->getLineNumber(),
            'argCount' => $this->getArgumentCount(),
        ];
    }
}
