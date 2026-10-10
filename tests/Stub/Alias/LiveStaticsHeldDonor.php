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
 * Method donor (no static variables) for LiveStaticsHeldBase::counter(), delegating to the
 * preserved alias - owned by the same scenario as LiveStaticsHeldBase
 *
 * @method int counterOriginal() the preserved LiveStaticsHeldBase::counter() body
 */
class LiveStaticsHeldDonor
{
    public function counter(): int
    {
        return 100 + $this->counterOriginal();
    }
}
