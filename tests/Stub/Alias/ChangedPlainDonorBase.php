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
 * Parent of the donor: its private m() makes the engine flag ChangedPlainDonor::m()
 * ZEND_ACC_CHANGED, a bit the override published from that donor must not carry
 * (AddMethodChangedFlagTest::testPlainOverrideOfAPublicInheritedMethodCarriesNoChangedBit)
 */
class ChangedPlainDonorBase
{
    /**
     * Resolves m() from this scope (keeps the private method referenced)
     */
    public function viaDonorBase(): string
    {
        return $this->m();
    }

    private function m(): string
    {
        return 'donor-base@' . static::class;
    }
}
