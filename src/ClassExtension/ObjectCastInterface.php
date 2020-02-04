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

use ZEngine\ClassExtension\Hook\CastObjectHook;

/**
 * Interface ObjectCastInterface allows to cast given object to scalar values, like integer, floats, etc
 */
interface ObjectCastInterface
{
    /**
     * Performs casting of given object to another value
     *
     * @param CastObjectHook $hook Instance of current hook
     *
     * @return mixed Casted value
     */
    public static function __cast(CastObjectHook $hook);
}
