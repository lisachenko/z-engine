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
 * CacheImageSync against opcache SHARED MEMORY: this process runs with opcache
 * enabled, so the fixture's entries are published immutable from shared
 * memory. Applying a patched image must copy the targets out of SHM (the
 * documented copy-out paths), swap the writable copies and leave the shared
 * originals byte-for-byte untouched.
 *
 * argv: [1] = file-cache directory to compile the fixture into (parent-owned)
 */

use FFI\CData;
use ZEngine\Core;
use ZEngine\HotSwap\CacheImageSync;
use ZEngine\OpCache\BinaryCacheFile;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

require __DIR__ . '/../../../vendor/autoload.php';

if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    fwrite(STDERR, "opcache is not active in the child process\n");
    exit(2);
}

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

// Default optimization on BOTH sides: the image compile child and this
// process's own require run the same opcache pipeline, so the untouched
// bodies must diff as equal
$file = BinaryCacheFile::compile($fixture, $cacheDir, PHP_BINARY, [
    'opcache.file_update_protection=0',
]);
require $fixture;

$liveFunction = new ReflectionFunction('zengine_bin_answer');
if (!$liveFunction->isImmutable()) {
    // Not published from shared memory: the copy-out branch under test would
    // silently not be exercised
    fwrite(STDERR, "zengine_bin_answer is not an immutable (shared-memory) function\n");
    exit(2);
}
$classValue = Core::$executor->classTable->find('zenginebinsubject');
if ($classValue === null) {
    $fail('fixture class is not published');
}
$sharedEntry = $classValue->getRawClass();
$sharedClass = ReflectionClass::fromCData($sharedEntry);
if (!$sharedClass->isImmutable()) {
    fwrite(STDERR, "ZEngineBinSubject is not an immutable (shared-memory) class entry\n");
    exit(2);
}
$sharedMethodTable = $sharedEntry->function_table;
assert($sharedMethodTable instanceof CData);
$sharedFlagsBefore   = $sharedEntry->ce_flags;
$sharedMethodsBefore = $sharedMethodTable->nNumOfElements;

$image = $file->getReflection();

// 1. The untouched image diffs as equal against the SHM-published bodies
$untouched = CacheImageSync::prepare($image);
if (!$untouched->isEmpty()) {
    $fail('untouched image did not diff as empty against shared memory');
}
echo "shm-noop-diff: ok\n";

// 2. Patch and apply: the bridge copies the targets out of shared memory
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
$patchLiteral($image->getClasses()['zenginebinsubject']->getDeclaredMethods()['describe'], 1, 3);

$report = CacheImageSync::prepare($image)->apply();
if ($report->appliedFunctions !== ['zengine_bin_answer']) {
    $fail('applied function report is wrong: ' . json_encode($report->appliedFunctions));
}
if ($report->appliedMethods !== ['zenginebinsubject::describe']) {
    $fail('applied method report is wrong: ' . json_encode($report->appliedMethods));
}
echo "shm-apply: ok\n";

// 3. Live dispatch through runtime names executes the patched bodies
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
if ($callStatic('ZEngineBinSubject', 'describe', 0) !== 'stablestablestable') {
    $fail('patched method body is not live');
}
echo "shm-dispatch: ok\n";

// 4. The published entries are per-process copies now, and the shared-memory
//    originals were neither written nor unpublished-and-freed
if ((new ReflectionFunction('zengine_bin_answer'))->isImmutable()) {
    $fail('the swapped function entry is still marked immutable');
}
$publishedValue = Core::$executor->classTable->find('zenginebinsubject');
if ($publishedValue === null) {
    $fail('the class lost its class-table bucket');
}
$publishedEntry = $publishedValue->getRawClass();
if (Core::addressOf($publishedEntry) === Core::addressOf($sharedEntry)) {
    $fail('the class is still published from shared memory');
}
if (ReflectionClass::fromCData($publishedEntry)->isImmutable()) {
    $fail('the class copy is still marked immutable');
}
if ($sharedEntry->ce_flags !== $sharedFlagsBefore) {
    $fail('shared-memory ce_flags changed');
}
if ($sharedMethodTable->nNumOfElements !== $sharedMethodsBefore) {
    $fail('shared-memory method table changed');
}
echo "shm-copy-out: ok\n";

// 5. Idempotency holds against the copied-out entries as well
if (!CacheImageSync::prepare($image)->isEmpty()) {
    $fail('re-diff after apply is not empty');
}
echo "shm-idempotency: ok\n";

echo "IMAGE SYNC SHM OK\n";
