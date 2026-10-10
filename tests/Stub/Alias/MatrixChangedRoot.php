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
 * Root of the matrix ZEND_ACC_CHANGED scenario (redefine-alias-matrix.php): declares a private
 * m() and calls it from its own scope
 *
 * m() reads a property so that the opcache optimizer cannot fold the `$this->m()` call site
 * in viaRoot() at cache time (a private method returning a literal is inlined).
 */
class MatrixChangedRoot
{
    public function __construct(private string $tag = 'root') {}

    private function m(): string
    {
        return $this->tag;
    }

    public function viaRoot(): string
    {
        return $this->m();
    }
}
