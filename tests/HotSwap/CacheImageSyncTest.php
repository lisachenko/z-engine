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

namespace ZEngine\HotSwap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\OpCache\FileCacheFixture;
use ZEngine\OpCache\PayloadRelocator;

/**
 * The file-cache -> runtime bridge end to end (issue #122): a patched
 * ReflectionOpcacheFile image is applied to the functions and classes ALREADY
 * LOADED in a live process, without re-including the script.
 *
 * Each probe runs in a child process (the bridge mutates executor tables and
 * the child's clean exit doubles as the shutdown check), with the cache binary
 * compiled into a per-test directory owned by this class:
 *
 *  - plain child (opcache off): unchanged-noop diff, patched apply, live
 *    dispatch of the patched bodies, idempotent re-diff, single-use sync;
 *  - shared-memory child (opcache on): the same loop against immutable
 *    entries, proving the copy-out path and the untouched SHM originals;
 *  - refusal child: never-loaded images report not-loaded entries instead of
 *    crashing, unchanged enums pass, changed enum methods throw loudly.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
class CacheImageSyncTest extends TestCase
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

    public function testAppliesPatchedImageToLoadedEntries(): void
    {
        [$exitCode, $stdout, $report] = $this->runImageSyncChild(__DIR__ . '/scripts/cache-image-sync.php');

        self::assertSame(0, $exitCode, "Image-sync child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('noop-diff: ok', $stdout, $report);
        self::assertStringContainsString('patched-apply: ok', $stdout, $report);
        self::assertStringContainsString('live-dispatch: ok', $stdout, $report);
        self::assertStringContainsString('idempotency: ok', $stdout, $report);
        self::assertStringContainsString('IMAGE SYNC OK', $stdout, $report);
    }

    public function testAppliesPatchedImageToSharedMemoryEntries(): void
    {
        [$exitCode, $stdout, $report] = $this->runImageSyncChild(
            __DIR__ . '/scripts/cache-image-sync-shm.php',
            [
                '-d', 'opcache.enable=1',
                '-d', 'opcache.enable_cli=1',
                // A freshly checked out fixture is younger than the default
                // 2-second update protection and would silently not be cached
                '-d', 'opcache.file_update_protection=0',
            ],
        );

        self::assertSame(0, $exitCode, "SHM image-sync child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('shm-noop-diff: ok', $stdout, $report);
        self::assertStringContainsString('shm-apply: ok', $stdout, $report);
        self::assertStringContainsString('shm-dispatch: ok', $stdout, $report);
        self::assertStringContainsString('shm-copy-out: ok', $stdout, $report);
        self::assertStringContainsString('shm-idempotency: ok', $stdout, $report);
        self::assertStringContainsString('IMAGE SYNC SHM OK', $stdout, $report);
    }

    public function testReportsNotLoadedEntriesAndRefusesEnumsLoudly(): void
    {
        [$exitCode, $stdout, $report] = $this->runImageSyncChild(__DIR__ . '/scripts/cache-image-sync-refusals.php');

        self::assertSame(0, $exitCode, "Refusal child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('not-loaded-report: ok', $stdout, $report);
        self::assertStringContainsString('unchanged-enum: ok', $stdout, $report);
        self::assertStringContainsString('refused-enum: ok', $stdout, $report);
        self::assertStringContainsString('IMAGE SYNC REFUSALS OK', $stdout, $report);
    }

    /**
     * Runs one bridge probe in a child process with a fresh cache directory
     *
     * @param list<string> $extraOptions Additional `php -d` options (the opcache leg)
     *
     * @return array{int, string, string} Exit code, stdout and a stdout+stderr report
     */
    private function runImageSyncChild(string $scriptPath, array $extraOptions = []): array
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache extension is not loaded');
        }
        self::$cacheDir = sys_get_temp_dir() . '/zengine-image-sync-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir(self::$cacheDir, 0o755, true), 'Cannot create the file-cache directory');

        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'memory_limit=512M',
            // The JIT rewrites the executor internals z-engine hooks into (AGENTS.md)
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            // Hermeticity: the bridge diffs an image against a live entry compiled at
            // the SAME optimization level, and the plain/refusal legs deliberately
            // pair an optimizer-off image (compiled by BinaryCacheFile::compile with
            // opcache.optimization_level=0) with an unoptimized live side loaded from
            // source. The opcache-runner CI job sets opcache.enable_cli=1 in php.ini,
            // which would otherwise leak into this child and optimize its live-side
            // require - producing a genuinely different (spuriously non-empty) diff.
            // Pin CLI opcache off here so the plain leg is deterministic whatever the
            // runner's php.ini says; the shared-memory leg re-enables it via
            // $extraOptions, which come last and win (php applies -d in order).
            '-d', 'opcache.enable_cli=0',
            ...$extraOptions,
            $scriptPath,
            self::$cacheDir,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the image-sync child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        if ($exitCode === 2) {
            // Never a silent pass: the branch under test was not exercised at all
            self::markTestSkipped("Opcache could not be activated in the child process\n{$report}");
        }

        return [$exitCode, $stdout, $report];
    }
}
