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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Child-process matrix for the preserved-alias redefine and the addMethod() override
 * (tests/HotSwap/scripts/redefine-alias-matrix.php)
 *
 * Two dimensions, each in its own process so the probed state is exactly the one
 * described:
 *
 *  - opcache off / on: with opcache the stub classes are immutable shared-memory entries
 *    and every operation runs the copy-out path first;
 *  - the Zend allocator / the system allocator (USE_ZEND_ALLOC=0): with the system
 *    allocator the engine shuts down through its table destructors instead of discarding
 *    the request heap wholesale, which is the only shutdown that frees the run-time cache
 *    of an entry once per method table publishing it - the double free a heap-flagged
 *    cache shared by a parent and a subclass table used to produce.
 */
class RedefineAliasMatrixTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function configurations(): iterable
    {
        yield 'opcache off, Zend allocator' => [false, false];
        yield 'opcache on, Zend allocator' => [true, false];
        yield 'opcache off, system allocator' => [false, true];
        yield 'opcache on, system allocator' => [true, true];
    }

    #[DataProvider('configurations')]
    public function testAliasScenariosHoldAndShutDownCleanly(bool $opcache, bool $systemAllocator): void
    {
        if ($opcache && !extension_loaded('Zend OPcache')) {
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
            '-d', 'opcache.enable=' . ($opcache ? '1' : '0'),
            '-d', 'opcache.enable_cli=' . ($opcache ? '1' : '0'),
            // The JIT rewrites the executor internals z-engine hooks into (AGENTS.md)
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            // Fresh stub files must still be published from shared memory
            '-d', 'opcache.file_update_protection=0',
            __DIR__ . '/scripts/redefine-alias-matrix.php',
            $opcache ? '1' : '0',
        ];
        $environment = getenv();
        if ($systemAllocator) {
            $environment['USE_ZEND_ALLOC'] = '0';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process, 'Unable to spawn the matrix child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        if ($exitCode === 2) {
            self::markTestSkipped("The requested opcache mode could not be activated in the child process\n{$report}");
        }
        self::assertSame(0, $exitCode, "Matrix child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('redefine-alias-matrix: ok', $stdout, $report);
    }
}
