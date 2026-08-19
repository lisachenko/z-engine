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
            // Pinned off so the scenario is hermetic whatever php.ini says (an
            // opcache-active runner, or Ubuntu's PHP 8.5 default opcache.enable_cli=On).
            // TODO(#243): with opcache active in this child the registered module is
            // not visible ("FAILED: engine-entry-visible") - unpin once fixed
            '-d', 'opcache.enable_cli=0',
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
