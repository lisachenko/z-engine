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
 * Donor body for Counter::counter(), delegating to the preserved alias
 *
 * @method int counterOriginal() the preserved Counter::counter() body
 */
class CounterDonor
{
    public function counter(): int
    {
        return 100 + $this->counterOriginal();
    }
}
