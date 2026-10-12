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
 * Inherits hello()/sealed()/__construct() and declares own() itself
 */
class OverrideDerived extends OverrideBase
{
    public function own(): string
    {
        return 'derived-own';
    }
}
