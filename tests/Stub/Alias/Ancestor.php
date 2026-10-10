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
 * Root of the inheritance scenario: woven first, then subclasses are loaded
 */
class Ancestor
{
    public function greet(string $who): string
    {
        return "hi {$who}";
    }

    public function inheritedOnly(int $x): int
    {
        return $x + 1;
    }
}
