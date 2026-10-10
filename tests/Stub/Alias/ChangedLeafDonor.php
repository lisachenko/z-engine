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
 * Donor outside the hierarchy for the ChangedLeaf::m() override: its m() carries no
 * ZEND_ACC_CHANGED bit of its own (AddMethodChangedFlagTest::testOverrideOfAChangedInheritedMethodKeepsTheRootPrivateDispatch)
 */
class ChangedLeafDonor
{
    public function m(): string
    {
        return 'donor';
    }
}
