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
 * Entry adopting the closure-swapped MintedSource::m() as its method donor - owned by
 * RedefineMintedStaticsTest::testMethodDonorCarryingAMintedTableGetsItsOwnDuplicate
 */
class MintedAdopter
{
    public function m(): int
    {
        return 0;
    }
}
