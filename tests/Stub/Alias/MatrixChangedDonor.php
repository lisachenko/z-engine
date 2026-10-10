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
 * Donor outside the hierarchy for the addMethod() override of m() on MatrixChangedLeaf: its
 * own m() carries no CHANGED bit, the override inherits it from the replaced inherited entry
 */
class MatrixChangedDonor
{
    public function m(): string
    {
        return 'leaf';
    }
}
