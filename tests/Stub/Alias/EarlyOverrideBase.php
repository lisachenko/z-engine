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
 * Three-level chain for the override-with-linked-subclass scenario
 */
class EarlyOverrideBase
{
    public function m(): string
    {
        return 'base';
    }
}
