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
 * Base class whose subclass is linked BEFORE the base method is redefined
 */
class EarlyBase
{
    public function m(): string
    {
        return 'base';
    }
}
