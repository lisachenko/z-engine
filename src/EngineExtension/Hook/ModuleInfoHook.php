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

use ZEngine\Core;
use ZEngine\Hook\AbstractHook;

/**
 * Receiving hook for the module info callback (phpinfo() section rendering)
 *
 * Follows the same safety contract as the lifecycle trampolines: no-op after
 * Core::shutdown(), and the user callback never throws across the FFI boundary (#50).
 */
class ModuleInfoHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'info_func';

    /**
     * void (*info_func)(zend_module_entry *zend_module);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): void
    {
        if (Core::isShutdown()) {
            return;
        }
        AbstractModuleLifecycleHook::invokeContained($this->userHandler, static::HOOK_FIELD);
    }
}
