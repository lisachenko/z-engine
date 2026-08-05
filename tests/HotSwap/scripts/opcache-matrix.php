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

use ReflectionMethod as NativeReflectionMethod;
use ZEngine\Core;
use ZEngine\HotSwap\HotSwap;
use ZEngine\Memory\SharedMemoryException;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

require __DIR__ . '/../../../vendor/autoload.php';

if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    fwrite(STDERR, "opcache is not active in the child process\n");
    exit(2);
}

Core::init();

require __DIR__ . '/opcache-shm-fixture.php';

$status        = opcache_get_status(true);
$cachedScripts = is_array($status) && isset($status['scripts']) && is_array($status['scripts'])
    ? $status['scripts']
    : [];
if (!isset($cachedScripts[realpath(__DIR__ . '/opcache-shm-fixture.php')])) {
    fwrite(STDERR, "fixture was not cached by opcache\n");
    exit(2);
}

// 1. An opcache-shared global function is copied out of SHM and redefined; the
//    SHM original stays untouched (never written, never freed). The dispatch
//    check takes the expected value as data - the body changes at runtime, so no
//    statically-known return value applies
$shmDispatches = static function (string $expected): bool {
    return zengine_shm_function() === $expected;
};
$refFunction = new ReflectionFunction('zengine_shm_function');
$refFunction->redefine(function (): string {
    return 'writable-copy';
});
if (!$shmDispatches('writable-copy')) {
    fwrite(STDERR, "copy-out redefine did not take effect\n");
    exit(1);
}
// The second redefinition exercises the ordinary writable path on the copy
$refFunction->redefine(function (): string {
    return 'writable-copy-2';
});
if (!$shmDispatches('writable-copy-2')) {
    fwrite(STDERR, "second redefine on the copied-out entry failed\n");
    exit(1);
}
echo "function-copy-out: ok\n";

// 2. A method of an opcache-shared class cannot be redefined (its method table
//    lives inside the SHM class entry): typed rejection
$refMethod = new ReflectionMethod(ZEngineShmClass::class, 'greet');
try {
    $refMethod->redefine(function (): string {
        return 'nope';
    });
    fwrite(STDERR, "method redefine was not rejected\n");
    exit(1);
} catch (SharedMemoryException $exception) {
    echo "method-redefine-rejected: ok\n";
}

// 3. addMethod on an opcache-shared class: typed rejection
$refClass = new ReflectionClass(ZEngineShmClass::class);
try {
    $refClass->addMethod('injected', function (): string {
        return 'nope';
    });
    fwrite(STDERR, "addMethod was not rejected\n");
    exit(1);
} catch (SharedMemoryException $exception) {
    echo "add-method-rejected: ok\n";
}

// 4. Hot-swap of an opcache-shared class: typed rejection
try {
    HotSwap::prepare(
        ZEngineShmClass::class,
        'class ZEngineShmClass { public const KIND = "x"; public function greet(): string { return "y"; } }',
    );
    fwrite(STDERR, "hot-swap was not rejected\n");
    exit(1);
} catch (SharedMemoryException $exception) {
    echo "hot-swap-rejected: ok\n";
}

// 5. Runtime-declared (writable) classes keep the full mutation surface with
//    opcache enabled in the same process
eval('class ZEngineRuntimeClass { public function ping(): string { return "v1"; } }');
$delta = HotSwap::prepare(
    'ZEngineRuntimeClass',
    'class ZEngineRuntimeClass { public function ping(): string { return "v2"; } }',
);
$delta->apply();
$runtimeClassName = 'ZEngineRuntimeClass';
$runtimeInstance  = (new ReflectionClass($runtimeClassName))->newInstance();
if ((new NativeReflectionMethod($runtimeInstance, 'ping'))->invoke($runtimeInstance) !== 'v2') {
    fwrite(STDERR, "runtime-class hot-swap under opcache failed\n");
    exit(1);
}
echo "runtime-class-swap: ok\n";

// The SHM original still answers through a fresh engine lookup in a sibling
// process (this process sees the writable copy, which is the point)
echo "MATRIX OK\n";
