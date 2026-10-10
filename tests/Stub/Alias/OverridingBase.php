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
 * Base of the overriding-subclass chain (RedefineAliasInheritanceTest::testAliasReachesOverridingSubclassesLinkedBefore):
 * m() is redefined with a preserved alias while OverridingSub and OverridingLeaf are already linked
 */
class OverridingBase
{
    public function m(): string
    {
        return 'base';
    }
}
