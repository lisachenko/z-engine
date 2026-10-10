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
 * Root of the CHANGED chain (AddMethodChangedFlagTest::testOverrideOfAChangedInheritedMethodKeepsTheRootPrivateDispatch):
 * a private m() the root's own code calls through $this, so the dispatch depends on the
 * ZEND_ACC_CHANGED bit of whatever m() the receiver's class resolves
 */
class ChangedRoot
{
    /**
     * Resolves m() from the root's own scope
     */
    public function viaRoot(): string
    {
        return $this->m();
    }

    /**
     * The body names its receiver, so the optimizer cannot fold the call in viaRoot() into
     * a constant at cache time (a `return 'const';` private method is inlined by opcache)
     */
    private function m(): string
    {
        return 'root@' . static::class;
    }
}
