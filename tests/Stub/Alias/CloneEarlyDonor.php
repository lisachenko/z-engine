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
 * Donor body for CloneEarlyBase::m()
 * (owned by RedefineInterfaceCloneTest::testCloneOfASubclassLinkedBeforeFollowsTheRedefinition)
 */
class CloneEarlyDonor
{
    public function m(string $who): string
    {
        return 'v1:' . strtoupper($who);
    }
}
