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
 * Linked BEFORE OverridingBase::m() is redefined; overrides m() and reaches the base body
 * through parent:: - the preserved body then runs on a receiver of this class
 */
class OverridingSub extends OverridingBase
{
    public function m(): string
    {
        return 'sub:' . parent::m();
    }
}
