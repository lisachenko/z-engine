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
 * Declares a PUBLIC m() carrying no ZEND_ACC_CHANGED bit, inherited and then overridden by
 * ChangedPlainChild (AddMethodChangedFlagTest::testPlainOverrideOfAPublicInheritedMethodCarriesNoChangedBit)
 */
class ChangedPlainBase
{
    /**
     * Resolves m() from the base's own scope: a virtual dispatch for a public method
     */
    public function viaBase(): string
    {
        return $this->m();
    }

    public function m(): string
    {
        return 'base';
    }
}
