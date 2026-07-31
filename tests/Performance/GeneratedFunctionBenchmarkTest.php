<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionFunction;

/**
 * Hand-declared baseline: an ordinary PHP function compiled to a zend_op_array
 */
function benchmarkDeclaredTwice(int $x): int
{
    return $x * 2;
}

/**
 * Demonstrates that a generated function carries no per-call FFI overhead
 *
 * A function published with ReflectionFunction::addFunction() dispatches through the normal
 * Zend VM exactly like a hand-declared function (Path B in docs/memory-model.md). This
 * benchmark asserts the two run in the same ballpark - if the generated function were routed
 * through an FFI trampoline (Path A), it would be several times slower. The test lives in the
 * excluded `performance` group and is not run by the default suite.
 */
#[Group('performance')]
class GeneratedFunctionBenchmarkTest extends TestCase
{
    private const ITERATIONS = 500_000;

    public function testGeneratedFunctionMatchesDeclaredSpeed(): void
    {
        // The generated function keeps its body alive until request end (immortal-by-design)
        ini_set('report_memleaks', '0');

        $generatedName = 'zengine_benchmark_twice';
        ReflectionFunction::addFunction($generatedName, static fn (int $x): int => $x * 2);

        $declared  = $this->time(static function (): void {
            for ($i = 0; $i < self::ITERATIONS; $i++) {
                benchmarkDeclaredTwice($i);
            }
        });
        $generated = $this->time(static function () use ($generatedName): void {
            for ($i = 0; $i < self::ITERATIONS; $i++) {
                $generatedName($i);
            }
        });

        fwrite(STDERR, sprintf(
            "\n[generated-function] declared=%.4fs generated=%.4fs ratio=%.2f\n",
            $declared,
            $generated,
            $generated / $declared,
        ));

        // A generated function is real bytecode on the native VM, so it must run within the same
        // order of magnitude as a hand-declared one. A trampoline-backed call would blow past this.
        $this->assertLessThan(
            3.0,
            $generated / $declared,
            'Generated function should run at hand-declared-function speed (no per-call FFI overhead)',
        );
    }

    private function time(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (hrtime(true) - $start) / 1e9;
    }
}
