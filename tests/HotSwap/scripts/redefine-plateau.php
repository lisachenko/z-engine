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

use ZEngine\Core;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

function plateau_function(): string
{
    return 'original';
}

class PlateauClass
{
    public function target(): string
    {
        return 'original';
    }
}

$cycles      = 1000;
$refFunction = new ReflectionFunction('plateau_function');
$refMethod   = new ReflectionMethod(PlateauClass::class, 'target');
$instance    = new PlateauClass();

// Dispatch checks take the expected value as data: the bodies are replaced at
// runtime, so no statically-known return value applies
$functionDispatches = static function (string $expected): bool {
    return plateau_function() === $expected;
};
$methodDispatches = static function (string $expected) use ($instance): bool {
    return $instance->target() === $expected;
};

// A fixed rotation of equal-length payloads: every cycle compiles a brand-new
// op_array (fresh opcodes/literals/arg_info buffers), while the string literals
// intern once during warm-up - the measurement stays deterministic
$payloads = ['alpha', 'bravo', 'charl', 'delta'];
$sources  = [];
foreach ($payloads as $payload) {
    $sources[] = "return function (): string { return '{$payload}'; };";
}
$totalSources = count($sources);

// Warm up every code path so lazily-allocated engine state (allocator bins,
// interned strings, run-time caches) does not skew the measurement
for ($i = 0; $i < 50; $i++) {
    $source = $sources[$i % $totalSources];
    $body   = eval($source);
    unset($body);
    $body = eval($source);
    assert($body instanceof Closure);
    $refFunction->redefine($body);
    unset($body);
    $expected = $payloads[$i % $totalSources];
    if (!$functionDispatches($expected)) {
        fwrite(STDERR, "warm-up dispatch failed\n");
        exit(1);
    }
    $body = eval($source);
    assert($body instanceof Closure);
    $refMethod->redefine($body);
    unset($body);
    if (!$methodDispatches($expected)) {
        fwrite(STDERR, "warm-up method dispatch failed\n");
        exit(1);
    }
}
gc_collect_cycles();

// Baseline: the engine's own per-eval compile cost (op_array containers live in
// the request arena and are only reclaimed at request end)
$before = memory_get_usage();
for ($i = 0; $i < $cycles; $i++) {
    $body = eval($sources[$i % $totalSources]);
    unset($body);
}
gc_collect_cycles();
$evalOnlyGrowth = memory_get_usage() - $before;

// Function redefine on top of the same eval pattern: each cycle compiles a brand
// new body, swaps it in, verifies dispatch and destroys the previous body
$before = memory_get_usage();
for ($i = 0; $i < $cycles; $i++) {
    $body = eval($sources[$i % $totalSources]);
    assert($body instanceof Closure);
    $refFunction->redefine($body);
    unset($body);
    if (!$functionDispatches($payloads[$i % $totalSources])) {
        fwrite(STDERR, "wrong function body at cycle {$i}\n");
        exit(1);
    }
}
gc_collect_cycles();
$functionGrowth = memory_get_usage() - $before;

// Method redefine, same protocol
$before = memory_get_usage();
for ($i = 0; $i < $cycles; $i++) {
    $body = eval($sources[$i % $totalSources]);
    assert($body instanceof Closure);
    $refMethod->redefine($body);
    unset($body);
    if (!$methodDispatches($payloads[$i % $totalSources])) {
        fwrite(STDERR, "wrong method body at cycle {$i}\n");
        exit(1);
    }
}
gc_collect_cycles();
$methodGrowth = memory_get_usage() - $before;

// Fixed donors: no compilation in the loop at all, pure swap churn
$firstDonor = static function (): string {
    return 'A';
};
$secondDonor = static function (): string {
    return 'B';
};
$refFunction->redefine($firstDonor);
gc_collect_cycles();
$before = memory_get_usage();
for ($i = 0; $i < $cycles; $i++) {
    $refFunction->redefine($i % 2 ? $firstDonor : $secondDonor);
    if (!$functionDispatches($i % 2 ? 'A' : 'B')) {
        fwrite(STDERR, "wrong fixed-donor body at cycle {$i}\n");
        exit(1);
    }
}
gc_collect_cycles();
$fixedDonorGrowth = memory_get_usage() - $before;

echo json_encode([
    'cycles'     => $cycles,
    'evalOnly'   => $evalOnlyGrowth,
    'function'   => $functionGrowth,
    'method'     => $methodGrowth,
    'fixedDonor' => $fixedDonorGrowth,
]), PHP_EOL;
