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

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\HasDimensionHook;

/**
 * Interface ObjectHasDimensionInterface allows to intercept dimension isset/empty checks (isset($object[$offset]))
 */
interface ObjectHasDimensionInterface
{
    /**
     * Performs checking of object's dimension
     *
     * @return int Value to return: 0 == not exists, 1 == exists
     */
    public static function __dimensionHas(HasDimensionHook $hook): int;
}
