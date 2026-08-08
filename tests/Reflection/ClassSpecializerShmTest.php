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

namespace ZEngine\Reflection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the opcache-immutable (shared memory) branch of the class specialization
 * copy-out (docs/class-specialization.md): a child PHP process preloads the template
 * fixture - preloaded classes are persisted into opcache SHM with ZEND_ACC_IMMUTABLE -
 * and the probe script then specializes the immutable template, exercises the copy and
 * verifies the shared-memory original was left byte-for-byte untouched.
 *
 * The probe hard-fails (dedicated exit code, asserted here) if the preloaded template is
 * NOT immutable, so this test can never silently pass against a mutable class. It only
 * skips when the opcache extension itself is unavailable.
 */
#[Group('opcache')]
final class ClassSpecializerShmTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('The opcache extension is required to build an immutable (SHM) template');
        }
        // opcache.preload does not exist on Windows, so the template can never be
        // persisted into shared memory there (issue #119)
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('opcache.preload is not available on Windows (issue #119)');
        }
    }

    public function testSpecializesImmutableSharedMemoryClass(): void
    {
        $fixtureDir = dirname(__DIR__) . '/Stub';
        $command    = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.preload=' . $fixtureDir . '/specializationShmPreload.php',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            $fixtureDir . '/specializationShmProbe.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the SHM probe child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

        // A broken preload setup is a test FAILURE, never a silent pass: the probe
        // verified ZEND_ACC_IMMUTABLE on the template before doing anything else
        self::assertStringNotContainsString(
            'TEMPLATE-NOT-IMMUTABLE',
            $stdout,
            "Preload did not produce an immutable template - the SHM branch was not exercised\n{$report}",
        );
        self::assertSame(0, $exitCode, "SHM probe exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('SHM PROBE OK', $stdout, "SHM probe did not complete\n{$report}");
    }
}
