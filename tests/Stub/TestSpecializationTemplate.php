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
 * Specialization template: TPlaceholder is deliberately NOT a real class - it is a
 * placeholder type name the ClassSpecializer tests substitute with concrete types.
 * Assigning anything to the placeholder-typed slots of THIS class therefore throws
 * TypeError, which is exactly what the tests assert for the unspecialized original.
 */
#[TestStubAttribute('specialization', second: 42)]
class TestSpecializationTemplate extends TestSpecializationBase implements TestInterface
{
    public const TEMPLATE_CONST = 'template';

    public static int $instances = 0;

    public TPlaceholder $value;

    public int $count = 5;

    public function __construct(int $count = 1)
    {
        $this->count = $count;
        static::$instances++;
    }

    public function setValue(TPlaceholder $newValue): void
    {
        $this->value = $newValue;
    }

    public function getValue(): TPlaceholder
    {
        return $this->value;
    }

    public function describe(): string
    {
        return static::class . ':' . $this->count;
    }

    public static function whoAmI(): string
    {
        return static::class;
    }
}
