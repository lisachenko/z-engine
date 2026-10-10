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
 * Declares the method MintedOverrideChild only inherits until addMethod() overrides it -
 * owned by RedefineMintedStaticsTest::testOverrideAdoptingAClosureSwappedMethodGetsItsOwnDuplicate
 */
class MintedOverrideBase
{
    public function m(): int
    {
        return 0;
    }
}
