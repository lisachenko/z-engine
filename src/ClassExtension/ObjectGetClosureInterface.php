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

use ZEngine\ClassExtension\Hook\GetClosureHook;

/**
 * Interface ObjectGetClosureInterface allows to control what $object(...),
 * Closure::fromCallable($object) and is_callable($object) resolve to - invokable
 * objects without __invoke
 */
interface ObjectGetClosureInterface
{
    /**
     * Returns the closure the engine should use for the given object
     *
     * When $hook->isCheckOnly() reports true, the engine only probes callability
     * (e.g. is_callable($object)) and the handler must stay side-effect-free.
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50). Call
     * $hook->proceed() to fall through to the engine resolution (__invoke), which
     * returns null when the object is not invokable by engine rules.
     */
    public static function __getClosure(GetClosureHook $hook): \Closure;
}
