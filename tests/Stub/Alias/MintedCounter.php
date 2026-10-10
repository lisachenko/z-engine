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
 * Method with a static variable, first swapped with a closure declaring its own static
 * (the swap mints a defaults duplicate), then preserved under an alias by a method-donor
 * swap - owned by RedefineMintedStaticsTest::testAliasOfAClosureSwappedMethodMintsItsOwnStaticsDefaults
 */
class MintedCounter
{
    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }
}
