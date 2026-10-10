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
 * Root of the override scenario: declares the m() that CloneOverrideMid inherits and
 * CloneOverrideChild holds a clone of
 * (owned by RedefineInterfaceCloneTest::testOverrideOnAnIntermediateClassReplacesTheCloneLinkedBefore)
 */
class CloneOverrideBase
{
    public function m(string $who): string
    {
        return 'base:' . $who;
    }
}
