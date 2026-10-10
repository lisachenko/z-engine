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
 * Base of the matrix early-overriding-subclass scenario (redefine-alias-matrix.php): handle() is
 * redefined with preserveAs AFTER MatrixEarlyChild and MatrixEarlyGrandChild were linked
 */
class MatrixEarlyBase
{
    public function __construct(private string $tag = 'base') {}

    public function handle(): string
    {
        return $this->tag;
    }
}
