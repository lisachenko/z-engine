<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\GetPropertiesForHook;

/**
 * Interface ObjectGetPropertiesForInterface allows to intercept casting to arrays, debug queries for object, etc
 */
interface ObjectGetPropertiesForInterface
{
    /**
     * Returns a hash-map (array) representation of object (for casting to array, json encoding, var dumping)
     *
     * @param GetPropertiesForHook $hook Instance of current hook
     *
     * @return array Key-value pair of fields
     */
    public static function __getFields(GetPropertiesForHook $hook): array;
}
