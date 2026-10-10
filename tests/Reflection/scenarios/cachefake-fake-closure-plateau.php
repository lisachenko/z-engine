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
use ZEngine\Reflection\FunctionLikeInterface;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Stub\Alias\CacheFakePlateauTarget;
use ZEngine\Type\ClosureEntry;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

// Plateau child of RedefineRunTimeCacheTest::testRetiredCachesAreStillReleasedWhileAFakeClosureIsAlive:
// a method is swapped a hundred times while a fake closure made over it stays alive. The first
// swap after the closure was made gives it a private heap copy of the shared block; every later
// swap must find no copy pointing at the block it retires and free it, so the cycle stays
// memory-flat and the closure keeps its one copy. The loop runs in this child because every
// swap walks the class table for the holders of the entry: with the few hundred classes of a
// bare process a cycle costs a few tens of milliseconds, with the thousands of a PHPUnit
// process ten times that.

/** Address of the run-time cache block a user function reads its inline caches from */
function cachefake_cache_address(FunctionLikeInterface $function): int
{
    $cache = $function->getOpArrayPointer()->run_time_cache__ptr;
    if ($cache === null) {
        fwrite(STDERR, "a swapped entry was left without a run-time cache\n");
        exit(1);
    }

    return Core::addressOf($cache);
}

/** Whether the function owns its run-time cache (ZEND_ACC_HEAP_RT_CACHE) */
function cachefake_has_heap_cache(FunctionLikeInterface $function): bool
{
    return ($function->getCommonPointer()->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0;
}

/** Pointer-level view of the zend_function embedded in a closure object (borrowed) */
function cachefake_closure_function(\Closure $closure): ReflectionFunction
{
    $closureEntry = new ClosureEntry($closure);

    return ReflectionFunction::fromCData(Core::addr($closureEntry->getRawFunction()));
}

$cycles = 100;
$method = (new ReflectionClass(CacheFakePlateauTarget::class))->getMethod('greet');
// Six call sites per body: a 32-byte block per swap (24 under opcache, whose optimizer folds
// the constant call), a few KiB over the measured cycles
$v1 = fn(string $who): string => 'v1:' . strtoupper($who) . str_repeat('!', 2) . strrev($who) . ucfirst($who) . trim($who) . substr($who, 0, 1);
$v2 = fn(string $who): string => 'v2:' . strtolower($who) . str_repeat('?', 2) . strrev($who) . ucfirst($who) . trim($who) . substr($who, 0, 1);

$method->redefine($v1);
$cacheSize  = $method->getOpArrayPointer()->cache_size;
$firstCache = cachefake_cache_address($method);
// The instance is created after the first swap: under opcache that swap copies the class out
// of shared memory, and an instance created before would keep the shared class entry
$instance = new CacheFakePlateauTarget();
$fake     = $instance->greet(...);
$shared   = cachefake_cache_address(cachefake_closure_function($fake)) === $firstCache;
$warmed   = $fake('ab');

// The next swap gives the closure its private copy; a few more settle the allocator
for ($i = 0; $i < 10; $i++) {
    $method->redefine($i % 2 === 0 ? $v2 : $v1);
}
$fakeFunction   = cachefake_closure_function($fake);
$privateCopy    = cachefake_cache_address($fakeFunction);
$heapAfterFirst = cachefake_has_heap_cache($fakeFunction);

gc_collect_cycles();
$before = memory_get_usage();
for ($i = 0; $i < $cycles; $i++) {
    $method->redefine($i % 2 === 0 ? $v2 : $v1);
}
gc_collect_cycles();
$growth = memory_get_usage() - $before;

$fakeFunction = cachefake_closure_function($fake);
echo json_encode([
    'cycles'           => $cycles,
    'cacheSize'        => $cacheSize,
    'growth'           => $growth,
    'sharedBeforeSwap' => $shared,
    'warmed'           => $warmed,
    'heapAfterFirst'   => $heapAfterFirst,
    'heapAfterCycles'  => cachefake_has_heap_cache($fakeFunction),
    'privateCopyKept'  => cachefake_cache_address($fakeFunction) === $privateCopy,
    'privateCopyIsOwn' => $privateCopy !== $firstCache && $privateCopy !== cachefake_cache_address($method),
    'entryResult'      => $instance->greet('cd'),
    'fakeResult'       => $fake('ef'),
]), PHP_EOL;
