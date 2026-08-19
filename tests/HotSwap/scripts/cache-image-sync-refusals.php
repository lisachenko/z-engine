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
 * CacheImageSync refusal and not-loaded reporting probe (no opcache in this
 * process):
 *
 *  - an image whose script was never loaded here reports every entry as not
 *    loaded and applies as a loud no-op (never a crash, never an invention);
 *  - an UNCHANGED enum in a loaded image is not an operation and passes;
 *  - a CHANGED enum method is refused loudly (throw-or-work, never silent),
 *    and the live enum keeps executing its old body.
 *
 * argv: [1] = file-cache directory to compile the fixture into (parent-owned)
 */

use ZEngine\Core;
use ZEngine\HotSwap\CacheImageSync;
use ZEngine\HotSwap\HotSwapException;
use ZEngine\OpCache\BinaryCacheFile;

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
$fixture = realpath(__DIR__ . '/image-sync-enum-fixture.php');
if ($fixture === false) {
    $fail('enum fixture not found');
}

$file = BinaryCacheFile::compile($fixture, $cacheDir, PHP_BINARY, [
    'opcache.optimization_level=0',
    'opcache.file_update_protection=0',
]);
$image = $file->getReflection();

// 1. Nothing of the image is loaded yet: everything lands in the not-loaded
//    buckets and apply() is an explicit no-op report, not a crash
$orphan = CacheImageSync::prepare($image);
if (!$orphan->isEmpty()) {
    $fail('image of a never-loaded script must diff as empty');
}
$orphanReport = $orphan->apply();
if (!$orphanReport->isNoOp()) {
    $fail('applying an image of a never-loaded script must be a no-op');
}
if ($orphanReport->notLoadedFunctions !== ['zengine_image_sync_enum_side']) {
    $fail('the image-only function is not reported as not loaded');
}
if ($orphanReport->notLoadedClasses !== ['zengineimagesyncchannel']) {
    $fail('the image-only enum is not reported as not loaded');
}
echo "not-loaded-report: ok\n";

// 2. Loaded and untouched: an enum in the image is not an operation, so
//    nothing is refused and the apply stays a no-op
require $fixture;
$unchanged = CacheImageSync::prepare($image);
if (!$unchanged->isEmpty()) {
    $fail('untouched enum image did not diff as empty');
}
if (!$unchanged->apply()->isNoOp()) {
    $fail('untouched enum image apply was not a no-op');
}
echo "unchanged-enum: ok\n";

// 3. A patched enum METHOD is a refused operation: prepare() exposes the
//    refusal, apply() throws it before touching anything
foreach ($image->getClasses()['zengineimagesyncchannel']->getDeclaredMethods()['describe']->getLiterals() as $literal) {
    $value = null;
    $literal->getNativeValue($value);
    if ($value === 'channel-') {
        $literal->setNativeValue('patched-');
    }
}
$refused = CacheImageSync::prepare($image);
if ($refused->isEmpty()) {
    $fail('a changed enum method must not diff as empty');
}
$reasons = $refused->getRefusalReasons();
if ($reasons === [] || !str_contains($reasons[0], 'ZEngineImageSyncChannel')) {
    $fail('the refusal reason does not name the enum');
}
try {
    $refused->apply();
    $fail('applying a changed enum method must throw');
} catch (HotSwapException $exception) {
    if (!str_contains($exception->getMessage(), 'enums are not supported')) {
        $fail('unexpected refusal message: ' . $exception->getMessage());
    }
}
// The live enum still executes its previous body (runtime-name dispatch)
$callCase = static function (string $enum, string $method) use ($fail): string {
    $case = constant("{$enum}::Stable");
    if (!is_object($case)) {
        $fail("{$enum}::Stable is not a case object");
    }
    $result = $case->{$method}();

    return is_string($result) ? $result : '';
};
if ($callCase('ZEngineImageSyncChannel', 'describe') !== 'channel-stable') {
    $fail('the refused enum body must stay untouched');
}
echo "refused-enum: ok\n";

echo "IMAGE SYNC REFUSALS OK\n";
