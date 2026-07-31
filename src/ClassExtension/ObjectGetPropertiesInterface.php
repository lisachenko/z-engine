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

use ZEngine\ClassExtension\Hook\GetPropertiesHook;

/**
 * Interface ObjectGetPropertiesInterface allows to control the property HashTable used by
 * (array) casts, foreach iteration, get_object_vars() and the garbage collector
 */
interface ObjectGetPropertiesInterface
{
    /**
     * Returns the properties to report for the given object
     *
     * The handler must not throw (it is called by the engine across the FFI boundary, see
     * issue #50) and should return a freshly built array on every call. The cycle
     * collector sees the object exclusively through the table this handler reported:
     * object references held in declared properties are invisible to it, so reference
     * cycles through instances of a hooked class are reclaimed only at request shutdown
     * (see the GetPropertiesHook docblock for the full GC model).
     * Call $hook->proceed() to get the default engine property table as an array.
     *
     * @return array<array-key, mixed> Properties to report
     */
    public static function __getProperties(GetPropertiesHook $hook): array;
}
