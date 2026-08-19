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
 * Request-cycle simulation for the module-anchored global heap (issue #100 acceptance):
 * a graph put in "request A" must be readable and fully functional after a simulated
 * RSHUTDOWN/RINIT cycle delivered through the module lifecycle hooks.
 *
 * The scenario runs in a child process because it registers the persistent heap module
 * in the engine module registry, which cannot be undone within a process. The same
 * scenario file is picked up by the debug-build leak gate (MemoryLeakScenarioTest).
 */
class PersistentHeapRequestCycleTest extends TestCase
{
    public function testGraphSurvivesASimulatedRequestCycle(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'report_memleaks=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED),
            // Pinned ON (JIT off, AGENTS.md) so the child hermetically runs the exact
            // configuration issue #243 failed in: opcache's optimizer constant-folds
            // literal extension_loaded() calls at compile time, which the scenario's
            // engine-entry-visible checkpoint dodges with a runtime-built name. The
            // opcache-off leg stays covered by the debug leak gate
            // (MemoryLeakScenarioTest spawns the same scenario with php.ini defaults).
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            __DIR__ . '/scenarios/persistent-heap-request-cycle.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Unable to spawn the request-cycle child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

        $this->assertSame(0, $exitCode, "Request-cycle scenario exited with code {$exitCode}\n{$report}");

        $expectedMarkers = [
            'no-silent-bootstrap',
            'module-registered',
            'module-is-singleton',
            'engine-entry-visible',
            'global-is-module-heap',
            'put-in-request-a',
            'get-in-request-a',
            'clone-is-not-the-source',
            'inert-after-rshutdown',
            'readable-after-rinit',
            'method-dispatch-works',
            'cycle-back-edge-intact',
            'dag-share-intact',
            'shared-leaf-data-intact',
            'array-data-intact',
            'alias-identity-within-request',
            'fresh-distinct-handles',
            'graph-survives-gc',
            'evicted-in-request-b',
            'phpinfo-has-module-section',
            'phpinfo-heap-active',
            'phpinfo-heap-stats',
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
