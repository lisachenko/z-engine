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

namespace ZEngine\System\Hook;

use PHPUnit\Framework\TestCase;

/**
 * Observer bridge behaviour on the opcache.preload boot path, exercised in a child process because
 * observer registration timing only exists during engine startup and cannot be reproduced in the
 * PHPUnit worker (which is already past startup).
 *
 * This pins the observed/unobserved boundary on a stock build: the engine freezes its
 * fcall-observer configuration during startup (zend_observer_post_startup) BEFORE the preload
 * script runs, so with no startup-time observer provider present the machinery is disabled and
 * ObserverHook refuses to attach with a typed exception rather than corrupting memory. See
 * docs/observer-hook.md for the full analysis.
 */
final class ObserverHookPreloadTest extends TestCase
{
    public function testPreloadPathReportsDisabledObserversAndRefusesToAttach(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('opcache is required to exercise the preload path');
        }

        $fixture   = dirname(__DIR__, 2) . '/Stub/observerPreloadProbe.php';
        $reportOut = tempnam(sys_get_temp_dir(), 'zobs_');
        self::assertIsString($reportOut);

        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.preload=' . $fixture,
            '-r', 'echo "REQUEST_OK\n";',
        ];

        /** @var array<string, string> $inheritedEnv */
        $inheritedEnv = getenv();
        $environment  = ['ZOBS_OUT' => $reportOut] + $inheritedEnv;
        $process      = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process, 'Unable to spawn the preload child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = (string) file_get_contents($reportOut);
        @unlink($reportOut);
        $context = "EXIT={$exitCode}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}\nREPORT:\n{$report}";

        if (str_contains($stderr, 'Preloading is not supported') || str_contains($stderr, 'preload_user')) {
            self::markTestSkipped("Preloading unavailable in this environment:\n{$context}");
        }

        self::assertSame(0, $exitCode, "Preload child exited abnormally\n{$context}");
        self::assertStringContainsString('REQUEST_OK', $stdout, "Request did not run after preload\n{$context}");

        // The engine booted through the preload path...
        self::assertStringContainsString('PRELOADED=1', $report, $context);
        // ...but on a stock build (no startup observer provider) the machinery stays disabled...
        self::assertStringContainsString('OBSERVER_ENABLED=0', $report, $context);
        // ...so ObserverHook refuses to attach instead of writing into unsized structures.
        self::assertStringContainsString('OBSERVE=rejected', $report, $context);
    }
}
