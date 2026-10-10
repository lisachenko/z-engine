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
 * Base of the linked-after chain (RedefineAliasInheritanceTest::testSubclassesLinkedAfterInheritTheAlias):
 * m() is redefined with a preserved alias BEFORE OverridingLateSub and OverridingLateLeaf are autoloaded
 */
class OverridingLateBase
{
    public function m(): string
    {
        return 'late-base';
    }
}
