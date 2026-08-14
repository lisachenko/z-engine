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
 * Receiving hook for the module startup callback (MINIT)
 *
 * The engine calls it from zend_startup_module_ex() during AbstractModule::startup().
 */
class ModuleStartupHook extends AbstractModuleLifecycleHook
{
    protected const string HOOK_FIELD = 'module_startup_func';
}
