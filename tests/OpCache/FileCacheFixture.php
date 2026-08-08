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

use PHPUnit\Framework\Assert;

/**
 * Shared harness for tests that need a real opcache file-cache binary: spawns
 * a child PHP with the file cache enabled, compiles the fixture script into a
 * per-test cache directory and hands back the paths.
 */
trait FileCacheFixture
{
    private static string $cacheDir = '';

    /**
     * Compiles the given script into a fresh file-cache directory and returns
     * the produced .bin path. Skips the test when opcache is unavailable.
     */
    private static function compileFixture(?string $scriptPath = null): string
    {
        if (!extension_loaded('Zend OPcache')) {
            Assert::markTestSkipped('Zend OPcache extension is not loaded');
        }
        if (\DIRECTORY_SEPARATOR !== '/') {
            // The bin path below assumes opcache's POSIX file-cache layout. Windows
            // inserts an extra accel_uname_id directory level and appends the script
            // path with its drive prefix and backslashes - tracked in issue #119.
            Assert::markTestSkipped('The file-cache fixture assumes the POSIX cache layout (issue #119)');
        }
        $scriptPath     = $scriptPath ?? self::fixturePath();
        self::$cacheDir = sys_get_temp_dir() . '/zengine-opcache-' . bin2hex(random_bytes(6));
        if (!mkdir(self::$cacheDir, 0777, true)) {
            Assert::fail('Cannot create the file-cache directory ' . self::$cacheDir);
        }

        $command = [
            PHP_BINARY,
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . self::$cacheDir,
            '-d', 'opcache.file_cache_only=1',
            // The file cache refuses to store scripts while the JIT is active
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            '-r', 'exit(function_exists("opcache_compile_file") ? (opcache_compile_file($argv[1]) ? 0 : 1) : 2);',
            '--',
            $scriptPath,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process, 'Unable to spawn the compile child process');
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === 2) {
            Assert::markTestSkipped("Opcache could not be activated in the compile child\n{$stdout}\n{$stderr}");
        }
        Assert::assertSame(0, $exitCode, "Compile child failed ({$exitCode})\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");

        $binPath = self::$cacheDir . '/' . \ZEngine\Core::systemId() . realpath($scriptPath) . '.bin';

        Assert::assertFileExists($binPath, 'The compile child did not produce the expected cache binary');

        return $binPath;
    }

    /**
     * Runs the given script strictly from the file cache in a child process and
     * returns its stdout, proving the engine executed whatever binary the cache
     * dir holds. Skips the test when opcache cannot be activated in the child.
     */
    private static function runFromCache(string $scriptPath, string $target, string $cacheDir): string
    {
        $command = [
            PHP_BINARY,
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . $cacheDir,
            '-d', 'opcache.file_cache_only=1',
            '-d', 'opcache.file_cache_consistency_checks=1',
            '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            __DIR__ . '/scripts/run-cached.php',
            $scriptPath,
            $target,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process, 'Unable to spawn the cache-run child process');
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === 2) {
            Assert::markTestSkipped("Opcache could not be activated in the cache-run child\n{$stderr}");
        }
        Assert::assertSame(0, $exitCode, "Cache-run child failed ({$exitCode})\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");

        return trim($stdout);
    }

    private static function fixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/answer.php');
        Assert::assertIsString($path);

        return $path;
    }

    private static function removeCacheDir(): void
    {
        if (self::$cacheDir === '') {
            return;
        }
        self::removeDirectory(self::$cacheDir);
        self::$cacheDir = '';
    }

    /**
     * Deletes a directory tree, in PHP so it works on every platform (no `rm -rf`)
     *
     * A missing directory is not an error: callers clean up cache dirs that a skipped
     * or half-way failed test may never have created.
     */
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
