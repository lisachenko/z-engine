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
 * Base of the engine-reference chain (RedefineAliasInheritanceTest::testSubclassDeclaringTheAliasNameLinkedAfterIsMarkedChangedByTheEngine):
 * m() is redefined with the alias mOriginal BEFORE OverridingLateMid is autoloaded
 */
class OverridingLateBase2
{
    public function m(): string
    {
        return 'late-base2';
    }
}
