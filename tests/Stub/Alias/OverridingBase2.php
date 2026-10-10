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
 * Base of the redeclared-alias chain (RedefineAliasInheritanceTest::testSubclassDeclaringTheAliasNameKeepsItAndGetsTheChangedFlag):
 * m() is redefined with the alias mOriginal while OverridingMid, which declares a private
 * mOriginal() of its own, and OverridingLeaf2 are already linked
 */
class OverridingBase2
{
    public function m(): string
    {
        return 'base2';
    }
}
