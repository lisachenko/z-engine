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
 * Donor body for MatrixEarlyBase::handle(), calling the preserved alias
 *
 * @method string handleOriginal() the preserved MatrixEarlyBase::handle() body
 */
class MatrixEarlyDonor
{
    public function handle(): string
    {
        return '[' . $this->handleOriginal() . ']';
    }
}
