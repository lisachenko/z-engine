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

namespace ZEngine\Stub;

/**
 * Parent class of the specialization template: provides inherited state so the
 * specialization tests can assert that parent-declared members stay shared
 */
class TestSpecializationBase
{
    public const BASE_CONST = 10;

    protected string $inheritedProperty = 'base';

    public function inheritedMethod(): string
    {
        return 'inherited:' . static::class;
    }
}
