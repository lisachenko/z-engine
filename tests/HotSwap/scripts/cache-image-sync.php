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

/**
 * CacheImageSync end-to-end probe WITHOUT opcache in this process: the fixture
 * is loaded from source, the cache binary is compiled by a child with the
 * optimizer off (so the compiled bodies provably match the source compile),
 * then the image is patched and applied to the ALREADY-LOADED entries.
 *
 * argv: [1] = file-cache directory to compile the fixture into (parent-owned)
 */

use ZEngine\Core;
use ZEngine\HotSwap\CacheImageSync;
use ZEngine\HotSwap\HotSwapException;
use ZEngine\OpCache\BinaryCacheFile;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$fail = static function (string $message): never {
    fwrite(STDERR, "{$message}\n");
    exit(1);
};

$cacheDir = $argv[1] ?? '';
if ($cacheDir === '') {
    $fail('cache directory argument missing');
}
$fixture = realpath(__DIR__ . '/../../OpCache/fixtures/answer.php');
if ($fixture === false) {
    $fail('fixture not found');
}

// The optimizer is off for the image compile: this process loads the fixture
// from source (no opcache), and only unoptimized bodies are comparable with a
// plain source compile - which is exactly what the diff must report as equal
$file = BinaryCacheFile::compile($fixture, $cacheDir, PHP_BINARY, [
    'opcache.optimization_level=0',
    'opcache.file_update_protection=0',
]);
require $fixture;

$image = $file->getReflection();

// 1. An untouched image diffs as all-unchanged: applying is a loud no-op
$untouched = CacheImageSync::prepare($image);
if (!$untouched->isEmpty()) {
    $fail('untouched image did not diff as empty');
}
$noopReport = $untouched->apply();
if (!$noopReport->isNoOp()) {
    $fail('untouched image apply was not a no-op');
}
if (!in_array('zengine_bin_answer', $noopReport->unchangedFunctions, true)) {
    $fail('unchanged function is not reported');
}
if (!in_array('zenginebinsubject::describe', $noopReport->unchangedMethods, true)) {
    $fail('unchanged method is not reported');
}
echo "noop-diff: ok\n";

// 2. Patch the image: a long literal, a size-changing string literal and a
//    method literal (the method also carries statics and try/catch)
$patchLiteral = static function (ReflectionFunction|ReflectionMethod $function, int|string $from, int|string $to): void {
    foreach ($function->getLiterals() as $literal) {
        $value = null;
        $literal->getNativeValue($value);
        if ($value === $from) {
            $literal->setNativeValue($to);
        }
    }
};
$patchLiteral($image->getFunctions()['zengine_bin_answer'], 41, 42);
$patchLiteral($image->getFunctions()['zengine_bin_greeting'], 'hello', 'patched-hello');
$patchLiteral($image->getClasses()['zenginebinsubject']->getDeclaredMethods()['describe'], 1, 3);

$sync = CacheImageSync::prepare($image);
if ($sync->getChangedFunctions() !== ['zengine_bin_answer', 'zengine_bin_greeting']) {
    $fail('changed function set is wrong: ' . json_encode($sync->getChangedFunctions()));
}
if ($sync->getChangedMethods() !== ['zenginebinsubject' => ['describe']]) {
    $fail('changed method set is wrong: ' . json_encode($sync->getChangedMethods()));
}
$report = $sync->apply();
if ($report->appliedFunctions !== ['zengine_bin_answer', 'zengine_bin_greeting']) {
    $fail('applied function report is wrong');
}
if ($report->appliedMethods !== ['zenginebinsubject::describe']) {
    $fail('applied method report is wrong');
}
if ($report->notLoadedFunctions !== [] || $report->notLoadedClasses !== [] || $report->notLoadedMethods !== []) {
    $fail('nothing in this image should be reported as not loaded');
}
echo "patched-apply: ok\n";

// 3. The LIVE, already-loaded entries now execute the patched bodies - no
//    re-include happened. Runtime-name dispatch keeps every call site free of
//    compile-time resolution and of statically-known results.
$callIt = static function (string $name) use ($fail): mixed {
    if (!is_callable($name)) {
        $fail("{$name} is not callable");
    }

    return $name();
};
$callStatic = static function (string $class, string $method, int $argument) use ($fail): string {
    $callable = [$class, $method];
    if (!is_callable($callable)) {
        $fail("{$class}::{$method} is not callable");
    }
    $result = $callable($argument);

    return is_string($result) ? $result : '';
};
if ($callIt('zengine_bin_answer') !== 42) {
    $fail('patched function body is not live');
}
if ($callIt('zengine_bin_greeting') !== 'patched-hello') {
    $fail('patched string-literal body is not live');
}
if ($callStatic('ZEngineBinSubject', 'describe', 0) !== 'stablestablestable') {
    $fail('patched method body is not live');
}
// The method's static counter still works across calls on the swapped body
$callStatic('ZEngineBinSubject', 'describe', 0);
echo "live-dispatch: ok\n";

// 4. Idempotency: a fresh diff of the same image against the synced process is
//    empty; re-applying the consumed sync is refused loudly
$again = CacheImageSync::prepare($image);
if (!$again->isEmpty()) {
    $fail('re-diff after apply is not empty');
}
try {
    $sync->apply();
    $fail('double apply must throw');
} catch (HotSwapException $exception) {
    // expected: the sync is single-use
}
echo "idempotency: ok\n";

// Exit code 0 also proves the request shuts down cleanly with image-backed
// bodies still published in the executor tables
echo "IMAGE SYNC OK\n";
