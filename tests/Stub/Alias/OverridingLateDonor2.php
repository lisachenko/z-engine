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
 * Donor body for OverridingLateBase2::m(), delegating to the preserved alias
 *
 * @method string mOriginal() the preserved OverridingLateBase2::m() body
 */
class OverridingLateDonor2
{
    public function m(): string
    {
        return '[' . $this->mOriginal() . ']';
    }
}
