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

use ZEngine\ClassExtension\Hook\GetClassNameHook;

/**
 * Interface ObjectGetClassNameInterface allows to override the class name reported for an
 * object by engine consumers of the get_class_name handler (var_dump, print_r, ...)
 *
 * Note that get_class() and ::class read zend_class_entry.name directly and are NOT routed
 * through this handler by the engine.
 */
interface ObjectGetClassNameInterface
{
    /**
     * Returns the class name to report for the given object
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50). Call
     * $hook->proceed() to get the original engine-reported name.
     */
    public static function __getClassName(GetClassNameHook $hook): string;
}
