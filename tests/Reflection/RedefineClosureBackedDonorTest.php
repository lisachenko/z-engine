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
use ZEngine\Stub\Alias\ClosureDonorChild;
use ZEngine\Stub\Alias\ClosureDonorCounter;
use ZEngine\Stub\Alias\ClosureDonorPlainDonor;
use ZEngine\Stub\Alias\ClosureDonorPlainTarget;
use ZEngine\Stub\Alias\ClosureDonorReleased;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;

/**
 * Statics ownership of a METHOD donor whose body is a closure's: a method published with
 * addMethod($name, $closure) and then handed to redefine() or addMethod() as a donor
 *
 * On 8.4 zend_create_closure_ex duplicates the prototype's statics defaults for the closure
 * object and points the closure's static_variables_ptr AT that duplicate: a closure's defaults
 * table IS its live table (ZEND_BIND_STATIC binds in it directly, destroy_op_array destroys it
 * with the closure). addMethod($name, $closure) publishes the closure's own zend_function in the
 * method table, so such a method carries exactly that shape. The decision to duplicate a donor's
 * defaults table for the entry used to key on the PHP argument type of redefine(): a Closure
 * argument got a duplicate, a method donor shared the table - correct for a plain compiled
 * method, whose table lives as long as the body the entry now holds a reference on, but wrong
 * for a closure-backed method: the entry's op_array.static_variables aliased the closure's live
 * table, every call of the closure-backed method rewrote the "defaults" the entry binds from,
 * and at a destructor-path shutdown the table was destroyed twice (through the closure-backed
 * bucket's live slot by the ZEND_HAS_STATIC_IN_METHODS walk, and through static_variables by
 * destroy_op_array of the entry as the body's last holder). FunctionBodySwap::unshareStaticVariables()
 * now recognizes a closure body by its shape (the live slot points at the defaults table) and
 * mints a duplicate whatever the argument type - which the tests pin through
 * FunctionBodySwap::hasMintedStaticDefaults(), distinct table addresses and the values the
 * tables hold: the duplicate is a snapshot of the closure's live table, so it carries the count
 * the closure-backed method had reached (a plain compiled donor's defaults never move).
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/ClosureDonor*): a redefinition changes
 * the class for the rest of the process. With opcache active in the runner the stubs are
 * shared-memory classes: the first mutation copies the class out, so instances and method
 * wrappers are taken only after it, and subclasses are autoloaded after the operation (the
 * copy-out caveats of docs/hot-swap.md). Everything runs in-process: the shutdown path is not
 * driven here.
 */
class RedefineClosureBackedDonorTest extends TestCase
{
    /**
     * Swap cycles measured for memory flatness: each one adopts the closure-backed method donor
     * (minting a duplicate of the closure's table) or a statics-free closure (releasing it)
     *
     * A method swap walks the class table and the object store a few times, which costs a few
     * hundred milliseconds per swap in a test process holding the whole suite: the count is
     * kept small, while one leaked duplicate per cycle (a 376-byte HashTable, 7520 bytes over
     * the measured cycles) would still exceed the tolerance several times over.
     */
    private const int MEASURED_CYCLES = 20;

    /**
     * Tolerance (bytes) for the memory_get_usage() growth over the measured cycles: every
     * allocation a cycle makes or releases goes through the Zend allocator, which
     * memory_get_usage() tracks exactly, so the fixed path measures a growth of 0 in both
     * runner modes; the tolerance leaves room for a few stray allocations and stays well
     * under a third of the leak signature
     */
    private const int FLATNESS_TOLERANCE_BYTES = 2 * 1024;

    public function testMethodDonorPublishedFromAClosureGetsADuplicateOfTheClosureTable(): void
    {
        $closure = function (): int {
            /** @var int $n */
            static $n = 0;

            return ++$n;
        };
        $class     = new ReflectionClass(ClosureDonorCounter::class);
        $published = $class->addMethod('counterBody', $closure);
        // From here on $class wraps the writable class (the copied-out one under opcache)

        // The shape the swap recognizes: the published bucket IS the closure's own zend_function,
        // whose live slot points at its defaults table (zend_create_closure_ex on 8.4)
        self::assertSame(self::closureFunction($closure)->getAddress(), $published->getAddress(), 'addMethod() publishes the closure\'s own zend_function');
        $closureTable = self::defaultsAddress($published);
        self::assertSame($closureTable, self::liveSlotAddress($published), 'a closure\'s defaults table IS its live table');

        $instance = new ClosureDonorCounter();
        self::assertSame(1, self::callMethod($instance, 'counterBody'));
        self::assertSame(2, self::callMethod($instance, 'counterBody'));
        self::assertSame(2, self::staticValue(self::defaultsTable($published), 'n'), 'the closure-backed method counts in its defaults table');

        // The closure-backed method as the METHOD donor: the entry gets a duplicate of the table
        // instead of aliasing it (the argument type says "method", the body says "closure")
        $method = $class->getMethod('counter');
        $method->redefine($class->getMethod('counterBody'));
        $entryAddress = $method->getAddress();
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the swap minted a defaults duplicate for the entry');
        $entryDefaults = self::defaultsAddress($method);
        self::assertNotSame($closureTable, $entryDefaults, 'the entry does not share the closure\'s table');
        $unboundTable = $method->getLiveStaticsPointer();
        self::assertNull($unboundTable, 'the duplicate is the entry\'s defaults: nothing is bound before the first call');
        self::assertSame(2, self::staticValue(self::defaultsTable($method), 'n'), 'the duplicate is a snapshot of the closure\'s live table, count included');
        self::assertSame($closureTable, self::defaultsAddress($published), 'the donor keeps its own table');
        self::assertSame($closureTable, self::liveSlotAddress($published), 'and its live slot still points at it');

        // On 8.4 a closure's defaults table is its live table, so the duplicate carries the
        // current count (2): the entry continues from there on a live table of its own, while
        // the closure-backed method keeps counting on the closure's table
        self::assertSame(3, $instance->counter(), 'the entry binds its statics from the duplicate (n = 2)');
        self::assertSame(4, $instance->counter());
        self::assertSame(3, self::callMethod($instance, 'counterBody'), 'the closure-backed method keeps its own count');
        $entryLiveTable = $method->getLiveStaticsPointer();
        self::assertNotNull($entryLiveTable, 'the first call materialized the entry\'s live table');
        self::assertNotSame($closureTable, Core::addressOf($entryLiveTable), 'the entry\'s live table is neither the closure\'s table');
        self::assertNotSame($entryDefaults, Core::addressOf($entryLiveTable), 'nor its own duplicate of the defaults');
        self::assertSame(4, self::staticValue($entryLiveTable, 'n'));
        self::assertSame(2, self::staticValue(self::defaultsTable($method), 'n'), 'the duplicate itself never moves');
        self::assertSame(3, self::staticValue(self::defaultsTable($published), 'n'));

        // A subclass autoloaded now inherits the entry pointer and the closure-backed method; an
        // override published from the same closure-backed method donor mints a table of its own
        $child    = new ReflectionClass(ClosureDonorChild::class);
        $override = $child->addMethod('inherited', $class->getMethod('counterBody'));
        self::assertSame(ClosureDonorChild::class, $override->getDeclaringClass()->getName());
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($override->getAddress()), 'the override container minted a duplicate of its own');
        $overrideDefaults = self::defaultsAddress($override);
        self::assertNotSame($closureTable, $overrideDefaults, 'the override does not share the closure\'s table');
        self::assertNotSame($entryDefaults, $overrideDefaults, 'nor the entry\'s duplicate');
        self::assertSame(3, self::staticValue(self::defaultsTable($override), 'n'), 'snapshot of the closure\'s table at publication time');
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the entry\'s record is untouched by the publication');

        $childInstance = new ClosureDonorChild();
        self::assertSame(4, $childInstance->inherited(), 'the override binds its statics from its own duplicate (n = 3)');
        self::assertSame(5, $childInstance->inherited());
        self::assertSame(0, $instance->inherited(), 'the ancestor\'s own method is untouched');
        self::assertSame(5, $childInstance->counter(), 'the inherited entry pointer continues the live table of the parent entry');
        self::assertSame(4, self::callMethod($childInstance, 'counterBody'), 'the inherited closure-backed method counts on the closure\'s table');
        self::assertSame(4, self::staticValue(self::defaultsTable($published), 'n'));
        self::assertSame($closureTable, self::liveSlotAddress($published), 'the closure\'s table is still the one the closure owns');
    }

    public function testTheNextSwapReleasesTheDuplicateAndLeavesTheClosureTableAlone(): void
    {
        // A non-static closure: the published method must agree with m() on static-ness
        $closure = function (): int {
            /** @var int $k */
            static $k = 100;

            return ++$k;
        };
        $noStatics    = fn(): int => -1;
        $class        = new ReflectionClass(ClosureDonorReleased::class);
        $published    = $class->addMethod('body', $closure);
        $closureTable = self::defaultsAddress($published);
        $instance     = new ClosureDonorReleased();
        self::assertSame(101, self::callMethod($instance, 'body'));
        self::assertSame(102, self::callMethod($instance, 'body'));

        $method = $class->getMethod('m');
        $method->redefine($class->getMethod('body'));
        $entryAddress = $method->getAddress();
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        $duplicate = self::defaultsAddress($method);
        self::assertNotSame($closureTable, $duplicate);
        self::assertSame(103, $instance->m(), 'the entry starts from the snapshot (k = 102)');
        self::assertSame(104, $instance->m());

        // The next swap (a donor without statics) releases the duplicate minted for the previous
        // body: the record is gone and the new body has no defaults table - while the closure's
        // table, which the entry never shared, is exactly where it was, with its count
        $method->redefine($noStatics);
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the eager release of the duplicate matched the record');
        self::assertNull($method->getOpArrayPointer()->static_variables, 'the new body declares no statics');
        self::assertNull($method->getLiveStaticsPointer(), 'the previous body\'s live table went with it');
        self::assertSame(-1, $instance->m());
        self::assertSame($closureTable, self::defaultsAddress($published), 'the closure-backed method still owns its table');
        self::assertSame($closureTable, self::liveSlotAddress($published));
        self::assertSame(102, self::staticValue(self::defaultsTable($published), 'k'), 'with the count it had: the swaps of the entry never touched it');
        self::assertSame(103, self::callMethod($instance, 'body'), 'and keeps counting on it');

        // Alternating the closure-backed method donor (mint) and the statics-free closure
        // (release) stays memory-flat: every duplicate is released by the next swap, never the
        // closure's table. The closure-backed method is not called in the loop, so every
        // duplicate snapshots k = 103 and the entry answers 104 on the even cycles
        [$growth, $checksum] = self::measureGrowth(static function (int $cycle) use ($method, $class, $instance, $noStatics): int {
            $method->redefine($cycle % 2 === 0 ? $class->getMethod('body') : $noStatics);

            return $instance->m();
        }, self::MEASURED_CYCLES);
        $evenCycles = intdiv(self::MEASURED_CYCLES + 1, 2);
        self::assertSame($evenCycles * 104 - (self::MEASURED_CYCLES - $evenCycles), $checksum, 'every even cycle bound fresh statics from a snapshot of the untouched closure table');
        self::assertLessThan(self::FLATNESS_TOLERANCE_BYTES, $growth, sprintf(
            'memory grew by %d bytes over %d swaps (%.1f bytes per swap; one leaked defaults duplicate is 376 bytes)',
            $growth,
            self::MEASURED_CYCLES,
            $growth / self::MEASURED_CYCLES,
        ));
        self::assertSame($closureTable, self::defaultsAddress($published));
        self::assertSame(104, self::callMethod($instance, 'body'), 'the closure\'s table survived every cycle with its count');
    }

    public function testPlainCompiledMethodDonorKeepsSharingItsDefaultsTable(): void
    {
        // The control for the body-based decision: a plain compiled method's live slot is a
        // map-ptr slot, never its defaults table, and its table lives as long as the body the
        // entry now holds a reference on - so the entry shares it, as before the fix
        $donor      = (new ReflectionClass(ClosureDonorPlainDonor::class))->getMethod('m');
        $donorTable = self::defaultsAddress($donor);
        $donorSlot  = $donor->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertTrue($donorSlot === null || Core::addressOf($donorSlot) !== $donorTable, 'a compiled method does not bind its statics in its defaults table');

        $target = (new ReflectionClass(ClosureDonorPlainTarget::class))->getMethod('m');
        $target->redefine($donor);
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($target->getAddress()), 'no duplicate: the donor body is not a closure\'s');
        self::assertSame($donorTable, self::defaultsAddress($target), 'the entry shares the compiled defaults table');

        $targetInstance = new ClosureDonorPlainTarget();
        self::assertSame(51, $targetInstance->m(), 'the entry binds its own live table from the shared defaults (50)');
        self::assertSame(52, $targetInstance->m());
        self::assertSame(51, (new ClosureDonorPlainDonor())->m(), 'the donor binds a live table of its own');
        self::assertSame(50, self::staticValue(self::defaultsTable($donor), 'n'), 'compiled defaults never move');
        self::assertNotSame(self::liveSlotAddress($target), $donorTable, 'the entry\'s live table is not the shared defaults');
        self::assertSame($donorTable, self::defaultsAddress($target));
    }

    /**
     * Runs the cycle a few times to settle the allocator, then measures the memory_get_usage()
     * growth over $cycles more cycles
     *
     * @param \Closure(int): int $cycle Performs one swap and one call, returns the call result
     *
     * @return array{int, int} The growth in bytes and the sum of the measured cycle results
     */
    private static function measureGrowth(\Closure $cycle, int $cycles): array
    {
        for ($i = 0; $i < 4; $i++) {
            $cycle($i);
        }
        gc_collect_cycles();
        $before   = memory_get_usage();
        $checksum = 0;
        for ($i = 0; $i < $cycles; $i++) {
            $checksum += $cycle($i);
        }
        gc_collect_cycles();

        return [memory_get_usage() - $before, $checksum];
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
     * The statics defaults table (op_array.static_variables) of a user function
     *
     * @return HashTableStruct
     */
    private static function defaultsTable(FunctionLikeInterface $function): object
    {
        $defaultsTable = $function->getOpArrayPointer()->static_variables;
        self::assertNotNull($defaultsTable, 'the body declares static variables');

        return $defaultsTable;
    }

    /**
     * Address of the statics defaults table of a user function
     */
    private static function defaultsAddress(FunctionLikeInterface $function): int
    {
        return Core::addressOf(self::defaultsTable($function));
    }

    /**
     * Address the raw static_variables_ptr slot of a user function holds (plain pointer form:
     * a closure's own table, or the live table an entry materialized)
     */
    private static function liveSlotAddress(FunctionLikeInterface $function): int
    {
        $liveTable = $function->getOpArrayPointer()->static_variables_ptr__ptr;
        self::assertNotNull($liveTable, 'the function has a statics table in its live slot');

        return Core::addressOf($liveTable);
    }

    /**
     * Current value of a static variable in a statics table (defaults or live)
     *
     * @param HashTableStruct $table
     */
    private static function staticValue(object $table, string $variableName): mixed
    {
        $slot = HashTable::fromCData($table)->find($variableName);
        self::assertNotNull($slot, "the table holds the static variable \${$variableName}");
        // A bound slot holds the IS_REFERENCE the executing body shares: read, never written
        $value = null;
        $slot->getNativeValue($value);

        return $value;
    }

    /**
     * Calls a method published at run time (unknown to static analysis) by name
     */
    private static function callMethod(object $instance, string $methodName): mixed
    {
        return (new \ReflectionMethod($instance, $methodName))->invoke($instance);
    }
}
