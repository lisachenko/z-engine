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

/**
 * Opcache shared-memory support matrix (the regression test of issue #41)
 *
 * A child process runs with opcache enabled so classes/functions loaded from a
 * file are published from shared memory with ZEND_ACC_IMMUTABLE. The child
 * asserts the documented matrix (docs/hot-swap.md):
 *
 *  - immutable global function + redefine(): copy-out into a writable entry works
 *  - immutable class + method redefine()/addMethod()/HotSwap: the class entry is
 *    copied out of shared memory and the mutation applies to the writable copy,
 *    while the shared-memory original stays byte-for-byte untouched
 *  - runtime-declared classes keep the full mutation surface under opcache
 *
 * The child exit code doubles as the shutdown check of issue #41, whose original
 * symptom was a zend_function_dtor() assertion failure and a SIGABRT while the
 * request was being torn down.
 */
#[Group('opcache')]
class OpcacheSupportMatrixTest extends TestCase
{
    public function testSharedMemorySupportMatrix(): void
    {
        [$exitCode, $stdout, $report] = $this->runOpcacheChild(__DIR__ . '/scripts/opcache-matrix.php');

        self::assertSame(0, $exitCode, "Opcache matrix child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('function-copy-out: ok', $stdout, $report);
        self::assertStringContainsString('method-redefine: ok', $stdout, $report);
        self::assertStringContainsString('add-method: ok', $stdout, $report);
        self::assertStringContainsString('hot-swap: ok', $stdout, $report);
        self::assertStringContainsString('runtime-class-swap: ok', $stdout, $report);
        self::assertStringContainsString('static-vars-live-table: ok', $stdout, $report);
        self::assertStringContainsString('MATRIX OK', $stdout, $report);
    }

    /**
     * The loud guard of issue #238: handlers installed from an interface_gets_implemented
     * hook target the temporary class entry opcache links classes on, and the temporary is
     * discarded once the inheritance cache persists the linked result - so the installation
     * must throw instead of being silently lost (issue #241 tracks making it stick by
     * declining the inheritance cache for hooked classes)
     */
    public function testHandlerInstallationDuringLazyLinkingIsRejected(): void
    {
        [$exitCode, $stdout, $report] = $this->runOpcacheChild(
            __DIR__ . '/scripts/opcache-interface-hook.php',
            // The lazy-linking path engages only for scripts opcache actually cached: the
            // default opcache.file_update_protection=2 refuses files modified less than 2s
            // before the request (a fresh checkout), silently degrading the reproduction
            ['-d', 'opcache.file_update_protection=0'],
        );

        self::assertSame(0, $exitCode, "Interface-hook child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('lazy-linking-guard: ok', $stdout, $report);
        self::assertStringContainsString('INTERFACE HOOK OK', $stdout, $report);
    }

    /**
     * A preloaded class entry is the one shared-memory shape the copy-out refuses:
     * its class-table bucket is reused by every request of the worker process, while
     * the copy would die with this one
     */
    public function testPreloadedClassMutationIsRejected(): void
    {
        // Only this leg needs opcache.preload, which does not exist on Windows (issue #119)
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('opcache.preload is not available on Windows (issue #119)');
        }

        [$exitCode, $stdout, $report] = $this->runOpcacheChild(
            __DIR__ . '/scripts/opcache-preloaded.php',
            ['-d', 'opcache.preload=' . dirname(__DIR__) . '/Stub/specializationShmPreload.php'],
        );

        self::assertSame(0, $exitCode, "Preloaded-class child exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('preloaded-rejected: ok', $stdout, $report);
    }

    /**
     * Runs a probe script in a child process with opcache activated
     *
     * @param list<string> $extraOptions Additional `php -d` options for this probe
     *
     * @return array{int, string, string} Exit code, stdout and a stdout+stderr report
     */
    private function runOpcacheChild(string $scriptPath, array $extraOptions = []): array
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
            ...$extraOptions,
            $scriptPath,
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
            // Never a silent pass: the shared-memory branch was not exercised at all
            self::markTestSkipped("Opcache could not be activated in the child process\n{$report}");
        }

        return [$exitCode, $stdout, $report];
    }
}
