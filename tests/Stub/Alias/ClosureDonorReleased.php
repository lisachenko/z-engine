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
 * Method swapped with a closure-backed method donor and swapped again afterwards: the next
 * swap releases the duplicate minted for the closure's table without touching the table -
 * owned by RedefineClosureBackedDonorTest::testTheNextSwapReleasesTheDuplicateAndLeavesTheClosureTableAlone
 */
class ClosureDonorReleased
{
    public function m(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }
}
