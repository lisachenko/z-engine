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
