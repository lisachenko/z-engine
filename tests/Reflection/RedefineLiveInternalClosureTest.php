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
use ZEngine\Stub\Alias\InternalClosureCounter;

/**
 * A swap walks the live closures to find the copies of the entry (fake closures over it
 * share its run-time cache and borrow its live statics). Closures over INTERNAL functions
 * are live objects too - a first-class callable of strlen(), Closure::fromCallable() over
 * an internal function, the closure of an internal method - and carry no user op_array: the
 * walk must skip them before building a user-function view, which has no name field to
 * read on an internal entry (it threw "Attempt to read undefined field 'function_name'"
 * and failed every redefine() while such a closure was alive; PHP 8.6 keeps such closures
 * in its callable caches, so there it failed always).
 */
final class RedefineLiveInternalClosureTest extends TestCase
{
    public function testSwapsRemovalsAndStaticsReleaseIgnoreClosuresOverInternalFunctions(): void
    {
        $firstClass = strlen(...);
        $callable   = \Closure::fromCallable('strtoupper');
        $method     = (new \ReflectionMethod(\ArrayObject::class, 'count'))->getClosure(new \ArrayObject([1, 2]));

        $entry = (new ReflectionClass(InternalClosureCounter::class))->getMethod('counter');
        $entry->redefine(function (): int {
            /** @var int $n */
            static $n = 10;

            return ++$n;
        });
        // Instantiated after the first swap: under the opcache-active runner the class is
        // copied out of shared memory by it, and instances made before keep the shared class
        $instance = new InternalClosureCounter();
        self::assertSame(11, $instance->counter(), 'the live statics table of the swapped body materializes');

        // Every release path that walks the live closures: the minted cache of the previous
        // body (second swap), the live statics the preserved alias takes over, and what
        // removeMethods() retires
        $entry->redefine(function (): int {
            /** @var int $n */
            static $n = 20;

            return ++$n;
        }, preserveAs: 'counterPrevious');
        self::assertSame(21, $instance->counter());
        self::assertSame(22, $instance->counter());
        (new ReflectionClass(InternalClosureCounter::class))->removeMethods('counterPrevious');
        self::assertSame(23, $instance->counter());

        self::assertSame(3, $firstClass('abc'), 'the closures over internal functions are untouched');
        self::assertSame('ABC', $callable('abc'));
        self::assertSame(2, $method());
    }
}
