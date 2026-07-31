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

namespace ZEngine\EngineExtension\Hook;

/**
 * Receiving hook for the module shutdown callback (MSHUTDOWN)
 *
 * Best-effort by design: the engine runs real MSHUTDOWN after the FFI bridge teardown,
 * where the trampoline pointer has already been cleared by Core::shutdown(). It only fires
 * if the engine destroys the module while the request (and ext/ffi) is still alive.
 */
class ModuleShutdownHook extends AbstractModuleLifecycleHook
{
    protected const HOOK_FIELD = 'module_shutdown_func';
}
