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
 * Receiving hook for the request startup callback (RINIT)
 *
 * The engine only activates modules at request start, so for a module registered in the
 * middle of a request the current request's RINIT is delivered directly by
 * AbstractModule::startup() (dl() parity) instead of through this trampoline.
 */
class RequestStartupHook extends AbstractModuleLifecycleHook
{
    protected const HOOK_FIELD = 'request_startup_func';
}
