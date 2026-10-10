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
 * Second donor body for MatrixCloneBase::greet() (the swap the interface clone must follow)
 */
class MatrixCloneDonorV2
{
    public string $name = 'donor2';

    public function greet(string $who): string
    {
        return 'v2:' . strtoupper($who) . ' from ' . $this->name . str_repeat('.', 2);
    }
}
