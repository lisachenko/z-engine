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

namespace ZEngine\Stub;

/**
 * Userland class that legally accepts dynamic properties (rejected by the persistent heap)
 */
#[\AllowDynamicProperties]
class TestDynamicPropsHolder
{
    public int $declared = 0;
}
