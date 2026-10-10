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
 * Donor body for CloneAliasBase::m(), delegating to the preserved alias
 * (owned by RedefineInterfaceCloneTest::testPreservedAliasReachesTheCloneHolderLinkedBefore)
 *
 * @method string mOriginal(string $who) the preserved CloneAliasBase::m() body
 */
class CloneAliasDonor
{
    public function m(string $who): string
    {
        return '[' . $this->mOriginal($who) . ']';
    }
}
