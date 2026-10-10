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
 * Target of RedefineRunTimeCacheTest::testFakeClosuresOverARedefinedMethodSurviveTheNextSwap:
 * redefined twice from closure donors while three kinds of fake closures made over greet()
 * stay alive
 */
class CacheFakeClosureTarget
{
    public function __construct(public string $name = 'target') {}

    public function greet(string $who): string
    {
        return 'target:' . $who . ' from ' . $this->name;
    }
}
