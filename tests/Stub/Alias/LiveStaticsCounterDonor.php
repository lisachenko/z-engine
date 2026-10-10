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
 * Method donor with a static variable of its own for LiveStaticsCounter::counter(): the entry
 * binds it afresh while the alias keeps the previous body's state - owned by the same scenario
 * as LiveStaticsCounter
 */
class LiveStaticsCounterDonor
{
    public function counter(): int
    {
        /** @var int $m */
        static $m = 1000;

        return ++$m;
    }
}
