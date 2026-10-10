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
 * Entry swapped with a closure declaring a static variable, then used as the METHOD donor of
 * MintedAdopter::m() - owned by RedefineMintedStaticsTest::testMethodDonorCarryingAMintedTableGetsItsOwnDuplicate
 */
class MintedSource
{
    public function m(): int
    {
        return 0;
    }
}
