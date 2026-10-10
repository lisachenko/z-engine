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
 * Method with a static variable and an inline cache (the property fetch), preserved under an
 * alias that is dropped again with removeMethods() - owned by
 * RedefinePreservedStaticsTest::testRemovingTheAliasDestroysItsLiveStaticsAndReleasesItsMintedCache
 */
class LiveStaticsRemoved
{
    public int $base = 0;

    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return $this->base + ++$n;
    }
}
