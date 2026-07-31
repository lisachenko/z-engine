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

use ZEngine\ClassExtension\Hook\GetConstructorHook;

/**
 * Interface ObjectGetConstructorInterface allows to intercept constructor resolution
 * during `new` (factories, sealed classes, construction bans)
 */
interface ObjectGetConstructorInterface
{
    /**
     * Returns the method the engine should invoke as the constructor
     *
     * Return null to skip the constructor call entirely, $hook->proceed() to fall through
     * to the engine-resolved constructor, or any (public, non-static) method reflection of
     * a loaded class to redirect construction to it.
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50).
     */
    public static function __getConstructor(GetConstructorHook $hook): ?\ReflectionMethod;
}
