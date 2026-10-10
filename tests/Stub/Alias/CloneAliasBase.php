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
 * Target of RedefineInterfaceCloneTest::testPreservedAliasReachesTheCloneHolderLinkedBefore:
 * m() is redefined with a preserved alias after CloneAliasChild linked
 */
class CloneAliasBase
{
    public function m(string $who): string
    {
        return 'base:' . $who;
    }
}
