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

use ZEngine\ClassExtension\Hook\CloneObjectHook;

/**
 * Interface ObjectCloneInterface allows to intercept `clone $object` at the engine level
 */
interface ObjectCloneInterface
{
    /**
     * Produces the clone of the object accessible via $hook->getObject()
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50). Call
     * $hook->proceed() to get the default field-copy clone.
     *
     * @return object The object to return as the clone result
     */
    public static function __cloneObject(CloneObjectHook $hook): object;
}
