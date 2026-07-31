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

use ZEngine\ClassExtension\Hook\ReadDimensionHook;

/**
 * Interface ObjectReadDimensionInterface allows to intercept dimension reads ($object[$offset]) and modify values
 */
interface ObjectReadDimensionInterface
{
    /**
     * Performs reading of object's dimension
     *
     * @return mixed Value to return
     */
    public static function __dimensionRead(ReadDimensionHook $hook);
}
