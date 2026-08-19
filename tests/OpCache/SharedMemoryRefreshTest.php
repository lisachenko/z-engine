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

namespace ZEngine\OpCache;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionValue;

/**
 * BinaryCacheFile::refresh() semantics under REAL opcache shared memory - the
 * SHM-active counterpart of RefreshWorkflowTest (issue #125). Every worker here
 * runs with opcache.enable_cli=1 + opcache.file_cache=<dir> and WITHOUT
 * file_cache_only, so scripts live in shared memory with the file cache as the
 * second-level store.
 *
 * Each CLI process owns a private SHM segment, so the legs are explicit about
 * what they prove:
 *
 *  - a warm worker's single include populates BOTH shared memory and the .bin,
 *    and after a patch + refresh() a FRESH worker (empty SHM, like a pool
 *    worker after restart) executes the patched body - loaded through
 *    opcache's own file-cache-into-SHM path, checksums verified, and resident
 *    in shared memory afterwards (something file_cache_only never exercises);
 *  - within ONE worker process, save() alone leaves the SHM-resident body in
 *    service on re-include - the patched binary on disk is NOT re-read until
 *    invalidated, which is exactly the semantics that motivate refresh();
 *  - within ONE worker process, refresh() evicts the SHM-resident copy
 *    (opcache_is_script_cached() flips to false).
 *
 * Deliberately NOT asserted (found while building this test, reported from
 * issue #125): in the invalidating process itself a re-include after refresh()
 * does not pick the patched binary up. opcache_invalidate() with
 * opcache.file_cache set calls zend_file_cache_invalidate(), which UNLINKS the
 * .bin that refresh()'s save() has just written, so the re-include recompiles
 * the original source (and even with the ordering inverted, the re-include
 * only consults the file cache under opcache.revalidate_path=1 - with the
 * default key lookup the invalidated hash entry short-circuits path resolution
 * and zend_file_cache_script_load() bails on the unresolved opened_path).
 * Asserting the current behaviour would enshrine the bug; fixing it is
 * follow-up work on refresh(), not on this test.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class SharedMemoryRefreshTest extends TestCase
{
    use FileCacheFixture;

    protected function setUp(): void
    {
        if (!PayloadRelocator::isSupported()) {
            self::markTestSkipped(
                'The file-cache relocator supports 64-bit POSIX NTS payloads only'
                . ' (ZTS is issue #118, Windows is issue #119)',
            );
        }
    }

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testFreshWorkerExecutesPatchedBodyFromSharedMemoryAfterRefresh(): void
    {
        $fixture  = self::shmFixturePath();
        $cacheDir = self::freshShmCacheDir();

        // One include in a warm worker fills shared memory AND the file cache
        self::assertSame('value=41 shm=1', self::runShmWorker($fixture, $cacheDir));
        $binPath = BinaryCacheFile::locate($cacheDir, $fixture);
        self::assertFileExists($binPath, 'the warm worker must populate the file cache alongside shared memory');

        // Patch the compiled body in the .bin and refresh it
        $file = BinaryCacheFile::read($binPath, $fixture);
        self::patchAnswerLiteral($file);
        $file->refresh();

        // A fresh worker (empty SHM) must execute the PATCHED body - and shm=1
        // proves opcache loaded the patched binary INTO shared memory through
        // its own consistency-checked file-cache path, not a process-memory
        // fallback and not a recompile of the (unchanged) source
        self::assertSame('value=42 shm=1', self::runShmWorker($fixture, $cacheDir));
    }

    public function testSharedMemoryResidentScriptIsServedUntilInvalidated(): void
    {
        $fixture  = self::shmFixturePath();
        $cacheDir = self::freshShmCacheDir();

        // The negative control: in one worker process, patch + save() WITHOUT
        // refresh() - the re-include keeps executing the SHM-resident original
        $stdout = self::runShmPatchWorker('save', $fixture, $cacheDir);
        self::assertStringContainsString('shm-populated: ok', $stdout);
        self::assertStringContainsString('patched-literal: ok', $stdout);
        self::assertStringContainsString('stale-shm-after-save: ok', $stdout);
        self::assertStringContainsString('SHM SAVE OK', $stdout);

        // ...while the patched binary really is on disk: a fresh worker (whose
        // empty SHM cannot mask the file cache) executes the patched body, so
        // the stale 41 above was shared memory shielding the script - not a
        // patch that failed to land
        self::assertSame('value=42 shm=1', self::runShmWorker($fixture, $cacheDir));
    }

    public function testRefreshEvictsTheSharedMemoryResidentCopy(): void
    {
        $stdout = self::runShmPatchWorker('refresh', self::shmFixturePath(), self::freshShmCacheDir());

        self::assertStringContainsString('shm-populated: ok', $stdout);
        self::assertStringContainsString('patched-literal: ok', $stdout);
        self::assertStringContainsString('refresh-evicts-shm: ok', $stdout);
        self::assertStringContainsString('SHM REFRESH OK', $stdout);
    }

    /**
     * Patches the fixture's single long literal 41 -> 42 through the wrappers
     */
    private static function patchAnswerLiteral(BinaryCacheFile $file): void
    {
        $patched = 0;
        foreach ($file->getReflection()->getScriptFunction()->getLiterals() as $literal) {
            $literal->getNativeValue($value);
            if ($literal->getBaseType() === ReflectionValue::IS_LONG && $value === 41) {
                $literal->setNativeValue(42);
                ++$patched;
            }
        }
        self::assertSame(1, $patched, 'expected to patch exactly one literal in the fixture');
    }

    /**
     * Includes the fixture in a worker with shared memory active (file cache as
     * the second level, NOT file_cache_only) and returns "value=<v> shm=<0|1>"
     */
    private static function runShmWorker(string $fixture, string $cacheDir): string
    {
        $command = [
            PHP_BINARY,
            ...self::sharedMemoryOptions($cacheDir),
            __DIR__ . '/scripts/run-shm.php',
            $fixture,
        ];

        return self::runWorker($command, 'shared-memory run');
    }

    /**
     * Runs the include -> patch -> save()/refresh() sequence inside ONE worker
     * process whose private SHM holds the fixture, and returns its stdout
     */
    private static function runShmPatchWorker(string $mode, string $fixture, string $cacheDir): string
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'memory_limit=512M',
            ...self::sharedMemoryOptions($cacheDir),
            __DIR__ . '/scripts/shm-refresh-worker.php',
            $mode,
            $fixture,
            $cacheDir,
        ];

        return self::runWorker($command, "shared-memory {$mode}");
    }

    /**
     * The ini set that makes shared memory + second-level file cache active and
     * deterministic: no file_cache_only, no update protection (the fixture may
     * be freshly checked out), no timestamp validation (the legs compare cache
     * contents, not mtimes) and no JIT (it refuses the file cache)
     *
     * @return list<string>
     */
    private static function sharedMemoryOptions(string $cacheDir): array
    {
        return [
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . $cacheDir,
            '-d', 'opcache.file_cache_consistency_checks=1',
            '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
        ];
    }

    /**
     * @param list<string> $command
     */
    private static function runWorker(array $command, string $label): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, "Unable to spawn the {$label} worker");
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        if ($exitCode === 2) {
            // Never a silent pass: the shared-memory shape was not exercised at all
            self::markTestSkipped("Opcache shared memory could not be activated in the {$label} worker\n{$report}");
        }
        self::assertSame(0, $exitCode, "The {$label} worker failed ({$exitCode})\n{$report}");

        return trim($stdout);
    }

    private static function shmFixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/shm-answer.php');
        self::assertIsString($path);

        return $path;
    }

    /**
     * A per-test cache directory for the shared-memory workers. Opcache
     * disables the file cache when the directory does not exist, so it is
     * created here rather than left to the child.
     */
    private static function freshShmCacheDir(): string
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache extension is not loaded');
        }
        self::$cacheDir = sys_get_temp_dir() . '/zengine-opcache-' . bin2hex(random_bytes(6));
        if (!mkdir(self::$cacheDir, 0o755, true)) {
            self::fail('Cannot create the file-cache directory ' . self::$cacheDir);
        }

        return self::$cacheDir;
    }
}
