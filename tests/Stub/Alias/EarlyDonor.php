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
 * Donor body for EarlyBase::m(), delegating to the preserved alias
 *
 * @method string mOriginal() the preserved EarlyBase::m() body
 */
class EarlyDonor
{
    public function m(): string
    {
        return '[' . $this->mOriginal() . ']';
    }
}
