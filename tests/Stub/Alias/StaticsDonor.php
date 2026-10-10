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
 * Donor body for Statics::label(), calling the preserved alias through self::
 *
 * @method static string labelOriginal(string $suffix) the preserved Statics::label() body
 */
class StaticsDonor
{
    public static function label(string $suffix): string
    {
        return '<' . self::labelOriginal($suffix) . '>';
    }
}
