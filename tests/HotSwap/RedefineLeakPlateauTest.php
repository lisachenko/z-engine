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

use PHPUnit\Framework\TestCase;

/**
 * Leak-plateau proof for issue #64: 1000 consecutive redefine() cycles on the same
 * function/method must not grow memory beyond the engine's own per-eval compile
 * cost (arena blocks for the eval'd op_array containers, which the engine frees
 * only at request end and which exist with or without the redefine call).
 *
 * The child process measures four series over 1000 cycles each:
 *  - evalOnly:   compile-and-discard a closure per cycle (engine baseline)
 *  - function:   compile + redefine a global function per cycle
 *  - method:     compile + redefine a method per cycle
 *  - fixedDonor: alternate between two pre-compiled donors (no compilation at all)
 *
 * Before the fix the function/method series grew by a full function body per cycle
 * over the baseline; with the previous body destroyed the redefine cost is zero.
 */
class RedefineLeakPlateauTest extends TestCase
{
    /**
     * Tolerance (bytes) for the redefine overhead above the eval baseline.
     *
     * The retained memory both series measure lives in the request arena, which
     * memory_get_usage() observes in whole ZEND_MM_CHUNK_SIZE (64KB) steps - the
     * measurement cannot resolve differences below one chunk, and a code-size or
     * allocation-order change anywhere in the process shifts the intra-chunk
     * alignment enough to flip a boundary crossing deterministically. One chunk of
     * slack therefore distinguishes alignment from scaling: a genuine per-cycle leak
     * crosses additional chunks as cycles grow (verified: quadrupling the cycles
     * kept the overhead at exactly one chunk), while anything at or below one chunk
     * is rounding. Leaks >= ~66 bytes/cycle still fail at 1000 cycles.
     */
    private const TOLERANCE_BYTES = 64 * 1024;

    public function testThousandRedefineCyclesAreMemoryFlat(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'memory_limit=512M',
            __DIR__ . '/scripts/redefine-plateau.php',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the plateau child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        self::assertSame(0, $exitCode, "Plateau child exited with code {$exitCode}\n{$report}");

        // The metrics are the last stdout line (diagnostics may precede them)
        $stdoutLines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        $lastLine    = $stdoutLines === [] ? '' : end($stdoutLines);
        $metrics     = json_decode($lastLine, true);
        self::assertIsArray($metrics, "Plateau child produced no metrics\n{$report}");
        self::assertIsInt($metrics['evalOnly']);
        self::assertIsInt($metrics['function']);
        self::assertIsInt($metrics['method']);
        self::assertIsInt($metrics['fixedDonor']);

        // Redefine must not cost anything beyond the engine's own eval baseline
        $functionOverhead = $metrics['function'] - $metrics['evalOnly'];
        $methodOverhead   = $metrics['method']   - $metrics['evalOnly'];
        self::assertLessThanOrEqual(
            self::TOLERANCE_BYTES,
            $functionOverhead,
            "Function redefine leaked {$functionOverhead} bytes over 1000 cycles\n{$report}",
        );
        self::assertLessThanOrEqual(
            self::TOLERANCE_BYTES,
            $methodOverhead,
            "Method redefine leaked {$methodOverhead} bytes over 1000 cycles\n{$report}",
        );

        // Pure swap churn with pre-compiled donors must be perfectly flat
        self::assertLessThanOrEqual(
            self::TOLERANCE_BYTES,
            $metrics['fixedDonor'],
            "Fixed-donor redefine churn grew by {$metrics['fixedDonor']} bytes\n{$report}",
        );
    }
}
