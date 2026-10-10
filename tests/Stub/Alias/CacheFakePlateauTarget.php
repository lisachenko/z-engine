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
 * Target of RedefineRunTimeCacheTest::testRetiredCachesAreStillReleasedWhileAFakeClosureIsAlive:
 * greet() is redefined a few hundred times while a fake closure over it stays alive
 */
class CacheFakePlateauTarget
{
    public function greet(string $who): string
    {
        return 'target:' . $who;
    }
}
