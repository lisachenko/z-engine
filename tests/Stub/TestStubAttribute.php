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

use Attribute;

/**
 * Simple userland attribute used by the engine-level attribute reflection tests
 */
#[Attribute(Attribute::TARGET_ALL)]
final class TestStubAttribute
{
    public function __construct(
        public mixed $first = null,
        public mixed $second = null,
    ) {}
}
