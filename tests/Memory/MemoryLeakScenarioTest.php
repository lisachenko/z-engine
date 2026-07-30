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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Leak gate: every scenario runs in a child debug-PHP process with report_memleaks=1 and
 * must terminate cleanly without a single memory-manager leak report line.
 *
 * On release builds the memory manager produces no leak reports, so these tests only make
 * sense (and only run) on a debug build - ie inside the tests-internal-debug CI container.
 */
#[Group('internal')]
final class MemoryLeakScenarioTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_DEBUG === 0) {
            self::markTestSkipped('Memory leak reports require a debug PHP build');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarioProvider(): iterable
    {
        foreach (glob(__DIR__ . '/scenarios/*.php') ?: [] as $scenario) {
            yield basename($scenario, '.php') => [$scenario];
        }
    }

    #[DataProvider('scenarioProvider')]
    public function testScenarioRunsLeakFree(string $scenarioFile): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'report_memleaks=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'memory_limit=-1',
            $scenarioFile,
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the scenario child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

        self::assertSame(0, $exitCode, "Scenario exited with code {$exitCode}\n{$report}");
        self::assertStringContainsString('SCENARIO OK', $stdout, "Scenario did not complete\n{$report}");
        // The debug memory manager prints one "Freeing 0x..." line per leaked block
        self::assertDoesNotMatchRegularExpression('/Freeing 0x[0-9a-f]+/i', $stdout . $stderr, $report);
        self::assertStringNotContainsStringIgnoringCase('memory leaks detected', $stdout . $stderr, $report);
        self::assertStringNotContainsStringIgnoringCase('heap corrupted', $stdout . $stderr, $report);
    }
}
