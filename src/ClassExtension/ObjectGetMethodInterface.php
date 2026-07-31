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

use ZEngine\ClassExtension\Hook\GetMethodHook;

/**
 * Interface ObjectGetMethodInterface allows to intercept method resolution
 * ($object->method(...)) - dynamic proxies without __call limitations
 */
interface ObjectGetMethodInterface
{
    /**
     * Returns the method the engine should invoke for the given call
     *
     * Return null to raise the standard "Call to undefined method" Error,
     * $hook->proceed() to fall through to the engine resolution, or any method
     * reflection of a loaded class to redirect the call. Note that the VM caches
     * resolutions of compile-time constant method names per call site and class,
     * so such call sites consult this handler only once (see GetMethodHook).
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50).
     */
    public static function __getMethod(GetMethodHook $hook): ?\ReflectionMethod;
}
