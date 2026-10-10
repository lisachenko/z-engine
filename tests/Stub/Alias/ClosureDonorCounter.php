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
 * Receives a method published from a closure (addMethod($name, $closure)) and then takes
 * that closure-backed method as the METHOD donor of counter() - owned by
 * RedefineClosureBackedDonorTest::testMethodDonorPublishedFromAClosureGetsADuplicateOfTheClosureTable;
 * inherited() is the method ClosureDonorChild overrides from the same donor
 */
class ClosureDonorCounter
{
    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }

    public function inherited(): int
    {
        return 0;
    }
}
