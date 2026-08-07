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
 * Pins WHY z-engine ships no zend_throw_exception_hook wrapper: the engine fires the
 * hook with EG(exception) already set, and ext/ffi aborts any C-to-PHP trampoline in
 * that state ("Throwing from FFI callbacks is not allowed"), so a userland callback
 * can never observe the throw - it only breaks the script's exception semantics.
 * Same root cause as the observer end-handler finding of PR #106.
 *
 * The experiment runs in a sacrificial child process; if a future engine or ext-ffi
 * version lifts the restriction this test goes red and the wrapper (dropped from the
 * public surface on purpose) becomes worth restoring.
 */
final class ThrowExceptionHookAbortTest extends TestCase
{
    public function testFfiTrampolineAbortsInsteadOfObservingTheThrow(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            __DIR__ . '/scenarios/throw-hook-ffi-abort.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the sacrificial child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $output = $stdout . $stderr;

        self::assertNotSame(0, $exitCode, "The child survived a throw under the hook\n{$report}");
        self::assertStringContainsString('Throwing from FFI callbacks is not allowed', $output, $report);
        // The callback never entered PHP and the catch block never ran
        self::assertStringNotContainsString('HOOK-FIRED', $output, $report);
        self::assertStringNotContainsString('CAUGHT', $output, $report);
        self::assertStringNotContainsString('SURVIVED', $output, $report);
    }
}
