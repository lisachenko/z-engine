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
use ZEngine\Reflection\ReflectionProperty;
use ZEngine\Stub\TestHookedClass;

require __DIR__ . '/../../../vendor/autoload.php';

// The scenario must observe an opcache-immutable hooked class: without opcache the
// declaring class's function table is request-local and the historical corruption
// cannot manifest. The parent test spawns this file with opcache.enable_cli=1.
if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    fwrite(STDERR, 'opcache is not active in the scenario process');
    exit(2);
}

Core::init();

class_exists(TestHookedClass::class);
$classInfo = new ReflectionClass(TestHookedClass::class);
if (!$classInfo->isImmutable()) {
    // Nothing to regress against - the engine did not serve the class from SHM
    // (eg opcache decided not to cache the stub). Report distinctly, do not fail.
    echo "class-not-immutable\n";
    exit(3);
}
echo "class-is-immutable\n";

// The historical bug: publishing the hook into the immutable class's SHM function
// table forced a bucket-array resize with the request allocator, corrupting the
// shared table - afterwards native reflection saw an empty method table and any
// FFI walk over it crashed the process.
$property = new ReflectionProperty(TestHookedClass::class, 'hooked');
$getHook  = $property->getHook(Core::ZEND_PROPERTY_HOOK_GET);

assert($getHook !== null);
echo $getHook->getName()                      === '$hooked::get' ? "hook-name-intact\n" : "hook-name-broken\n";
echo $getHook->getDeclaringClass()->getName() === TestHookedClass::class
    ? "hook-scope-intact\n"
    : "hook-scope-broken\n";
echo $getHook->invoke(new TestHookedClass()) === 6 ? "hook-invokable\n" : "hook-invoke-broken\n";

// The transient publication must not leak into any visible method list
$nativeMethods = array_map(
    static fn(\ReflectionMethod $method): string => $method->getName(),
    (new \ReflectionClass(TestHookedClass::class))->getMethods(),
);
echo $nativeMethods === ['annotatedMethod'] ? "native-table-intact\n" : "native-table-broken\n";

// The FFI walk over the same table is what used to segfault on the corrupted arData
$engineMethods = [];
foreach ((new ReflectionClass(TestHookedClass::class))->getMethods() as $method) {
    $engineMethods[] = $method->getName();
}
echo $engineMethods === ['annotatedMethod'] ? "engine-table-intact\n" : "engine-table-broken\n";

echo "scenario-complete\n";
