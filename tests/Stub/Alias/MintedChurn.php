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
 * Method redefined hundreds of times with closures declaring static variables - owned by
 * RedefineMintedStaticsTest::testConsecutiveClosureDonorSwapsOfAMethodAreMemoryFlat
 */
class MintedChurn
{
    public function m(): int
    {
        return -1;
    }
}
