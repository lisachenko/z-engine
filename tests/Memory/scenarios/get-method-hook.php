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
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\VirtualProxy;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Type-erasing seam for dynamic method names: engine-level method resolution (the
 * feature under test) is invisible to static analysis, which would otherwise treat
 * literal-typed name strings as regular method references
 */
$dynamicMethodName = static function (string $methodName): string {
    return $methodName;
};

Core::init();

$refClass = new ReflectionClass(VirtualProxy::class);
$refClass->installExtensionHandlers();

$proxy = new VirtualProxy('leak-check');
// Exercise every get_method path in a loop:
//  - the constant-name call site resolves through the hook once and is then served from
//    the VM's polymorphic inline cache (still dispatching to the redirected method),
//  - dynamic mixed-case names bypass the cache and resolve through the hook on every
//    iteration, exercising the userland lowercasing path with per-iteration name strings,
//  - proceed() falls through to the engine resolution for the defined method,
//  - the array-callable check resolves through get_method without invoking
for ($index = 0; $index < 1000; $index++) {
    if ($proxy->virtualMethod() !== 'real-leak-check') {
        throw new RuntimeException('Unexpected redirected method result');
    }
    $dynamicName = $dynamicMethodName($index % 2 === 0 ? 'VirtualMETHOD' : 'virtualMethod');
    if ($proxy->$dynamicName() !== 'real-leak-check') {
        throw new RuntimeException('Unexpected dynamically redirected method result');
    }
    if ($proxy->realMethod() !== 'real-leak-check') {
        throw new RuntimeException('Unexpected proceed() fallthrough result');
    }
    if (!is_callable([$proxy, 'virtualMethod'])) {
        throw new RuntimeException('Redirected method must probe as callable');
    }
}
// The hook records every non-cached resolution: the constant call sites resolved once,
// the dynamic ones on every iteration
if (count(VirtualProxy::$methodResolutions) < 1000) {
    throw new RuntimeException('Dynamic method names must resolve through the hook every time');
}
VirtualProxy::$methodResolutions = [];
unset($proxy);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
