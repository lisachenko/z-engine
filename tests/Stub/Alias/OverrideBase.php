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
 * Base of the addMethod() override scenario (constructor, final and plain methods)
 */
class OverrideBase
{
    public function __construct(public string $tag = 'base') {}

    public function hello(): string
    {
        return 'base';
    }

    final public function sealed(): string
    {
        return 'sealed';
    }

    public function own(): string
    {
        return 'own';
    }
}
