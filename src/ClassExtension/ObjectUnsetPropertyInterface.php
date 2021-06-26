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

use ZEngine\ClassExtension\Hook\UnsetPropertyHook;

/**
 * Interface ObjectUnsetPropertyInterface allows to intercept property unset and handle this
 */
interface ObjectUnsetPropertyInterface
{
    /**
     * Performs reading of object's field
     */
    public static function __fieldUnset(UnsetPropertyHook $hook): void;
}
