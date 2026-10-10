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
 * Donor whose m() redeclares a parent-private method and therefore carries ZEND_ACC_CHANGED
 * itself (AddMethodChangedFlagTest::testPlainOverrideOfAPublicInheritedMethodCarriesNoChangedBit)
 */
class ChangedPlainDonor extends ChangedPlainDonorBase
{
    public function m(): string
    {
        return 'donor';
    }
}
