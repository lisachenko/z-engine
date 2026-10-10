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
 * Donor bodies for Greeter: they call the preserved aliases, which exist on Greeter only
 *
 * The `itself()` return type spells Greeter explicitly: a `self` here would resolve to
 * GreeterDonor at compile time and keep enforcing it after the swap.
 *
 * @method string greetOriginal(string $who) the preserved Greeter::greet() body
 * @method Greeter itselfOriginal() the preserved Greeter::itself() body
 */
class GreeterDonor
{
    public function greet(string $who): string
    {
        return '[' . $this->greetOriginal($who) . ']';
    }

    public function itself(): Greeter
    {
        return $this->itselfOriginal();
    }
}
