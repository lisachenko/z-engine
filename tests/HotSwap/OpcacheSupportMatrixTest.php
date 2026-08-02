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

use PHPUnit\Framework\TestCase;

/**
 * Opcache shared-memory support matrix (resolves the ambiguity of issue #41)
 *
 * A child process runs with opcache enabled so classes/functions loaded from a
 * file are published from shared memory with ZEND_ACC_IMMUTABLE. The child
 * asserts the documented matrix (docs/hot-swap.md):
 *
 *  - immutable global function + redefine(): copy-out into a writable entry works
 *  - immutable class + redefine()/addMethod()/HotSwap: typed SharedMemoryException
 *  - runtime-declared classes keep the full mutation surface under opcache
 */
class OpcacheSupportMatrixTest extends TestCase
{
    public function testSharedMemorySupportMatrix(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache extension is not loaded');
        }

        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'memory_limit=512M',
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            // The JIT rewrites the executor internals z-engine hooks into (AGENTS.md)
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            __DIR__ . '/scripts/opcache-matrix.php',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the opcache child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        if ($exitCode === 2) {
            self::markTestSkipped("Opcache could not be activated in the child process\n{$report}");
        }
        self::assertSame(0, $exitCode, "Opcache matrix child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('function-copy-out: ok', $stdout, $report);
        self::assertStringContainsString('method-redefine-rejected: ok', $stdout, $report);
        self::assertStringContainsString('add-method-rejected: ok', $stdout, $report);
        self::assertStringContainsString('hot-swap-rejected: ok', $stdout, $report);
        self::assertStringContainsString('runtime-class-swap: ok', $stdout, $report);
        self::assertStringContainsString('MATRIX OK', $stdout, $report);
    }
}
