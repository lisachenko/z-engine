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

/**
 * Interface ObjectCastInterface allows to cast given object to scalar values, like integer, floats, etc
 */
interface ObjectCastInterface
{
    /**
     * Performs casting of given object to another value
     *
     * @param object $instance Instance of object that should be casted
     * @param int $typeTo Type of casting, @see ReflectionValue::IS_* constants
     *
     * @return mixed Casted value
     */
    public static function __cast(object $instance, int $typeTo);
}
