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
 * Method with a static variable that a fake closure borrows the live table of before the
 * method is redefined without an alias - owned by
 * RedefinePreservedStaticsTest::testFakeClosureOverTheEntryKeepsBorrowingTheLiveStaticsThroughASwap
 */
class LiveStaticsBorrowedEntry
{
    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }
}
