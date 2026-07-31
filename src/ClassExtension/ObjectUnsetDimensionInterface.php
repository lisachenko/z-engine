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

use ZEngine\ClassExtension\Hook\UnsetDimensionHook;

/**
 * Interface ObjectUnsetDimensionInterface allows to intercept dimension unset operations (unset($object[$offset]))
 */
interface ObjectUnsetDimensionInterface
{
    /**
     * Performs unsetting of object's dimension
     */
    public static function __dimensionUnset(UnsetDimensionHook $hook): void;
}
