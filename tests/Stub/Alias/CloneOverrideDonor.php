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
 * Donor for the CloneOverrideMid::m() override, reaching the base body through parent::
 * (owned by RedefineInterfaceCloneTest::testOverrideOnAnIntermediateClassReplacesTheCloneLinkedBefore)
 */
class CloneOverrideDonor extends CloneOverrideBase
{
    public function m(string $who): string
    {
        return 'over+' . parent::m($who);
    }
}
