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
 * Fixture with PHP 8.4 property hooks and attributes on every supported target
 */
#[TestStubAttribute('classValue', second: 42)]
class TestHookedClass
{
    #[TestStubAttribute('constantValue')]
    public const SOME_CONST = 100;

    #[TestStubAttribute(123, second: 'namedValue')]
    public int $hooked = 5 {
        get => $this->hooked + 1;
        set(int $value) {
            $this->hooked = $value * 2;
        }
    }

    public int $virtual {
        get => 42;
    }

    public int $plain = 0;

    #[TestStubAttribute('methodValue')]
    public function annotatedMethod(): int
    {
        return 1;
    }
}
