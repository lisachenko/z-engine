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

namespace ZEngine\Stub;

/**
 * Template for slot-addressed substitution: every substitutable slot is declared `mixed`,
 * which has no type name for TypeSubstitutionMap to key on
 */
class TestSlotSpecializationTemplate extends TestSlotSpecializationBase implements TestInterface
{
    public mixed $value;

    public mixed $nullableValue = null;

    public mixed $textDefault = 'text';

    public TPlaceholder $named;

    public int $count = 5;

    public function __construct(int $count = 1)
    {
        $this->count = $count;
    }

    public function setValue(mixed $newValue): void
    {
        $this->value = $newValue;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setNamed(TPlaceholder $newValue): void
    {
        $this->named = $newValue;
    }

    public function getNamed(): TPlaceholder
    {
        return $this->named;
    }

    public function collect(TPlaceholder ...$values): int
    {
        return count($values);
    }

    public function constantReturn(): string
    {
        // A literal the compiler can prove satisfies `string`, so no check is emitted
        return 'literal';
    }

    /**
     * Non-constant return, so the compiler really emits ZEND_VERIFY_RETURN_TYPE
     *
     * @return string
     */
    public function describeValue(): string
    {
        /** @phpstan-ignore return.type (the point of the fixture is that the engine checks it) */
        return $this->value;
    }

    /**
     * Literals, a loop and a try/catch, so a relocated opcode array has something to get wrong
     */
    public function classify(mixed $input): string
    {
        try {
            if ($input < 0) {
                return 'negative';
            }
            if ($input === 0) {
                return 'zero';
            }
            $seen = 0;
            for ($index = 0; $index < $input; $index++) {
                $seen++;
            }

            return 'positive:' . $seen;
        } finally {
            // A try region so the copied opcodes carry a try_catch_array entry to get wrong
            $this->count = $this->count;
        }
    }

    /**
     * Intentionally untyped: a slot with no zend_type at all cannot be substituted
     *
     * @param mixed $raw
     */
    public function untypedParameter($raw): void
    {
        $this->value = $raw;
    }

    /**
     * Intentionally without a return type: there is no arg_info entry at index -1 to rewrite
     *
     * @return int
     */
    public function withoutReturnType()
    {
        return $this->count;
    }

    public function unionReturn(): int|string
    {
        return $this->count;
    }
}
