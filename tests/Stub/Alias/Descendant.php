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
 * Loaded after Ancestor::greet() was redefined; overrides greet() through parent::
 */
class Descendant extends Ancestor
{
    public function greet(string $who): string
    {
        return 'child:' . parent::greet($who);
    }
}
