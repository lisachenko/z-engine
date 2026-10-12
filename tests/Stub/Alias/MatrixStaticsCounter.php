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
 * Counter of the matrix statics scenario (redefine-alias-matrix.php): redefined with a closure
 * declaring a static, then with a method donor preserving the closure body under an alias,
 * then swapped back from the alias before the alias is removed
 */
class MatrixStaticsCounter
{
    public function counter(): int
    {
        /** @var int $n */
        static $n = 0;

        return ++$n;
    }
}
