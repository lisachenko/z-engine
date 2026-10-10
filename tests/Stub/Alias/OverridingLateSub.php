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
 * Autoloaded AFTER OverridingLateBase::m() was redefined: linked against the class that
 * already publishes the alias, overrides m() through parent::
 */
class OverridingLateSub extends OverridingLateBase
{
    public function m(): string
    {
        return 'late:' . parent::m();
    }
}
