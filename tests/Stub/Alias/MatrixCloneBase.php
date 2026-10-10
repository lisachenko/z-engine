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
 * Base class of the matrix interface-clone scenario: greet() is redefined twice, with the
 * subclass MatrixCloneChild linked in between
 */
class MatrixCloneBase
{
    public function __construct(public string $name = 'base') {}

    public function greet(string $who): string
    {
        return strtoupper($who) . ' from ' . $this->name . str_repeat('.', 2);
    }
}
