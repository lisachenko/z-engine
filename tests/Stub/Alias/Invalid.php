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
 * Shapes the donor validation must refuse
 */
class Invalid
{
    public function dyn(): int
    {
        return 1;
    }

    public static function stat(): int
    {
        return 2;
    }

    public function typed(int $x): int
    {
        return $x;
    }
}
