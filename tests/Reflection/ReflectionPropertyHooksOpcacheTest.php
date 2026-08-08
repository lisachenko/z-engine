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

use PHPUnit\Framework\TestCase;

/**
 * Regression gate for the property-hook reflection path against an opcache-immutable
 * class: getHook() used to publish the transient method-table entry into the declaring
 * class's own function table, which lives in shared memory for an immutable class.
 * The insert forced a bucket-array resize with the request allocator, corrupting the
 * shared table - native reflection then saw an empty method table and the FFI walk
 * over it segfaulted the process.
 *
 * The scenario runs in a child because opcache.enable_cli cannot be toggled at
 * runtime, and the main suite intentionally runs without opcache.
 */
class ReflectionPropertyHooksOpcacheTest extends TestCase
{
    public function testHookReflectionLeavesAnImmutableClassIntact(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=off',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED),
            __DIR__ . '/scenarios/hook-reflection-under-opcache.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Unable to spawn the opcache scenario child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

        if ($exitCode === 2) {
            $this->markTestSkipped('opcache is not available for the scenario child');
        }
        if ($exitCode === 3) {
            $this->markTestSkipped('opcache did not serve the stub class as immutable');
        }

        // A segfault surfaces here as a signal exit code (139), not as a marker mismatch
        $this->assertSame(0, $exitCode, "Opcache scenario exited with code {$exitCode}\n{$report}");

        $expectedMarkers = [
            'class-is-immutable',
            'hook-name-intact',
            'hook-scope-intact',
            'hook-invokable',
            'native-table-intact',
            'engine-table-intact',
            'scenario-complete',
        ];
        foreach ($expectedMarkers as $marker) {
            $this->assertStringContainsString($marker, $stdout, "Missing marker {$marker}\n{$report}");
        }
    }
}
