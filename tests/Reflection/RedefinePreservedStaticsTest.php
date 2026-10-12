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
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Stub\Alias\LiveStaticsBorrowedAlias;
use ZEngine\Stub\Alias\LiveStaticsBorrowedAliasDonor;
use ZEngine\Stub\Alias\LiveStaticsBorrowedEntry;
use ZEngine\Stub\Alias\LiveStaticsChurn;
use ZEngine\Stub\Alias\LiveStaticsChurnDonor;
use ZEngine\Stub\Alias\LiveStaticsCounter;
use ZEngine\Stub\Alias\LiveStaticsCounterDonor;
use ZEngine\Stub\Alias\LiveStaticsHeldBase;
use ZEngine\Stub\Alias\LiveStaticsHeldChild;
use ZEngine\Stub\Alias\LiveStaticsHeldDonor;
use ZEngine\Stub\Alias\LiveStaticsRemoved;
use ZEngine\Stub\Alias\LiveStaticsRemovedDonor;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;

/**
 * Ownership of the MATERIALIZED live static-variables table of a body preserved under an alias
 *
 * A function's statics live in two tables on 8.4: op_array.static_variables holds the compiled
 * defaults, and the first ZEND_BIND_STATIC duplicates them into the live per-entry table the
 * map pointer static_variables_ptr refers to. The engine destroys a live table only through the
 * tables that publish its zend_function (the ZEND_HAS_STATIC_IN_METHODS walk over class method
 * tables, the function-table walk at shutdown) or through destroy_op_array for the body's last
 * holder. Two paths of the alias workflow used to lose that table on 8.4:
 *
 *  - redefine($donor, preserveAs: ...) copied the entry into the alias container and then
 *    nulled the entry's slot for the new body, leaving the table the previous body had
 *    accumulated referenced by no zend_function: the alias restarted from the defaults and the
 *    table leaked, one per preserving swap. The alias now takes the table over
 *    (FunctionBodySwap::adoptFunctionForPublishing with inheritLiveStatics), which the tests pin
 *    by pointer identity of getLiveStaticsPointer() and by the continued count;
 *  - removeMethods() of the alias (the documented "drop the backup" step) deleted the bucket
 *    whose destructor only decrements the body refcount: the container stayed allocated in
 *    request memory with its live table, minted run-time cache and minted defaults duplicate
 *    reachable from nowhere. FunctionBodySwap::retireUnpublishedEntry() releases them now -
 *    unless another class table still publishes the container or a fake closure still shares
 *    the table by pointer, which would turn the release into a use-after-free.
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/LiveStatics*): a redefinition changes
 * the class for the rest of the process. With opcache active in the runner the stubs are
 * shared-memory classes; a scenario that materializes statics BEFORE its first mutation copies
 * the class out of shared memory explicitly first (a no-op otherwise), because statics bound
 * on the shared entry stay with it (the copy-out caveats of docs/hot-swap.md).
 */
class RedefinePreservedStaticsTest extends TestCase
{
    /**
     * Cycles of the memory-flatness scenario: each one publishes, calls, restores and removes an alias
     *
     * Every step walks the class table and the object store a few times (share holders, clones,
     * fake closures), which costs close to a second per cycle inside the test process: the count
     * is kept small, and the growth measured is exact (a fixed number of bytes per cycle).
     */
    private const int MEASURED_CYCLES = 20;

    /**
     * Allowed memory_get_usage() growth per cycle (bytes)
     *
     * The alias container itself (a 256-byte zend_function from request memory, see
     * MethodPublisher::newContainer()) is the documented per-publication baseline; before the
     * fix every cycle left the alias' live statics table behind as well - a 376-byte HashTable
     * with its one-element data block - which this budget does not accommodate.
     */
    private const int PER_CYCLE_BUDGET_BYTES = 512;

    public function testAliasTakesOverTheLiveStaticsOfTheMethodBodyItPreserves(): void
    {
        $class = new ReflectionClass(LiveStaticsCounter::class);
        $class->copyOutOfSharedMemory();
        $method   = $class->getMethod('counter');
        $instance = new LiveStaticsCounter();
        self::assertSame(1, $instance->counter());
        self::assertSame(2, $instance->counter());
        $liveTable = $method->getLiveStaticsPointer();
        self::assertNotNull($liveTable, 'the first call materialized the live statics table of the entry');
        $liveAddress = Core::addressOf($liveTable);

        // The alias publication detaches the table from the entry before the swap nulls the slot
        // for the new body: the alias container's slot is the very same HashTable pointer
        $method->redefine((new ReflectionClass(LiveStaticsCounterDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');
        $alias = (new ReflectionClass(LiveStaticsCounter::class))->getMethod('counterOriginal');
        self::assertSame($liveAddress, self::liveTableAddress($alias), 'the alias took over the table the entry materialized');
        self::assertSame(2, self::staticValue($liveTable, 'n'), 'with the state the preserved body accumulated');
        self::assertNull($method->getLiveStaticsPointer(), 'the entry starts afresh: its slot was handed over, not copied');

        $aliasMethod = new \ReflectionMethod(LiveStaticsCounter::class, 'counterOriginal');
        self::assertSame(3, $aliasMethod->invoke($instance), 'the preserved body continues its count');
        self::assertSame(1001, $instance->counter(), 'the new body binds its own statics from its defaults');
        self::assertSame(1002, $instance->counter());
        self::assertSame(4, $aliasMethod->invoke($instance));
        self::assertNotSame($liveAddress, self::liveTableAddress($method), 'the new body materialized a live table of its own on its first call');
        self::assertSame($liveAddress, self::liveTableAddress($alias), 'the alias keeps its table across calls');
        self::assertSame(4, self::staticValue($liveTable, 'n'));

        // Swapping the preserved body back in adopts the alias as a method donor: the entry binds
        // the statics afresh while the alias keeps the state (the previous body's table stays its own)
        $method->redefine((new ReflectionClass(LiveStaticsCounter::class))->getMethod('counterOriginal'));
        self::assertNull($method->getLiveStaticsPointer(), 'the restored body has no live table yet');
        self::assertSame(1, $instance->counter(), 'the restored body starts with fresh statics');
        self::assertSame(5, $aliasMethod->invoke($instance), 'the alias keeps counting on the table it took over');
        self::assertSame($liveAddress, self::liveTableAddress($alias));
    }

    public function testAliasTakesOverTheLiveStaticsOfTheFunctionBodyItPreserves(): void
    {
        require_once __DIR__ . '/../Stub/Alias/LiveStatics-functions.php';
        self::assertTrue(function_exists('zengine_live_statics_fn'));
        self::assertSame(1, \zengine_live_statics_fn());
        self::assertSame(2, \zengine_live_statics_fn());

        // Under opcache the entry is still the shared-memory function here: its slot holds the
        // map-ptr offset form, which getLiveStaticsPointer() dereferences to the per-process table
        $function  = new ReflectionFunction('zengine_live_statics_fn');
        $liveTable = $function->getLiveStaticsPointer();
        self::assertNotNull($liveTable, 'two calls materialized the live statics table of the function');
        $liveAddress = Core::addressOf($liveTable);

        $function->redefine(static function (): int {
            /** @var int $m */
            static $m = 1000;

            return ++$m;
        }, preserveAs: 'zengine_live_statics_fn_original');
        self::assertTrue(function_exists('zengine_live_statics_fn_original'));

        $alias = new ReflectionFunction('zengine_live_statics_fn_original');
        self::assertSame($liveAddress, self::liveTableAddress($alias), 'the second function entry took over the table of the function it preserves');
        self::assertNull($function->getLiveStaticsPointer(), 'the entry (the writable copy under opcache) starts afresh');

        // The entry is called by name: a call site that already resolved the function before the
        // swap keeps the shared-memory entry under opcache (copy-out caveat of docs/hot-swap.md)
        self::assertSame(3, \zengine_live_statics_fn_original(), 'the preserved body continues its count');
        self::assertSame(1001, self::callFunction('zengine_live_statics_fn'), 'the new body binds its own statics from its defaults');
        self::assertSame(1002, self::callFunction('zengine_live_statics_fn'));
        self::assertSame(4, \zengine_live_statics_fn_original());
        self::assertNotSame($liveAddress, self::liveTableAddress($function), 'the new body materialized a table of its own');
        self::assertSame($liveAddress, self::liveTableAddress($alias), 'the alias keeps its table across calls');
        self::assertSame(4, self::staticValue($liveTable, 'n'));
    }

    public function testRemovingTheAliasDestroysItsLiveStaticsAndReleasesItsMintedCache(): void
    {
        $class = new ReflectionClass(LiveStaticsRemoved::class);
        $class->getMethod('counter')->redefine((new ReflectionClass(LiveStaticsRemovedDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');
        // Created after the first mutation: under opcache that mutation copied the class out
        $instance = new LiveStaticsRemoved();
        self::assertSame(101, $instance->counter(), 'the donor body delegates to the alias, which binds its statics');
        self::assertSame(102, $instance->counter());

        $class        = new ReflectionClass(LiveStaticsRemoved::class);
        $alias        = $class->getMethod('counterOriginal');
        $aliasOpArray = $alias->getOpArrayPointer();
        $liveTable    = $aliasOpArray->static_variables_ptr__ptr;
        self::assertNotNull($liveTable, 'the alias materialized its live statics table on its first call');
        self::assertSame(2, self::staticValue($liveTable, 'n'));
        self::assertGreaterThan(0, $aliasOpArray->cache_size, 'the preserved body has inline caches (the property fetch)');
        self::assertNotNull($aliasOpArray->run_time_cache__ptr, 'the publication minted a run-time cache for the alias');
        self::assertSame(0, $alias->getCommonPointer()->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE, 'a minted cache is request memory the engine never frees itself');
        self::assertCount(1, FunctionBodySwap::publishedShareHolders($alias), 'the declaring class is the only table publishing the alias');

        // The documented workflow: swap the preserved body back in, then drop the backup
        $class->getMethod('counter')->redefine($class->getMethod('counterOriginal'));
        $class->removeMethods('counterOriginal');

        self::assertFalse(method_exists(LiveStaticsRemoved::class, 'counterOriginal'));
        self::assertCount(0, FunctionBodySwap::publishedShareHolders($alias), 'no table reaches the container anymore');
        // The container is request memory the engine never frees (op_arrays are arena-allocated),
        // so its slots stay readable: the bucket destructor only decremented the body refcount, the
        // retirement destroyed the live table and released the minted cache the destructor cannot reach
        self::assertNull($aliasOpArray->static_variables_ptr__ptr, 'zend_destroy_static_vars freed the live table and cleared the slot');
        self::assertNull($aliasOpArray->run_time_cache__ptr, 'the minted run-time cache was released');
        self::assertSame(1, (new LiveStaticsRemoved())->counter(), 'the restored body starts with fresh statics');
        self::assertSame(2, $instance->counter());
    }

    public function testAliasPublishCallRestoreRemoveCyclesAreMemoryFlat(): void
    {
        $class = new ReflectionClass(LiveStaticsChurn::class);
        $class->copyOutOfSharedMemory();
        $donor    = (new ReflectionClass(LiveStaticsChurnDonor::class))->getMethod('counter');
        $instance = new LiveStaticsChurn();

        // One cycle materializes the entry's table (first call), moves it to the alias (publication),
        // continues it (the donor body delegates), restores the body and drops the alias: before the
        // fix the table was orphaned by the publication, or by the removal when materialized later
        $cycle = static function () use ($donor, $instance): int {
            $class    = new ReflectionClass(LiveStaticsChurn::class);
            $checksum = $instance->counter();
            $class->getMethod('counter')->redefine($donor, preserveAs: 'counterOriginal');
            $checksum += $instance->counter();
            $class->getMethod('counter')->redefine($class->getMethod('counterOriginal'));
            $class->removeMethods('counterOriginal');

            return $checksum;
        };

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(1 + 102, $cycle(), 'warm-up: a fresh table (1) continued by the alias (100 + 2)');
        }
        gc_collect_cycles();
        $before   = memory_get_usage();
        $checksum = 0;
        for ($i = 0; $i < self::MEASURED_CYCLES; $i++) {
            $checksum += $cycle();
        }
        gc_collect_cycles();
        $growth = memory_get_usage() - $before;

        self::assertSame(self::MEASURED_CYCLES * 103, $checksum, 'every cycle started from fresh statics and continued them through the alias');
        self::assertFalse(method_exists(LiveStaticsChurn::class, 'counterOriginal'));
        self::assertLessThan(
            self::MEASURED_CYCLES * self::PER_CYCLE_BUDGET_BYTES,
            $growth,
            sprintf(
                'memory grew by %d bytes over %d cycles (%.1f bytes per cycle; the alias container is 256 bytes, a leaked live statics table 376 more)',
                $growth,
                self::MEASURED_CYCLES,
                $growth / self::MEASURED_CYCLES,
            ),
        );
    }

    public function testFakeClosureOverTheAliasKeepsItsLiveStaticsPastTheRemoval(): void
    {
        $class = new ReflectionClass(LiveStaticsBorrowedAlias::class);
        $class->getMethod('counter')->redefine((new ReflectionClass(LiveStaticsBorrowedAliasDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');
        $instance = new LiveStaticsBorrowedAlias();
        self::assertSame(101, $instance->counter());
        self::assertSame(102, $instance->counter());

        $class     = new ReflectionClass(LiveStaticsBorrowedAlias::class);
        $alias     = $class->getMethod('counterOriginal');
        $liveTable = $alias->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($liveTable);
        $liveAddress = Core::addressOf($liveTable);

        // zend_create_closure_ex points a fake closure's slot AT the live table of the function it
        // is made over (no duplicate): the closure reads and writes the alias' table
        $fake = (new \ReflectionMethod(LiveStaticsBorrowedAlias::class, 'counterOriginal'))->getClosure($instance);
        self::assertSame($liveAddress, self::liveSlotAddress(self::closureFunction($fake)), 'the fake closure borrows the table by pointer');
        self::assertSame(3, $fake());

        $class->getMethod('counter')->redefine($class->getMethod('counterOriginal'));
        $class->removeMethods('counterOriginal');
        self::assertFalse(method_exists(LiveStaticsBorrowedAlias::class, 'counterOriginal'));

        // Destroying the table here would pull it out from under the closure: it stays with it
        $slotAfter = $alias->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($slotAfter, 'the retirement left the borrowed table alone');
        self::assertSame($liveAddress, Core::addressOf($slotAfter));
        self::assertSame(4, $fake(), 'the fake closure keeps executing the preserved body on its table');
        self::assertSame(5, $fake());
        self::assertSame(5, self::staticValue($slotAfter, 'n'));
        self::assertSame($liveAddress, self::liveSlotAddress(self::closureFunction($fake)));
        self::assertSame(1, (new LiveStaticsBorrowedAlias())->counter(), 'the restored body binds fresh statics of its own');
    }

    public function testFakeClosureOverTheEntryKeepsBorrowingTheLiveStaticsThroughASwap(): void
    {
        $class = new ReflectionClass(LiveStaticsBorrowedEntry::class);
        $class->copyOutOfSharedMemory();
        $method   = $class->getMethod('counter');
        $instance = new LiveStaticsBorrowedEntry();
        self::assertSame(1, $instance->counter());
        self::assertSame(2, $instance->counter());
        $liveTable = $method->getLiveStaticsPointer();
        self::assertNotNull($liveTable);
        $liveAddress = Core::addressOf($liveTable);

        $fake = $instance->counter(...);
        self::assertSame($liveAddress, self::liveSlotAddress(self::closureFunction($fake)), 'a first-class callable borrows the live table of the entry');

        // Without an alias the previous body is destroyed by the swap - but the fake closure
        // still holds a share of it, so the body arrays survive, and the live table it borrows
        // must survive with them (destroying it would be a use-after-free in the closure)
        $method->redefine(static fn(): int => 1000);
        self::assertSame(1000, $instance->counter());
        self::assertNull($method->getLiveStaticsPointer(), 'the new body declares no statics');
        self::assertSame(3, $fake(), 'the fake closure keeps counting on the table the swap left to it');
        self::assertSame(4, $fake());
        self::assertSame($liveAddress, self::liveSlotAddress(self::closureFunction($fake)));
        self::assertSame(4, self::staticValue($liveTable, 'n'));
    }

    public function testAliasHeldByASubclassTableKeepsItsLiveStaticsUntilTheLastTableDropsIt(): void
    {
        // Linked now, before the redefinition: the child table shares the entry pointer
        self::assertSame(1, (new LiveStaticsHeldChild())->counter());
        $base = new ReflectionClass(LiveStaticsHeldBase::class);

        if ($base->isImmutable()) {
            // A subclass linked against the shared-memory entry could never see the alias
            $this->expectException(SharedMemoryException::class);
            $this->expectExceptionMessageMatches('/subclass .*LiveStaticsHeldChild was linked against the shared entry/');
        }
        $base->getMethod('counter')->redefine((new ReflectionClass(LiveStaticsHeldDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');

        // The alias took over the table the child's call materialized, and the child inherited the alias
        self::assertSame(102, (new LiveStaticsHeldChild())->counter(), 'the preserved body runs on the child and continues its count');
        $alias     = $base->getMethod('counterOriginal');
        $liveTable = $alias->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($liveTable);
        $liveAddress = Core::addressOf($liveTable);
        self::assertCount(2, FunctionBodySwap::publishedShareHolders($alias), 'both tables publish the alias container');

        $base->getMethod('counter')->redefine($base->getMethod('counterOriginal'));
        $base->removeMethods('counterOriginal');
        self::assertFalse(method_exists(LiveStaticsHeldBase::class, 'counterOriginal'));
        // method_exists() reports false for a shadow private method (one inherited from an ancestor): the table itself is checked
        self::assertTrue((new \ReflectionClass(LiveStaticsHeldChild::class))->hasMethod('counterOriginal'), 'the child table still publishes the alias');

        // The engine reaches the table through the child's method table (ZEND_HAS_STATIC_IN_METHODS
        // walk), so the retirement must leave it alone: the alias keeps working on the child
        $slotAfter = $alias->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($slotAfter, 'removing the alias from one of two tables keeps its live statics');
        self::assertSame($liveAddress, Core::addressOf($slotAfter));
        self::assertSame(3, (new \ReflectionMethod(LiveStaticsHeldChild::class, 'counterOriginal'))->invoke(new LiveStaticsHeldChild()));
        self::assertCount(1, FunctionBodySwap::publishedShareHolders($alias));

        (new ReflectionClass(LiveStaticsHeldChild::class))->removeMethods('counterOriginal');
        self::assertFalse((new \ReflectionClass(LiveStaticsHeldChild::class))->hasMethod('counterOriginal'));
        self::assertNull($alias->getOpArrayPointer()->static_variables_ptr__ptr, 'the last table dropping the alias destroys its live statics');
        self::assertCount(0, FunctionBodySwap::publishedShareHolders($alias));
        self::assertSame(1, (new LiveStaticsHeldBase())->counter(), 'the restored body starts with fresh statics');
        self::assertSame(2, (new LiveStaticsHeldChild())->counter(), 'shared with the child through the entry pointer');
    }

    /**
     * Current value of a static variable in a materialized live statics table
     *
     * @param HashTableStruct $liveTable `HashTable *` of the table (the stub-typed FFI handle)
     */
    private static function staticValue(object $liveTable, string $variableName): mixed
    {
        $slot = HashTable::fromCData($liveTable)->find($variableName);
        self::assertNotNull($slot, "the table binds the static variable \${$variableName}");
        // A bound slot holds the IS_REFERENCE the executing body shares: read, never written
        $value = null;
        $slot->getNativeValue($value);

        return $value;
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
     * Address of the materialized live statics table of an entry (fails while none is materialized)
     */
    private static function liveTableAddress(FunctionLikeInterface $function): int
    {
        $liveTable = $function->getLiveStaticsPointer();
        self::assertNotNull($liveTable, 'the entry has a materialized live statics table');

        return Core::addressOf($liveTable);
    }

    /**
     * Address the raw static_variables_ptr slot of a user function points at (plain pointer form)
     */
    private static function liveSlotAddress(FunctionLikeInterface $function): int
    {
        $liveTable = $function->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($liveTable, 'the function has a materialized live statics table');

        return Core::addressOf($liveTable);
    }

    /**
     * Calls a global function published at run time (unknown to static analysis) by name
     */
    private static function callFunction(string $functionName): mixed
    {
        return (new \ReflectionFunction($functionName))->invoke();
    }
}
