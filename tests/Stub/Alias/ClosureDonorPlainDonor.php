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
 * Plain compiled method with a static variable, serving as the method donor of
 * ClosureDonorPlainTarget::m() - owned by the same scenario as ClosureDonorPlainTarget
 */
class ClosureDonorPlainDonor
{
    public function m(): int
    {
        /** @var int $n */
        static $n = 50;

        return ++$n;
    }
}
