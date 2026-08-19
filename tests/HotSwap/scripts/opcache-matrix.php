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

use FFI\CData;
use ZEngine\Core;
use ZEngine\HotSwap\HotSwap;
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

/**
 * Fails the matrix with a diagnostic (exit code 1 = assertion failure)
 */
$fail = static function (string $message): never {
    fwrite(STDERR, "{$message}\n");
    exit(1);
};

/**
 * Snapshot of a shared-memory class entry that must survive a copy-out untouched
 *
 * @return array{entry: CData, flags: int, methods: int}
 */
$snapshotSharedClass = static function (string $className) use ($fail): array {
    $classValue = Core::$executor->classTable->find(strtolower($className));
    if ($classValue === null) {
        $fail("class {$className} is not published in the engine");
    }
    $sharedEntry = $classValue->getRawClass();
    $sharedClass = ReflectionClass::fromCData($sharedEntry);
    if (!$sharedClass->isImmutable()) {
        // A mutable entry means opcache did not publish the fixture from shared
        // memory: the branch under test would silently not be exercised
        fwrite(STDERR, "class {$className} is not an immutable (shared-memory) class entry\n");
        exit(2);
    }
    $methodTable = $sharedEntry->function_table;
    assert($methodTable instanceof CData);
    $methodCount = $methodTable->nNumOfElements;
    $classFlags  = $sharedEntry->ce_flags;
    assert(is_int($methodCount) && is_int($classFlags));

    return ['entry' => $sharedEntry, 'flags' => $classFlags, 'methods' => $methodCount];
};

/**
 * Asserts the shared-memory entry was neither written nor unpublished-and-freed, and
 * that the class table now publishes a writable per-process copy instead
 *
 * @param array{entry: CData, flags: int, methods: int} $snapshot
 */
$assertSharedEntryIntact = static function (string $className, array $snapshot) use ($fail): void {
    $sharedEntry = $snapshot['entry'];
    assert($sharedEntry instanceof CData);
    $methodTable = $sharedEntry->function_table;
    assert($methodTable instanceof CData);
    if ($sharedEntry->ce_flags !== $snapshot['flags']) {
        $fail("shared-memory ce_flags of {$className} changed");
    }
    if ($methodTable->nNumOfElements !== $snapshot['methods']) {
        $fail("shared-memory method table of {$className} changed");
    }
    $publishedValue = Core::$executor->classTable->find(strtolower($className));
    if ($publishedValue === null) {
        $fail("class {$className} lost its class-table bucket");
    }
    $publishedEntry = $publishedValue->getRawClass();
    if (Core::addressOf($publishedEntry) === Core::addressOf($sharedEntry)) {
        $fail("class {$className} is still published from shared memory");
    }
    if (ReflectionClass::fromCData($publishedEntry)->isImmutable()) {
        $fail("the copy of {$className} is still marked immutable");
    }
};

// Everything below dispatches through runtime names: an engine that resolved a class
// or a method at compile time would answer from a call site that captured the
// shared-memory entry, which is exactly what must NOT decide the outcome here (and a
// method published by addMethod() does not exist at compile time at all)
$instantiate = static function (string $className): object {
    return new $className();
};
$callMethod = static function (object $target, string $methodName, string ...$arguments): string {
    $result = $target->{$methodName}(...$arguments);

    return is_string($result) ? $result : '';
};

/**
 * Compares two runtime strings
 *
 * Both sides arrive as arguments on purpose: a swapped-in body returns something else
 * than the source the analyser sees, so no comparison here has a statically-known result
 */
$assertSameString = static function (string $actual, string $expected, string $message) use ($fail): void {
    if ($actual !== $expected) {
        $fail("{$message} (got '{$actual}', expected '{$expected}')");
    }
};

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
    $fail('copy-out redefine did not take effect');
}
// The second redefinition exercises the ordinary writable path on the copy
$refFunction->redefine(function (): string {
    return 'writable-copy-2';
});
if (!$shmDispatches('writable-copy-2')) {
    $fail('second redefine on the copied-out entry failed');
}
echo "function-copy-out: ok\n";

// 2. A method of an opcache-shared class: the class entry is copied out of shared
//    memory and the body swap targets the writable copy (issue #41)
$greetSnapshot = $snapshotSharedClass(ZEngineShmClass::class);
$refMethod     = new ReflectionMethod(ZEngineShmClass::class, 'greet');
$refMethod->redefine(function (): string {
    return 'redefined-hello';
});
$greeter = $instantiate(ZEngineShmClass::class);
$assertSameString(
    $callMethod($greeter, 'greet'),
    'redefined-hello',
    'method redefine on a shared-memory class did not take effect',
);
$assertSameString(
    $callMethod($greeter, 'callsGreet'),
    'via:redefined-hello',
    'an untouched method of the copy does not dispatch the redefined one',
);
$assertSameString(ZEngineShmClass::KIND, 'shm', 'class constant was not carried over to the copy');
$assertSharedEntryIntact(ZEngineShmClass::class, $greetSnapshot);
echo "method-redefine: ok\n";

// 3. addMethod on an opcache-shared class - the operation issue #41 is about
$extendableSnapshot = $snapshotSharedClass(ZEngineShmExtendable::class);
$refClass           = new ReflectionClass(ZEngineShmExtendable::class);
$refClass->addMethod('injected', function (): string {
    return 'injected-body';
});
$extendable = $instantiate(ZEngineShmExtendable::class);
$assertSameString(
    $callMethod($extendable, 'injected'),
    'injected-body',
    'the injected method does not dispatch its body',
);
$assertSameString(
    $callMethod($extendable, 'callsInjected', 'injected'),
    'via:injected-body',
    'a shared-memory method body does not dispatch the injected method',
);
$assertSameString(
    $callMethod($extendable, 'original'),
    'original',
    'an original method of the copied-out class stopped working',
);
$assertSharedEntryIntact(ZEngineShmExtendable::class, $extendableSnapshot);
echo "add-method: ok\n";

// 4. Hot-swap of an opcache-shared class: the whole delta is applied to the copy
$swappableSnapshot = $snapshotSharedClass(ZEngineShmSwappable::class);
$sharedDelta       = HotSwap::prepare(
    ZEngineShmSwappable::class,
    'class ZEngineShmSwappable { public const VERSION = "v2"; public function ping(): string { return "v2"; } }',
);
$sharedDelta->apply();
$swappable = $instantiate(ZEngineShmSwappable::class);
$assertSameString(
    $callMethod($swappable, 'ping'),
    'v2',
    'hot-swap of a shared-memory class did not take effect',
);
if (constant(ZEngineShmSwappable::class . '::VERSION') !== 'v2') {
    $fail('hot-swapped class constant was not updated on the copy');
}
$assertSharedEntryIntact(ZEngineShmSwappable::class, $swappableSnapshot);
echo "hot-swap: ok\n";

// 5. Runtime-declared (writable) classes keep the full mutation surface with
//    opcache enabled in the same process
eval('class ZEngineRuntimeClass { public function ping(): string { return "v1"; } }');
$delta = HotSwap::prepare(
    'ZEngineRuntimeClass',
    'class ZEngineRuntimeClass { public function ping(): string { return "v2"; } }',
);
$delta->apply();
$runtimeInstance = $instantiate('ZEngineRuntimeClass');
$assertSameString(
    $callMethod($runtimeInstance, 'ping'),
    'v2',
    'runtime-class hot-swap under opcache failed',
);
echo "runtime-class-swap: ok\n";

// 6. The live static-variables table of an untouched shared-memory function must be
//    read through its map-ptr offset slot (issue #239): the declaration defaults say 0,
//    only the materialized live table knows about the calls made below
$staticsFunction = new ReflectionFunction('zengine_shm_static_counter');
if (!$staticsFunction->isImmutable()) {
    // A mutable function means opcache did not publish the fixture from shared memory:
    // the map-ptr offset branch under test would silently not be exercised
    fwrite(STDERR, "zengine_shm_static_counter is not an immutable (shared-memory) function\n");
    exit(2);
}
zengine_shm_static_counter();
zengine_shm_static_counter();
$staticsTable = $staticsFunction->getStaticVariables();
if ($staticsTable === null) {
    $fail('the shared-memory function reports no static-variables table at all');
}
$invocationsEntry = $staticsTable->find('invocations');
if ($invocationsEntry === null) {
    $fail('static variable $invocations is missing from the table');
}
// Bound slots hold IS_REFERENCE zvals shared with the function (see the
// getStaticVariables() ownership contract) - unwrap before reading the value
$observedCount = null;
$invocationsEntry->dereference()->getNativeValue($observedCount);
if ($observedCount !== 2) {
    $observedExport = var_export($observedCount, true);
    $fail("the live static-variables table was not read through the map-ptr slot (invocations = {$observedExport}, expected 2)");
}
echo "static-vars-live-table: ok\n";

// Reaching this point with exit code 0 also proves the request shuts down cleanly:
// issue #41 crashed in zend_function_dtor()/destroy_zend_class() at request shutdown
echo "MATRIX OK\n";
