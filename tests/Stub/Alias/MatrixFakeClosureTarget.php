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
 * Target of the matrix fake-closure scenario (redefine-alias-matrix.php): fake closures made over
 * greet() after its first redefinition share the minted run-time cache of the entry
 *
 * The body reads a property and calls an internal function so that the warmed inline caches
 * (property offset, function pointer) are what the shared cache block holds.
 */
class MatrixFakeClosureTarget
{
    public function __construct(public string $name = 'target') {}

    public function greet(string $who): string
    {
        return strtoupper($who) . ' from ' . $this->name . str_repeat('.', 2);
    }
}
