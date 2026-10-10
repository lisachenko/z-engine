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
 * Redeclares the private ChangedRoot::m() as a public method, which the engine marks
 * ZEND_ACC_CHANGED at link time; ChangedLeaf inherits this entry and overrides it with
 * addMethod() (AddMethodChangedFlagTest::testOverrideOfAChangedInheritedMethodKeepsTheRootPrivateDispatch)
 */
class ChangedMid extends ChangedRoot
{
    public function m(): string
    {
        return 'mid';
    }
}
