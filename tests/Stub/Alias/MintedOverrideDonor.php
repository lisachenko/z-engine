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
 * Donor whose m() is first swapped with a closure declaring a static variable, then published
 * as the override MintedOverrideChild::m() - owned by
 * RedefineMintedStaticsTest::testOverrideAdoptingAClosureSwappedMethodGetsItsOwnDuplicate
 */
class MintedOverrideDonor
{
    public function m(): int
    {
        return 0;
    }
}
