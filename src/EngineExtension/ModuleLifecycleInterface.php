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

namespace ZEngine\EngineExtension;

/**
 * Opt-in lifecycle callbacks for userland modules
 *
 * When a module class implementing this interface is registered via AbstractModule::register(),
 * FFI-closure trampolines are written into the module entry's `module_startup_func`,
 * `module_shutdown_func`, `request_startup_func` and `request_shutdown_func` slots.
 *
 * Delivery guarantees (see docs/long-running.md, "Module lifecycle callbacks"):
 *
 *  - moduleStartup() and requestStartup() are delivered during AbstractModule::startup(),
 *    inside the current request - guaranteed.
 *  - requestShutdown() is delivered at request end by z-engine's own shutdown chain, right
 *    after Core::shutdown() has restored the engine pointers - guaranteed. Engine writes
 *    are already forbidden inside it.
 *  - moduleShutdown() is best-effort only: the engine runs real MSHUTDOWN after the FFI
 *    bridge is torn down, where no PHP callback can be reached anymore. It fires only if
 *    the engine destroys the module while the request (and ext/ffi) is still alive.
 *
 * None of the callbacks may let an exception escape: they are entered by the engine through
 * an FFI trampoline with no PHP frame around them (see issue #50). Escaping exceptions are
 * contained by z-engine and reported as E_USER_WARNING.
 */
interface ModuleLifecycleInterface
{
    /**
     * Module startup callback (MINIT), called by the engine during AbstractModule::startup()
     */
    public function moduleStartup(): void;

    /**
     * Module shutdown callback (MSHUTDOWN), best-effort: real MSHUTDOWN runs after the FFI
     * bridge teardown and cannot be delivered (see the interface description)
     */
    public function moduleShutdown(): void;

    /**
     * Request startup callback (RINIT), delivered during AbstractModule::startup() for the
     * current request (dl() parity: the engine only activates modules at request start)
     */
    public function requestStartup(): void;

    /**
     * Request shutdown callback (RSHUTDOWN), delivered at request end right after
     * Core::shutdown()
     */
    public function requestShutdown(): void;
}
