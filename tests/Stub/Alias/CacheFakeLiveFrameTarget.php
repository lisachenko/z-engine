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
 * Target of RedefineRunTimeCacheTest::testFakeClosureRedefiningItsOwnMethodMidFlightKeepsItsCacheBlock:
 * greet() is redefined again from inside a fake closure that is executing it
 */
class CacheFakeLiveFrameTarget
{
    public function greet(string $who): string
    {
        return 'target:' . $who;
    }
}
