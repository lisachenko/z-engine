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
use ZEngine\Stub\Alias\MintedAdopter;
use ZEngine\Stub\Alias\MintedChurn;
use ZEngine\Stub\Alias\MintedCounter;
use ZEngine\Stub\Alias\MintedCounterDonor;
use ZEngine\Stub\Alias\MintedOverrideBase;
use ZEngine\Stub\Alias\MintedOverrideChild;
use ZEngine\Stub\Alias\MintedOverrideDonor;
use ZEngine\Stub\Alias\MintedSource;
use ZEngine\Type\ClosureEntry;

/**
 * Ownership of the static-variable defaults tables minted by closure-donor swaps
 *
 * A closure destroys its own statics table when it dies, so a swap adopting a closure body
 * duplicates the defaults (zend_array_dup) for the entry and records the duplicate under the
 * entry's address: the entry's NEXT swap releases it eagerly, because the body arrays stay
 * shared with the closure template and are never freed by the swap itself. That duplicate
 * belongs to exactly one zend_function. Two things used to break the rule on 8.4:
 *
 *  - a container adopting the entry's current body (the alias of a preserving redefine(), the
 *    override published by addMethod()) and an entry taking such an entry as its METHOD donor
 *    copied the op_array - and with it the pointer to the minted table. The source entry's next
 *    swap then freed a table those copies still read (use-after-free, visible under valgrind
 *    only, or as corrupted static values). Every adopter now mints a duplicate of its own, which
 *    the tests pin through FunctionBodySwap::hasMintedStaticDefaults() and distinct
 *    op_array.static_variables addresses;
 *  - the record of the previous duplicate was compared only AFTER the new swap had overwritten
 *    it with its own, so two consecutive closure-donor swaps with statics never matched and the
 *    previous duplicate leaked (one HashTable, 376 bytes, per swap). The record is read before
 *    the swap now, which the memory-flatness tests pin.
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/Minted*): a redefinition changes the
 * class for the rest of the process. With opcache active in the runner the stubs are
 * shared-memory classes: the first mutation copies the class out, so wrappers and instances are
 * taken only after it (the copy-out caveats of docs/hot-swap.md).
 */
class RedefineMintedStaticsTest extends TestCase
{
    /**
     * Tolerance (bytes) for the memory_get_usage() growth over the measured swap cycles
     *
     * Every allocation a swap cycle makes or releases (the defaults duplicate, the run-time
     * cache, the live statics table, the body snapshot) goes through the Zend allocator, which
     * memory_get_usage() tracks exactly: the fixed path measures a growth of 0 in both runner
     * modes. Before the fix every cycle leaked the previous defaults duplicate (a 376-byte
     * HashTable), so the tolerance stays well under a fifth of the smallest leak signature
     * below while leaving room for a dozen stray allocations.
     */
    private const int FLATNESS_TOLERANCE_BYTES = 4 * 1024;

    /**
     * Swap cycles measured on the function entry (376 bytes leaked per cycle before the fix)
     */
    private const int FUNCTION_CYCLES = 300;

    /**
     * Swap cycles measured on the method entry: a method swap walks the class table several
     * times (published shares, engine clones) and the object store for fake closures, which
     * costs a few hundred milliseconds per swap in a test process holding the whole suite, so
     * fewer cycles are measured - 376 bytes per cycle still exceed the tolerance five times over
     */
    private const int METHOD_CYCLES = 60;

    public function testAliasOfAClosureSwappedMethodMintsItsOwnStaticsDefaults(): void
    {
        $closure = static function (): int {
            /** @var int $n */
            static $n = 10;

            return ++$n;
        };
        $method = (new ReflectionClass(MintedCounter::class))->getMethod('counter');
        $method->redefine($closure);
        // From here on $method wraps the writable entry (the copied-out one under opcache)
        $entryAddress   = $method->getAddress();
        $mintedDefaults = self::defaultsAddress($method);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'a closure donor swap mints a defaults duplicate for the entry');
        self::assertNotSame(self::closureDefaultsAddress($closure), $mintedDefaults, 'the closure keeps its own table');

        $instance = new MintedCounter();
        self::assertSame(11, $instance->counter());
        $liveTable = $method->getLiveStaticsPointer();
        self::assertNotNull($liveTable, 'the first call materialized the live statics table from the duplicate');
        $liveAddress = Core::addressOf($liveTable);

        // A method donor without statics replaces the body; the previous body goes under the alias
        $method->redefine((new ReflectionClass(MintedCounterDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');
        $class        = new ReflectionClass(MintedCounter::class);
        $alias        = $class->getMethod('counterOriginal');
        $aliasAddress = $alias->getAddress();
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($aliasAddress), 'the alias container minted a duplicate of its own');
        $aliasDefaults = self::defaultsAddress($alias);
        self::assertNotSame($mintedDefaults, $aliasDefaults, 'the alias does not share the table the swap releases');
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the entry released its duplicate with the previous body (the new body declares no statics)');
        self::assertNull($method->getOpArrayPointer()->static_variables);
        $aliasLiveTable = $alias->getLiveStaticsPointer();
        self::assertNotNull($aliasLiveTable);
        self::assertSame($liveAddress, Core::addressOf($aliasLiveTable), 'the alias took over the live table of the body it preserves');
        self::assertNull($method->getLiveStaticsPointer(), 'the entry starts afresh');

        // The preserved body continues on the live table it kept (n was 11): the duplicate of
        // the defaults (10) only matters for a body whose statics were never materialized
        self::assertSame(112, $instance->counter(), 'the new body delegates to the alias, which continues its own statics');
        self::assertSame(113, $instance->counter());

        // Swap the preserved body back in: the alias is a method donor carrying a minted table,
        // so the entry mints a duplicate again instead of sharing the alias' table
        $method->redefine($class->getMethod('counterOriginal'));
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        self::assertNotSame($aliasDefaults, self::defaultsAddress($method), 'restoring duplicates the defaults again');
        self::assertSame($aliasDefaults, self::defaultsAddress($alias), 'the alias keeps its own duplicate');
        self::assertSame(11, $instance->counter(), 'the restored body materializes its statics from the duplicate of the defaults (10)');
        self::assertSame(12, $instance->counter());
        self::assertSame(14, (new \ReflectionMethod(MintedCounter::class, 'counterOriginal'))->invoke($instance), 'the alias keeps its live statics (11, 12, 13 so far)');

        // Dropping the alias releases its duplicate; the entry's own duplicate is untouched
        $class->removeMethods('counterOriginal');
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($aliasAddress));
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        self::assertFalse(method_exists(MintedCounter::class, 'counterOriginal'));
        self::assertSame(13, $instance->counter());
    }

    public function testAliasOfAClosureSwappedFunctionStartsFromItsOwnDuplicateOfTheDefaults(): void
    {
        require_once __DIR__ . '/../Stub/Alias/minted-functions.php';
        $closureA = static function (): int {
            /** @var int $n */
            static $n = 0;

            return ++$n;
        };
        $closureB = static function (): int {
            /** @var int $k */
            static $k = 100;

            return ++$k;
        };
        $function = new ReflectionFunction('zengine_minted_fixture_fn');
        $function->redefine($closureA);
        $entryAddress  = $function->getAddress();
        $firstDefaults = self::defaultsAddress($function);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        self::assertNull($function->getLiveStaticsPointer(), 'never called: no live table to hand over to the alias');

        // The alias adopts a body whose defaults table was minted by the first swap, while the
        // second swap mints another duplicate for the entry and releases the first one
        $function->redefine($closureB, preserveAs: 'zengine_minted_fixture_fn_v1');
        $alias = new ReflectionFunction('zengine_minted_fixture_fn_v1');
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($alias->getAddress()), 'the alias container minted its own duplicate');
        $aliasDefaults = self::defaultsAddress($alias);
        self::assertNotSame($firstDefaults, $aliasDefaults, 'the alias does not share the duplicate the entry releases');
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the record follows the duplicate minted by the second swap');
        self::assertNotSame($firstDefaults, self::defaultsAddress($function));

        // Nothing was materialized before the alias was published, so its first call binds a
        // fresh live table from its duplicate of the defaults (n = 0), as a plain compiled
        // function would
        self::assertSame(1, self::callFunction('zengine_minted_fixture_fn_v1'));
        self::assertSame(2, self::callFunction('zengine_minted_fixture_fn_v1'));
        self::assertSame(101, \zengine_minted_fixture_fn());

        // The entry's next swap releases the second duplicate; the alias reads its own table
        $function->redefine($closureA);
        self::assertSame(3, self::callFunction('zengine_minted_fixture_fn_v1'));
        self::assertSame(1, \zengine_minted_fixture_fn(), 'every swap starts the statics of the new body afresh');
        self::assertSame($aliasDefaults, self::defaultsAddress($alias));
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($alias->getAddress()));
    }

    public function testMethodDonorCarryingAMintedTableGetsItsOwnDuplicate(): void
    {
        $closure = static function (): int {
            /** @var int $n */
            static $n = 20;

            return ++$n;
        };
        $source = (new ReflectionClass(MintedSource::class))->getMethod('m');
        $source->redefine($closure);
        $sourceDefaults = self::defaultsAddress($source);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($source->getAddress()));
        self::assertSame(21, (new MintedSource())->m());

        // A method donor's table normally lives as long as its body, which the adopter now
        // shares - but a table minted by a swap is released by the SOURCE entry's next swap,
        // so the adopter duplicates it like a closure donor's
        $adopter = (new ReflectionClass(MintedAdopter::class))->getMethod('m');
        $adopter->redefine($source);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($adopter->getAddress()), 'the adopter minted a duplicate of its own');
        $adopterDefaults = self::defaultsAddress($adopter);
        self::assertNotSame($sourceDefaults, $adopterDefaults);
        self::assertSame(21, (new MintedAdopter())->m(), 'the adopter starts from its own copy of the defaults (20)');
        self::assertSame(22, (new MintedSource())->m(), 'the source keeps its own live statics');

        // The source moves on: its duplicate is released, the adopter's stays
        $source->redefine(static fn(): int => -1);
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($source->getAddress()));
        self::assertNull($source->getOpArrayPointer()->static_variables);
        self::assertSame(-1, (new MintedSource())->m());
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($adopter->getAddress()));
        self::assertSame($adopterDefaults, self::defaultsAddress($adopter));
        self::assertSame(22, (new MintedAdopter())->m(), 'the adopter is unaffected by the release of the table it was adopted from');
    }

    public function testOverrideAdoptingAClosureSwappedMethodGetsItsOwnDuplicate(): void
    {
        $closure = static function (): int {
            /** @var int $n */
            static $n = 30;

            return ++$n;
        };
        $donor = (new ReflectionClass(MintedOverrideDonor::class))->getMethod('m');
        $donor->redefine($closure);
        $donorDefaults = self::defaultsAddress($donor);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($donor->getAddress()));
        self::assertSame(31, (new MintedOverrideDonor())->m());

        // The override container adopts the donor body the way the alias container does
        $override = (new ReflectionClass(MintedOverrideChild::class))->addMethod('m', $donor);
        self::assertSame(MintedOverrideChild::class, $override->getDeclaringClass()->getName());
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($override->getAddress()), 'the override minted a duplicate of its own');
        $overrideDefaults = self::defaultsAddress($override);
        self::assertNotSame($donorDefaults, $overrideDefaults);
        self::assertSame(31, (new MintedOverrideChild())->m(), 'the override starts from its own copy of the defaults (30)');
        self::assertSame(32, (new MintedOverrideChild())->m());
        self::assertSame(32, (new MintedOverrideDonor())->m(), 'the donor keeps its own live statics');

        // The donor's next swap releases the donor's duplicate; the override keeps reading its own
        $donor->redefine(static fn(): int => -1);
        self::assertFalse(FunctionBodySwap::hasMintedStaticDefaults($donor->getAddress()));
        self::assertSame(-1, (new MintedOverrideDonor())->m());
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($override->getAddress()));
        self::assertSame($overrideDefaults, self::defaultsAddress($override));
        self::assertSame(33, (new MintedOverrideChild())->m());
        self::assertSame(0, (new MintedOverrideBase())->m(), 'the ancestor is untouched');
    }

    public function testConsecutiveClosureDonorSwapsOfAFunctionAreMemoryFlat(): void
    {
        require_once __DIR__ . '/../Stub/Alias/minted-functions.php';
        [$closureA, $closureB] = self::staticsDonors();
        $function              = new ReflectionFunction('zengine_minted_churn_fn');

        // The mechanism: each swap mints a duplicate and records it under the entry address; the
        // next swap must read that record BEFORE overwriting it with its own, or the previous
        // duplicate is never matched and leaks
        $function->redefine($closureA);
        $entryAddress  = $function->getAddress();
        $firstDefaults = self::defaultsAddress($function);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        self::assertSame(1, \zengine_minted_churn_fn());
        $function->redefine($closureB);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress), 'the record now names the second duplicate');
        self::assertNotSame($firstDefaults, self::defaultsAddress($function));
        self::assertSame(101, \zengine_minted_churn_fn());

        [$growth, $checksum] = self::measureGrowth(static function (int $cycle) use ($function, $closureA, $closureB): int {
            $function->redefine($cycle % 2 === 0 ? $closureA : $closureB);

            return \zengine_minted_churn_fn();
        }, self::FUNCTION_CYCLES);
        self::assertSame(self::expectedChecksum(self::FUNCTION_CYCLES), $checksum, 'every cycle dispatched the freshly swapped-in body with fresh statics');
        self::assertLessThan(self::FLATNESS_TOLERANCE_BYTES, $growth, self::growthReport($growth, self::FUNCTION_CYCLES));
    }

    public function testConsecutiveClosureDonorSwapsOfAMethodAreMemoryFlat(): void
    {
        [$closureA, $closureB] = self::staticsDonors();
        $method                = (new ReflectionClass(MintedChurn::class))->getMethod('m');
        // The first swap copies the class out of shared memory under opcache: the instance is
        // created only afterwards, so it dispatches the writable entry
        $method->redefine($closureB);
        $entryAddress  = $method->getAddress();
        $firstDefaults = self::defaultsAddress($method);
        $instance      = new MintedChurn();
        self::assertSame(101, $instance->m());
        $method->redefine($closureA);
        self::assertTrue(FunctionBodySwap::hasMintedStaticDefaults($entryAddress));
        self::assertNotSame($firstDefaults, self::defaultsAddress($method));
        self::assertSame(1, $instance->m());

        [$growth, $checksum] = self::measureGrowth(static function (int $cycle) use ($method, $instance, $closureA, $closureB): int {
            $method->redefine($cycle % 2 === 0 ? $closureA : $closureB);

            return $instance->m();
        }, self::METHOD_CYCLES);
        self::assertSame(self::expectedChecksum(self::METHOD_CYCLES), $checksum, 'every cycle dispatched the freshly swapped-in body with fresh statics');
        self::assertLessThan(self::FLATNESS_TOLERANCE_BYTES, $growth, self::growthReport($growth, self::METHOD_CYCLES));
    }

    /**
     * Two closure donors declaring static variables: the first returns 1 on a fresh table,
     * the second 101
     *
     * @return array{\Closure(): int, \Closure(): int}
     */
    private static function staticsDonors(): array
    {
        $closureA = static function (): int {
            /** @var int $n */
            static $n = 0;

            return ++$n;
        };
        $closureB = static function (): int {
            /** @var int $k */
            static $k = 100;

            return ++$k;
        };

        return [$closureA, $closureB];
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
        for ($i = 0; $i < 10; $i++) {
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
     * Sum of the results of $cycles alternating cycles: 1 on even cycles, 101 on odd ones
     */
    private static function expectedChecksum(int $cycles): int
    {
        $evenCycles = intdiv($cycles + 1, 2);
        $oddCycles  = $cycles - $evenCycles;

        return $evenCycles * 1 + $oddCycles * 101;
    }

    private static function growthReport(int $growth, int $cycles): string
    {
        return sprintf(
            'memory grew by %d bytes over %d swaps (%.1f bytes per swap; one leaked defaults duplicate is 376 bytes)',
            $growth,
            $cycles,
            $growth / $cycles,
        );
    }

    /**
     * Address of the statics defaults table (op_array.static_variables) of a user function
     */
    private static function defaultsAddress(FunctionLikeInterface $function): int
    {
        $defaultsTable = $function->getOpArrayPointer()->static_variables;
        self::assertNotNull($defaultsTable, 'the body declares static variables');

        return Core::addressOf($defaultsTable);
    }

    /**
     * Address of the statics table owned by a closure object (its own zend_function)
     */
    private static function closureDefaultsAddress(\Closure $closure): int
    {
        $closureFunction = ReflectionFunction::fromCData(Core::addr((new ClosureEntry($closure))->getRawFunction()));

        return self::defaultsAddress($closureFunction);
    }

    /**
     * Calls a global function published at run time (unknown to static analysis) by name
     */
    private static function callFunction(string $functionName): mixed
    {
        return (new \ReflectionFunction($functionName))->invoke();
    }
}
