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
 * Redeclares the private ChangedEarlyRoot::m(), so the engine marks this entry
 * ZEND_ACC_CHANGED (AddMethodChangedFlagTest::testOverrideIsSharedIntoASubclassLinkedBeforeWithTheChangedFlag)
 */
class ChangedEarlyMid extends ChangedEarlyRoot
{
    public function m(): string
    {
        return 'mid';
    }
}
