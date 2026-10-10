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
 * Method with a static variable whose preserved alias gets a fake closure made over it before
 * the alias is removed - owned by
 * RedefinePreservedStaticsTest::testFakeClosureOverTheAliasKeepsItsLiveStaticsPastTheRemoval
 */
class LiveStaticsBorrowedAlias
{
    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }
}
