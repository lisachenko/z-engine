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

namespace ZEngine\Reflection;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Stub\Alias\CacheFakeAliasDonor;
use ZEngine\Stub\Alias\CacheFakeAliasTarget;
use ZEngine\Stub\Alias\CacheFakeCloneBase;
use ZEngine\Stub\Alias\CacheFakeCloneChild;
use ZEngine\Stub\Alias\CacheFakeClosureTarget;
use ZEngine\Stub\Alias\CacheFakeLiveFrameTarget;
use ZEngine\Stub\Alias\CacheFakeMethodDonor;
use ZEngine\Stub\Alias\CacheFakeMethodTarget;
use ZEngine\Type\ClosureEntry;

/**
 * The run-time cache a body swap mints for an entry, and the engine copies of the entry that
 * point at it when the next swap releases the block
 *
 * A swapped entry gets a fresh request-memory run-time cache WITHOUT ZEND_ACC_HEAP_RT_CACHE
 * (a heap-flagged block shared by a parent and a subclass table would be freed once per
 * table), so the block is released by z-engine itself: the next swap of the entry frees it.
 * The engine, however, copies the entry structure - cache pointer included - wherever it
 * needs a zend_function of its own: zend_create_closure_ex shares a scope-matching non-heap
 * cache with every fake closure made over the entry (Closure::fromCallable(), a first-class
 * callable, ReflectionMethod::getClosure()), and do_inheritance_check_on_method copies the
 * inherited entry into the method table of a subclass implementing an interface that declares
 * the method. Before the release found those copies, they kept reading the freed block (a
 * use-after-free that only valgrind, or a reallocation of the block, reveals), so every
 * scenario here pins both the dispatch results and the engine state the release establishes:
 * a fake closure owns a private heap copy of its warmed slots, a clone a minted cache of its
 * own, and a block a running frame reads stays allocated.
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/CacheFake*): a redefinition changes
 * the class for the rest of the process. Subclasses are autoloaded only AFTER the first
 * redefinition, the order the engine supports for opcache-shared classes; with opcache
 * active in the runner the first swap copies each target out of shared memory.
 */
class RedefineRunTimeCacheTest extends TestCase
{
    /**
     * Tolerance (bytes) for the memory_get_usage() growth the plateau child measures over a
     * hundred swap cycles: the fixed path is exactly flat, and a block leaked per swap (24 to
     * 32 bytes for the donor bodies of the child) would cost at least five times this
     */
    private const int FLATNESS_TOLERANCE_BYTES = 512;

    /**
     * State of the mid-flight scenario, reached by the swapped-in body through this class
     * (see testFakeClosureRedefiningItsOwnMethodMidFlightKeepsItsCacheBlock)
     */
    public static ?ReflectionMethod $liveFrameEntry = null;

    public static ?\Closure $liveFrameNext = null;

    public static bool $liveFrameSwapped = false;

    public function testFakeClosuresOverARedefinedMethodSurviveTheNextSwap(): void
    {
        $method = (new ReflectionClass(CacheFakeClosureTarget::class))->getMethod('greet');
        $v1     = fn(string $who): string => 'v1:' . strtoupper($who) . str_repeat('!', 2);
        $v2     = fn(string $who): string => 'v2:' . strtolower($who) . str_repeat('?', 2);

        $method->redefine($v1);
        $firstCache = self::cacheAddress($method);

        $instance = new CacheFakeClosureTarget();
        $fakes    = [
            'first-class callable'         => $instance->greet(...),
            'Closure::fromCallable'        => \Closure::fromCallable([$instance, 'greet']),
            'ReflectionMethod::getClosure' => (new \ReflectionMethod(CacheFakeClosureTarget::class, 'greet'))->getClosure($instance),
        ];
        foreach ($fakes as $kind => $fake) {
            // zend_create_closure_ex copies the entry and shares its scope-matching non-heap cache
            $fakeFunction = self::closureFunction($fake);
            self::assertSame($firstCache, self::cacheAddress($fakeFunction), "{$kind} shares the minted cache of the entry");
            self::assertFalse(self::hasHeapCache($fakeFunction), "{$kind} does not own the shared block");
            self::assertSame('v1:A!!', $fake('a'), "{$kind} warms the shared block");
        }

        // The second swap retires the shared block: every fake closure gets a private heap copy first
        $method->redefine($v2);
        $secondCache = self::cacheAddress($method);
        self::assertNotSame($firstCache, $secondCache);
        self::assertSame('v2:b??', $instance->greet('B'), 'the entry dispatches the new body');

        $privateCopies = [];
        foreach ($fakes as $kind => $fake) {
            $fakeFunction = self::closureFunction($fake);
            self::assertTrue(self::hasHeapCache($fakeFunction), "{$kind} now owns a heap cache the engine frees with it");
            $fakeCache = self::cacheAddress($fakeFunction);
            self::assertNotSame($firstCache, $fakeCache, "{$kind} no longer points at the released block");
            self::assertNotSame($secondCache, $fakeCache, "{$kind} does not share the current cache of the entry");
            self::assertSame('v1:C!!', $fake('c'), "{$kind} keeps executing the previous body on its own cache");
            self::assertSame($fakeCache, self::cacheAddress(self::closureFunction($fake)), "{$kind} keeps its private copy across calls");
            $privateCopies[$kind] = $fakeCache;
        }

        // A third swap retires a block no closure points at: the private copies are left alone
        $method->redefine($v1);
        self::assertSame('v1:D!!', $instance->greet('d'));
        foreach ($fakes as $kind => $fake) {
            $fakeFunction = self::closureFunction($fake);
            self::assertSame($privateCopies[$kind], self::cacheAddress($fakeFunction), "{$kind} keeps its single private copy across later swaps");
            self::assertTrue(self::hasHeapCache($fakeFunction));
            self::assertSame('v1:E!!', $fake('e'));
        }
    }

    public function testFakeClosuresOverAMethodDonorBodyOutliveTheRestoringSwap(): void
    {
        $method = (new ReflectionClass(CacheFakeMethodTarget::class))->getMethod('greet');
        $method->redefine((new ReflectionClass(CacheFakeMethodDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');
        $firstCache = self::cacheAddress($method);

        $instance = new CacheFakeMethodTarget('t');
        $fakes    = [
            'first-class callable'         => $instance->greet(...),
            'Closure::fromCallable'        => \Closure::fromCallable([$instance, 'greet']),
            'ReflectionMethod::getClosure' => (new \ReflectionMethod(CacheFakeMethodTarget::class, 'greet'))->getClosure($instance),
        ];
        foreach ($fakes as $kind => $fake) {
            self::assertSame($firstCache, self::cacheAddress(self::closureFunction($fake)), "{$kind} shares the minted cache of the entry");
            self::assertSame('donor:A from t..', $fake('a'), "{$kind} warms the shared block");
        }

        // Swap the preserved body back in: the alias is the donor now, the donor-body cache is retired
        $method->redefine((new ReflectionClass(CacheFakeMethodTarget::class))->getMethod('greetOriginal'));
        $secondCache = self::cacheAddress($method);
        self::assertNotSame($firstCache, $secondCache);
        self::assertSame('target:b from t', $instance->greet('b'), 'the entry dispatches the restored body');

        foreach ($fakes as $kind => $fake) {
            $fakeFunction = self::closureFunction($fake);
            self::assertTrue(self::hasHeapCache($fakeFunction), "{$kind} now owns a heap cache");
            $fakeCache = self::cacheAddress($fakeFunction);
            self::assertNotSame($firstCache, $fakeCache, "{$kind} no longer points at the released block");
            self::assertNotSame($secondCache, $fakeCache, "{$kind} does not share the current cache of the entry");
            self::assertSame('donor:C from t..', $fake('c'), "{$kind} keeps executing the donor body");
        }
    }

    public function testFakeClosureOverARedefinedGlobalFunction(): void
    {
        require_once __DIR__ . '/../Stub/Alias/CacheFake-functions.php';
        self::assertTrue(function_exists('zengine_cachefake_fn'));

        // The block is minted by a closure-donor swap: a donor declared in an opcache-cached
        // file carries ZEND_ACC_IMMUTABLE, a body-level flag the entry adopts, and a global
        // function flagged so is copied out again by its next swap instead of swapped in place
        $function = new ReflectionFunction('zengine_cachefake_fn');
        $function->redefine(fn(string $who): string => 'v1:' . strtoupper($who) . str_repeat('!', 2));
        $firstCache = self::cacheAddress($function);

        // A plain function has no scope: the fake closure shares the minted cache like a method's
        $fake         = \Closure::fromCallable('zengine_cachefake_fn');
        $fakeFunction = self::closureFunction($fake);
        self::assertSame($firstCache, self::cacheAddress($fakeFunction));
        self::assertFalse(self::hasHeapCache($fakeFunction));
        self::assertSame('v1:A!!', $fake('a'));

        // The function donor retires the block
        $function->redefine(new ReflectionFunction('zengine_cachefake_fn_donor'));
        $secondCache = self::cacheAddress($function);
        self::assertNotSame($firstCache, $secondCache);
        self::assertSame('donor:b??', \zengine_cachefake_fn('B'), 'the function dispatches the donor body');

        $fakeFunction = self::closureFunction($fake);
        self::assertTrue(self::hasHeapCache($fakeFunction), 'the fake closure owns a heap cache now');
        $fakeCache = self::cacheAddress($fakeFunction);
        self::assertNotSame($firstCache, $fakeCache, 'the released block is not referenced anymore');
        self::assertNotSame($secondCache, $fakeCache);
        self::assertSame('v1:C!!', $fake('c'), 'the fake closure keeps executing the previous body');
    }

    public function testEngineCloneOfASubclassLinkedAfterTheSwapGetsItsOwnCache(): void
    {
        $entry = (new ReflectionClass(CacheFakeCloneBase::class))->getMethod('greet');
        $v1    = fn(string $who): string => 'v1:' . strtoupper($who) . str_repeat('!', 2);
        $v2    = fn(string $who): string => 'v2:' . strtolower($who) . str_repeat('?', 2);

        $entry->redefine($v1);
        $firstCache = self::cacheAddress($entry);

        // Autoloaded now, i.e. linked against the redefined base: the interface prototype makes
        // the engine separate the inherited bucket into a struct copy of the entry, cache pointer
        // included (do_inheritance_check_on_method) - a clone, not a share of the entry pointer
        $child = new CacheFakeCloneChild();
        self::assertSame(
            [CacheFakeCloneBase::class],
            array_map(static fn(ReflectionClass $holder): string => $holder->getName(), FunctionBodySwap::publishedShareHolders($entry)),
            'the subclass table does not hold the entry pointer',
        );
        $clones = FunctionBodySwap::cloneHolders($entry);
        self::assertCount(1, $clones);
        $cloneAddress = null;
        foreach ($clones as [$holder, $clone]) {
            self::assertSame(CacheFakeCloneChild::class, $holder->getName());
            self::assertNotSame($entry->getAddress(), $clone->getAddress(), 'the subclass holds a struct copy of the entry');
            self::assertSame($firstCache, self::cacheAddress($clone), 'the copy carries the minted cache pointer of the entry');
            self::assertFalse(self::hasHeapCache($clone));
            $cloneAddress = $clone->getAddress();
        }
        self::assertSame('v1:A!!', $child->greet('a'), 'the clone dispatches for the subclass and warms the shared block');

        // The second swap of the entry retires the shared block: the clone follows the swap and
        // gets a minted cache of its own before the block is freed
        $entry->redefine($v2);
        $secondCache = self::cacheAddress($entry);
        self::assertNotSame($firstCache, $secondCache);
        self::assertSame('v2:b??', (new CacheFakeCloneBase())->greet('B'));
        self::assertSame('v2:b??', $child->greet('B'), 'the subclass dispatches the new body through its clone');

        $clones = FunctionBodySwap::cloneHolders($entry);
        self::assertCount(1, $clones);
        foreach ($clones as [$holder, $clone]) {
            self::assertSame(CacheFakeCloneChild::class, $holder->getName());
            self::assertSame($cloneAddress, $clone->getAddress(), 'the clone was swapped in place');
            $cloneCache = self::cacheAddress($clone);
            self::assertNotSame($firstCache, $cloneCache, 'the clone no longer points at the released block');
            self::assertNotSame($secondCache, $cloneCache, 'the clone does not share the current cache of the entry');
            self::assertFalse(self::hasHeapCache($clone), 'a minted cache, released by the next swap of the clone');
        }
    }

    public function testFakeClosureRedefiningItsOwnMethodMidFlightKeepsItsCacheBlock(): void
    {
        $entry = (new ReflectionClass(CacheFakeLiveFrameTarget::class))->getMethod('greet');
        // The state the swapped-in body needs lives in static properties of this test class: on
        // PHP 8.5 a closure donor's bound variables (its live statics) stay with the closure
        // object, so the entry adopting the body would read them unbound - and the target class
        // itself is copied out of shared memory by the swap under an opcache-active runner
        self::$liveFrameEntry   = $entry;
        self::$liveFrameNext    = fn(string $who): string => 'v2:' . strtolower($who);
        self::$liveFrameSwapped = false;
        $v1                     = function (string $who): string {
            $head = 'v1:' . strtoupper($who);
            if (!RedefineRunTimeCacheTest::$liveFrameSwapped) {
                RedefineRunTimeCacheTest::$liveFrameSwapped = true;
                // The frame of the fake closure below runs on the minted block being retired
                RedefineRunTimeCacheTest::$liveFrameEntry?->redefine(RedefineRunTimeCacheTest::$liveFrameNext ?? static fn(string $who): string => $who);
            }

            // Inline-cache reads after the swap: the block this frame runs on must still be there
            return $head . str_repeat('.', 2) . strtolower($who);
        };

        $entry->redefine($v1);
        $firstCache = self::cacheAddress($entry);
        $instance   = new CacheFakeLiveFrameTarget();
        $fake       = $instance->greet(...);
        self::assertSame($firstCache, self::cacheAddress(self::closureFunction($fake)), 'the fake closure shares the minted cache');

        // hasLiveFrame() cannot see this frame (it executes &closure->func, not the entry):
        // the block is kept because a frame on the stack reads its inline caches from it
        self::assertSame('v1:A..a', $fake('a'), 'the frame completes on the previous body');
        self::assertTrue(self::$liveFrameSwapped);
        self::assertSame('v2:b', $instance->greet('B'), 'the entry dispatches the new body once the frame returned');
        self::assertNotSame($firstCache, self::cacheAddress($entry));

        $fakeFunction = self::closureFunction($fake);
        self::assertSame($firstCache, self::cacheAddress($fakeFunction), 'the block a live frame ran on is kept allocated, not copied');
        self::assertFalse(self::hasHeapCache($fakeFunction));
        self::assertSame('v1:C..c', $fake('c'), 'the kept block still serves the fake closure');
    }

    public function testRetiredCachesAreStillReleasedWhileAFakeClosureIsAlive(): void
    {
        // The swap loop runs in a child (scenarios/cachefake-fake-closure-plateau.php): every
        // swap walks the class table for the holders of the entry, which costs ten times more
        // in a PHPUnit process than in a bare one. The child follows the runner's opcache mode,
        // so with opcache active the stub and the donor bodies are shared-memory ones there too
        $isOpcacheActive = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
        $command         = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            '-d', 'opcache.enable=' . ($isOpcacheActive ? '1' : '0'),
            '-d', 'opcache.enable_cli=' . ($isOpcacheActive ? '1' : '0'),
            // The JIT rewrites the executor internals z-engine hooks into (AGENTS.md)
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            // Freshly checked-out stubs must still be served from shared memory
            '-d', 'opcache.file_update_protection=0',
            __DIR__ . '/scenarios/cachefake-fake-closure-plateau.php',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Unable to spawn the plateau child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        // A use-after-free surfaces here as a signal exit code, not as a metric
        self::assertSame(0, $exitCode, "Plateau child exited with code {$exitCode}\n{$report}");

        $stdoutLines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        $lastLine    = $stdoutLines === [] ? '' : end($stdoutLines);
        $metrics     = json_decode($lastLine, true);
        self::assertIsArray($metrics, "Plateau child produced no metrics\n{$report}");
        self::assertIsInt($metrics['cycles']);
        self::assertIsInt($metrics['cacheSize']);
        self::assertIsInt($metrics['growth']);
        self::assertGreaterThanOrEqual(100, $metrics['cycles']);
        self::assertGreaterThan(0, $metrics['cacheSize']);

        // The engine shape the loop starts from: the closure shares the block minted by the swap
        self::assertTrue($metrics['sharedBeforeSwap'], "the fake closure shares the minted cache of the entry\n{$report}");
        self::assertSame('v1:AB!!baAbaba', $metrics['warmed'], $report);

        // The first swap after the closure was made gives it a private heap copy; no later swap
        // touches that copy, and every retired block is freed: a leaked block per swap would cost
        // cycles * cacheSize bytes, at least five times the tolerance
        self::assertTrue($metrics['heapAfterFirst'], "the fake closure owns a heap cache after the first swap\n{$report}");
        self::assertTrue($metrics['privateCopyIsOwn'], "the private copy is neither the released block nor the entry's cache\n{$report}");
        self::assertTrue($metrics['privateCopyKept'], "later swaps leave the private copy of the fake closure alone\n{$report}");
        self::assertTrue($metrics['heapAfterCycles'], $report);
        self::assertLessThan(
            self::FLATNESS_TOLERANCE_BYTES,
            $metrics['growth'],
            "{$metrics['cycles']} swaps grew the heap by {$metrics['growth']} bytes (a leaked block per swap would cost "
                . ($metrics['cycles'] * $metrics['cacheSize']) . ")\n{$report}",
        );
        self::assertSame('v1:CD!!dcCdcdc', $metrics['entryResult'], "the entry dispatches the last donor body\n{$report}");
        self::assertSame('v1:EF!!feEfefe', $metrics['fakeResult'], "the fake closure keeps executing its body\n{$report}");
    }

    public function testFakeClosureOverThePreservedAliasSurvivesARedefinitionOfTheAlias(): void
    {
        $class = new ReflectionClass(CacheFakeAliasTarget::class);
        $class->getMethod('greet')->redefine((new ReflectionClass(CacheFakeAliasDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');

        // The alias container got a minted cache of its own when it was published
        $alias      = (new ReflectionClass(CacheFakeAliasTarget::class))->getMethod('greetOriginal');
        $aliasCache = self::cacheAddress($alias);
        self::assertFalse(self::hasHeapCache($alias));

        $instance     = new CacheFakeAliasTarget('t');
        $fake         = (new \ReflectionMethod(CacheFakeAliasTarget::class, 'greetOriginal'))->getClosure($instance);
        $fakeFunction = self::closureFunction($fake);
        self::assertSame($aliasCache, self::cacheAddress($fakeFunction), 'a fake closure over a published container shares its minted cache too');
        self::assertSame('target:A from t', $fake('a'));

        // Redefining the alias retires the container's minted cache through the same release
        $alias->redefine(fn(string $who): string => 'alias-v2:' . strtolower($who));
        self::assertNotSame($aliasCache, self::cacheAddress($alias));
        self::assertSame('alias-v2:b', (new \ReflectionMethod(CacheFakeAliasTarget::class, 'greetOriginal'))->invoke($instance, 'B'));

        $fakeFunction = self::closureFunction($fake);
        self::assertTrue(self::hasHeapCache($fakeFunction));
        self::assertNotSame($aliasCache, self::cacheAddress($fakeFunction));
        self::assertSame('target:C from t', $fake('c'), 'the fake closure keeps executing the preserved body');
        self::assertSame('donor:d from t', $instance->greet('d'), 'the entry itself is untouched by the alias swap');
    }

    /**
     * Pointer-level view of the zend_function embedded in a closure object (borrowed: the
     * closure must stay alive while the view is used)
     */
    private static function closureFunction(\Closure $closure): ReflectionFunction
    {
        $closureEntry = new ClosureEntry($closure);

        return ReflectionFunction::fromCData(Core::addr($closureEntry->getRawFunction()));
    }

    /**
     * Address of the run-time cache block a user function reads its inline caches from
     */
    private static function cacheAddress(FunctionLikeInterface $function): int
    {
        $cache = $function->getOpArrayPointer()->run_time_cache__ptr;
        self::assertNotNull($cache, 'a swapped entry is never left without a run-time cache');

        return Core::addressOf($cache);
    }

    /**
     * Whether the function owns its run-time cache (ZEND_ACC_HEAP_RT_CACHE: destroy_op_array frees it)
     */
    private static function hasHeapCache(FunctionLikeInterface $function): bool
    {
        return ($function->getCommonPointer()->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0;
    }
}
