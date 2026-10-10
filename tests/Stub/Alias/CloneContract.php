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
 * Interface declaring the method the Clone* subclasses inherit: an interface prototype makes
 * the engine separate an inherited bucket into a struct copy of the ancestor entry at link time
 * (do_inheritance_check_on_method) instead of sharing the entry pointer
 *
 * Shared by every scenario of RedefineInterfaceCloneTest: an interface is never redefined
 */
interface CloneContract
{
    public function m(string $who): string;
}
