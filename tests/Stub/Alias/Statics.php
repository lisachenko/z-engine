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
 * Static method with late static binding in its body
 */
class Statics
{
    public static function label(string $suffix): string
    {
        return 'label:' . static::class . ':' . $suffix;
    }
}
