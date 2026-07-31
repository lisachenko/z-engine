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
use ZEngine\Stub\LifecycleModule;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$module = new LifecycleModule();
$module->register();
$module->startup();

if (!extension_loaded('lifecycle')) {
    throw new RuntimeException('Module was not registered');
}
if (LifecycleModule::$events !== ['moduleStartup', 'requestStartup']) {
    throw new RuntimeException('Startup lifecycle callbacks were not delivered');
}

ob_start();
phpinfo(INFO_MODULES);
$info = (string) ob_get_clean();
if (!str_contains($info, 'Lifecycle support => enabled')) {
    throw new RuntimeException('phpinfo() output is missing the module section');
}

$dependencies = (new ReflectionExtension('lifecycle'))->getDependencies();
if (($dependencies['standard'] ?? null) !== 'Required') {
    throw new RuntimeException('Module dependencies were not written');
}

// Explicit shutdown clears every trampoline pointer from the persistent module entry
// while the trampolines are still alive; a second call must be a no-op
Core::shutdown();
Core::shutdown();

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;

// Immortal-by-design allocations left behind on purpose (documented exemptions, see
// docs/long-running.md "Immortal-by-design allocations"):
//  - the persistent zend_module_entry (the registry stores this pointer directly on 8.4+)
//  - the module name buffer
//  - the NULL-terminated zend_module_dep[] array and its dependency-name strings
// All of them are malloc-backed (never ZendMM), so the debug leak gate stays silent about
// them by construction. The libffi trampolines are owned by ext/ffi and freed by its
// RSHUTDOWN. After SCENARIO OK the request shuts down through the engine's full-table
// cleanup, destroying the temporary module with all its callback pointers already cleared.
