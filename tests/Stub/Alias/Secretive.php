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

namespace ZEngine\Stub\Alias;

/**
 * Private method reached through a public one
 *
 * secret() reads a property so the opcache optimizer cannot fold the call site
 * away (a private method returning a literal is inlined at cache time).
 */
class Secretive
{
    public function __construct(private int $base = 40) {}

    private function secret(): int
    {
        return $this->base + 2;
    }

    public function reveal(): int
    {
        return $this->secret() * 2;
    }
}
