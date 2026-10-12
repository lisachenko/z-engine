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
 * Root of the linked-before chain (AddMethodChangedFlagTest::testOverrideIsSharedIntoASubclassLinkedBeforeWithTheChangedFlag):
 * a private m() the root's own code calls through $this
 */
class ChangedEarlyRoot
{
    /**
     * Resolves m() from the root's own scope
     */
    public function viaRoot(): string
    {
        return $this->m();
    }

    /**
     * Receiver-dependent body: not foldable by the optimizer at cache time
     */
    private function m(): string
    {
        return 'root@' . static::class;
    }
}
