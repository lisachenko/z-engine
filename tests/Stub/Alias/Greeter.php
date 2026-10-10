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
 * Instance methods for the method-donor scenario (a `self` return type among them)
 */
class Greeter
{
    public function __construct(public string $name = 'greeter') {}

    public function greet(string $who): string
    {
        return "hi {$who} from {$this->name}";
    }

    public function itself(): self
    {
        return $this;
    }
}
