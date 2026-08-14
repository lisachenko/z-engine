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
 * Receiving hook for the request shutdown callback (RSHUTDOWN)
 *
 * The engine walks the module registry only after user shutdown functions have run, ie
 * after Core::shutdown() has already cleared this trampoline pointer. The guaranteed
 * request-end delivery of ModuleLifecycleInterface::requestShutdown() therefore happens in
 * z-engine's own shutdown chain (see AbstractModule), not through this trampoline.
 */
class RequestShutdownHook extends AbstractModuleLifecycleHook
{
    protected const string HOOK_FIELD = 'request_shutdown_func';
}
