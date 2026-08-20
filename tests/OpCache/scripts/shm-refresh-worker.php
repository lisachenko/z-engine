<?php

/**
 * Child worker for the shared-memory refresh tests. Runs with opcache shared
 * memory ACTIVE (opcache.file_cache set, but NOT file_cache_only), so each CLI
 * process owns a private SHM segment - which makes this the one place where
 * "a script resident in SHM" can be observed honestly: everything below
 * happens inside the single process whose shared memory holds the fixture.
 *
 * The worker includes the fixture (populating BOTH shared memory and the file
 * cache .bin), patches the .bin through the BinaryCacheFile API, and then
 * exercises one of two modes:
 *
 *  - save:    save() only (no invalidation). A re-include must STILL execute
 *             the original body - the SHM-resident copy is served and the
 *             patched binary on disk is not re-read.
 *  - refresh: refresh() (opcache_invalidate + save, in that order - issue
 *             #252). The SHM-resident copy must be evicted, the patched
 *             binary must SURVIVE the invalidation (the unlink hits the stale
 *             binary, not the fresh one), and a re-include must execute the
 *             PATCHED body, loaded from the file cache back into shared
 *             memory. Same-process pickup only happens under
 *             opcache.revalidate_path=1, so this mode requires that ini.
 *
 * argv: [1] = mode ("save" | "refresh")
 *       [2] = fixture script (must `return` a patchable long literal 41)
 *       [3] = the opcache.file_cache directory this process runs with
 *
 * Exit codes: 0 = all checks passed, 1 = an assertion failed (diagnostic on
 * stderr), 2 = opcache shared memory could not be activated (parent skips -
 * never a silent pass).
 */
declare(strict_types=1);

use ZEngine\Core;
use ZEngine\OpCache\BinaryCacheFile;
use ZEngine\Reflection\ReflectionValue;

require __DIR__ . '/../../../vendor/autoload.php';

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if (!is_array($status) || !empty($status['file_cache_only'])) {
    fwrite(STDERR, "opcache shared memory is not active in the child process\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(STDERR, "{$message}\n");
    exit(1);
};

// Residency is asserted through this closure because the answer genuinely
// changes over the script's lifetime (include -> resident, refresh() ->
// evicted): each call site states the expectation that holds at that point
$assertResidency = static function (string $path, bool $resident, string $message) use ($fail): void {
    if (opcache_is_script_cached($path) !== $resident) {
        $fail($message);
    }
};

// Binary presence also changes over the script's lifetime (the ordering bug of
// issue #252 was refresh() unlinking its own fresh binary), and PHP's stat
// cache would otherwise keep reporting the pre-refresh() state
$assertBinaryOnDisk = static function (string $path, string $message) use ($fail): void {
    clearstatcache(true, $path);
    if (!is_file($path)) {
        $fail($message);
    }
};

$mode     = $argv[1] ?? '';
$fixture  = realpath($argv[2] ?? '');
$cacheDir = $argv[3] ?? '';
if (!in_array($mode, ['save', 'refresh'], true)) {
    $fail("unknown mode '{$mode}', expected 'save' or 'refresh'");
}
if ($fixture === false || $cacheDir === '') {
    $fail('usage: shm-refresh-worker.php <mode> <fixture> <cache-dir>');
}

// 1. Load the fixture: with SHM active + opcache.file_cache set, one include
//    populates both the shared-memory hash and the file-cache .bin
$first = include $fixture;
if ($first !== 41) {
    $fail('the pristine fixture must return 41, got ' . var_export($first, true));
}
$assertResidency($fixture, true, 'the fixture is not resident in shared memory after the include');
$binPath = BinaryCacheFile::locate($cacheDir, $fixture);
$assertBinaryOnDisk($binPath, "the include did not populate the file cache: {$binPath} is missing");
echo "shm-populated: ok\n";

// 2. Patch the compiled body in the .bin through the framework wrappers
Core::init();
$file    = BinaryCacheFile::read($binPath, $fixture);
$main    = $file->getReflection()->getScriptFunction();
$patched = 0;
foreach ($main->getLiterals() as $literal) {
    $literal->getNativeValue($value);
    if ($literal->getBaseType() === ReflectionValue::IS_LONG && $value === 41) {
        $literal->setNativeValue(42);
        ++$patched;
    }
}
if ($patched !== 1) {
    $fail("expected to patch exactly one literal, patched {$patched}");
}
echo "patched-literal: ok\n";

if ($mode === 'save') {
    // 3a. save() alone: the patched binary lands on disk, but the script stays
    //     resident in shared memory - a re-include in THIS process must keep
    //     executing the original body, proving a SHM-resident script is not
    //     re-read until it is invalidated (the semantics that motivate refresh())
    $file->save();
    $second = include $fixture;
    if ($second !== 41) {
        $fail('save() alone must leave the SHM-resident body in service, got ' . var_export($second, true));
    }
    $assertResidency($fixture, true, 'save() alone must not evict the shared-memory copy');
    echo "stale-shm-after-save: ok\n";
    echo "SHM SAVE OK\n";
} else {
    // 3b. refresh(): both halves of the contract inside one process. The
    //     invalidation half evicts the SHM-resident copy; the reload half
    //     re-includes the script, which must execute the PATCHED body loaded
    //     from the surviving binary back into shared memory. Same-process
    //     pickup requires opcache.revalidate_path=1 (with the default key
    //     lookup the invalidated hash entry short-circuits path resolution
    //     and the file cache is never consulted again)
    if (ini_get('opcache.revalidate_path') !== '1') {
        $fail('the refresh mode must run with opcache.revalidate_path=1 - fix the parent test command');
    }
    $file->refresh();
    $assertResidency($fixture, false, 'refresh() must evict the shared-memory copy of the fixture');
    echo "refresh-evicts-shm: ok\n";
    $assertBinaryOnDisk($binPath, 'refresh() must leave the patched binary in place, not unlink it (issue #252)');
    echo "bin-survives-refresh: ok\n";
    $second = include $fixture;
    if ($second !== 42) {
        $fail('the re-include after refresh() must execute the patched body, got ' . var_export($second, true));
    }
    $assertResidency($fixture, true, 'the re-include must load the patched binary back into shared memory');
    echo "patched-body-on-reinclude: ok\n";
    echo "SHM REFRESH OK\n";
}
