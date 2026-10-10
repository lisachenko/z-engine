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
 * Interface declaring the method MatrixCloneChild inherits from MatrixCloneBase: implementing
 * it makes the engine separate the inherited bucket into a struct clone of the entry
 * (matrix interface-clone scenario, redefine-alias-matrix.php)
 */
interface MatrixCloneContract
{
    public function greet(string $who): string;
}
