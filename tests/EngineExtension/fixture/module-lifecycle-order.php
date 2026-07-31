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

use ZEngine\Core;
use ZEngine\EngineExtension\AbstractModule;
use ZEngine\EngineExtension\ModuleDependency;
use ZEngine\Stub\LifecycleModule;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

LifecycleModule::$echoEvents = true;
$module                      = new LifecycleModule();
$module->register();
$module->startup();

// Registered AFTER the module: must run after the module's requestShutdown() delivery
register_shutdown_function(static function (): void {
    echo 'late-user-shutdown(coreShutdown=', var_export(Core::isShutdown(), true), ')', PHP_EOL;
});

// The engine must reject an entry that conflicts with an already loaded module
$conflicting = new class ('conflicting') extends AbstractModule {
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    public static function targetPersistent(): bool
    {
        return false;
    }

    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    public static function globalType(): ?string
    {
        return null;
    }

    public function getModuleDependencies(): array
    {
        return [ModuleDependency::conflicts('standard')];
    }
};
try {
    $conflicting->register();
    echo 'conflict-not-rejected', PHP_EOL;
} catch (RuntimeException) {
    echo 'conflict-rejected', PHP_EOL;
}
if ($conflicting->isModuleRegistered()) {
    throw new RuntimeException('Conflicting module must not appear in the registry');
}

echo 'script-end', PHP_EOL;
