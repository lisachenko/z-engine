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
 * Donor body for Descendant::greet(): its alias shadows the inherited Ancestor alias
 *
 * @method string greetOriginal(string $who) the preserved Descendant::greet() body
 */
class DescendantDonor
{
    public function greet(string $who): string
    {
        return '{' . $this->greetOriginal($who) . '}';
    }
}
