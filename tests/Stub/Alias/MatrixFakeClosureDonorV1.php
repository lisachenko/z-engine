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
 * First donor body for MatrixFakeClosureTarget::greet() (the body the fake closures are made over)
 */
class MatrixFakeClosureDonorV1
{
    public string $name = 'donor1';

    public function greet(string $who): string
    {
        return 'v1:' . strtoupper($who) . ' from ' . $this->name . str_repeat('.', 2);
    }
}
