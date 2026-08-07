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

namespace ZEngine\Memory;

use PHPUnit\Framework\TestCase;

/**
 * Re-boot simulation for the process-wide FFI binding (issue #108): a second Core::init()
 * in one process must reuse the binding, so CData minted before the re-boot - module
 * entries, the heap anchor - stays valid through the re-booted request and the exit-time
 * shutdown chain.
 *
 * The scenario runs in a child process because it registers the persistent heap module in
 * the engine module registry, which cannot be undone within a process. The same scenario
 * file is picked up by the debug-build leak gate (MemoryLeakScenarioTest).
 */
class CoreReinitRequestCycleTest extends TestCase
{
    public function testBindingSurvivesAShutdownReinitCycle(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'report_memleaks=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED),
            __DIR__ . '/scenarios/core-reinit-request-cycle.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Unable to spawn the re-init child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

        $this->assertSame(0, $exitCode, "Re-init scenario exited with code {$exitCode}\n{$report}");

        $expectedMarkers = [
            'cdata-survives-double-init',
            'put-in-request-a',
            'inert-after-rshutdown',
            'core-shutdown-simulated',
            'reinit-done',
            'module-entry-valid-after-reinit',
            'graph-recovered-after-reinit',
            'new-allocations-work-after-reinit',
            'evicted-after-reinit',
            'SCENARIO OK',
        ];
        $offset = 0;
        foreach ($expectedMarkers as $marker) {
            $position = strpos($stdout, $marker, $offset);
            $this->assertNotFalse($position, "Marker '{$marker}' missing or out of order\n{$report}");
            $offset = $position + strlen($marker);
        }
    }
}
