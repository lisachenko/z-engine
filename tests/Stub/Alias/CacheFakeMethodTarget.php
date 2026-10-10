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
 * Target of RedefineRunTimeCacheTest::testFakeClosuresOverAMethodDonorBodyOutliveTheRestoringSwap:
 * greet() adopts the CacheFakeMethodDonor body under a preserved alias and is swapped back from it
 */
class CacheFakeMethodTarget
{
    public function __construct(public string $name = 'target') {}

    public function greet(string $who): string
    {
        return 'target:' . $who . ' from ' . $this->name;
    }
}
