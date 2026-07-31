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

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZEngine\ClassExtension\Hook\GetIteratorHook;
use ZEngine\ClassExtension\Hook\IteratorBridge;
use ZEngine\Stub\NativeIterable;

/**
 * Tests for the engine-level get_iterator hook (foreach over classes without \Traversable)
 *
 * The hooked class intentionally does NOT implement \Traversable - engine-level iteration
 * is the feature under test. The engine drives the userland Iterator returned by the
 * handler through a native zend_object_iterator bridged by IteratorBridge.
 */
#[Group('internal')]
class ReflectionClassIteratorTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesForeachWithKeysAndValues(): void
    {
        // Interface-driven wiring: ObjectGetIteratorInterface + installExtensionHandlers()
        $refClass = new ReflectionClass(NativeIterable::class);
        $refClass->installExtensionHandlers();
        // The stub intentionally does not implement \Traversable (engine-level iteration is
        // the feature under test), so instances are annotated with their runtime behavior
        /** @var NativeIterable&\Traversable<string, int> $iterable */
        $iterable = new NativeIterable(['a' => 1, 'b' => 2, 'c' => 3]);

        $seen = [];
        foreach ($iterable as $key => $value) {
            $seen[$key] = $value;
        }

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $seen);
        $this->assertSame(0, IteratorBridge::activeIteratorCount(), 'Completed loop must release its iterator');
    }

    #[RunInSeparateProcess]
    public function testForeachDrivesEveryUserlandIteratorMethod(): void
    {
        $calls    = [];
        $refClass = new ReflectionClass(NativeIterable::class);
        $hook     = $refClass->setGetIteratorHandler(
            static function (GetIteratorHook $hook) use (&$calls): Iterator {
                $object = $hook->getObject();
                assert($object instanceof NativeIterable);

                $recorder = static function (string $method) use (&$calls): void {
                    $calls[] = $method;
                };

                return new class ($object->items, $recorder) implements Iterator {
                    /**
                     * @param array<array-key, mixed> $items
                     * @param Closure(string): void $recorder
                     */
                    public function __construct(private array $items, private readonly Closure $recorder) {}

                    public function current(): mixed
                    {
                        ($this->recorder)('current');

                        return current($this->items);
                    }

                    public function key(): mixed
                    {
                        ($this->recorder)('key');

                        return key($this->items);
                    }

                    public function next(): void
                    {
                        ($this->recorder)('next');
                        next($this->items);
                    }

                    public function rewind(): void
                    {
                        ($this->recorder)('rewind');
                        reset($this->items);
                    }

                    public function valid(): bool
                    {
                        ($this->recorder)('valid');

                        return key($this->items) !== null;
                    }
                };
            },
        );

        /** @var NativeIterable&\Traversable<int, int> $iterable */
        $iterable = new NativeIterable([10, 20]);
        $seen     = [];
        foreach ($iterable as $key => $value) {
            $seen[$key] = $value;
        }

        $this->assertSame([10, 20], $seen);
        $this->assertContains('rewind', $calls);
        $this->assertContains('valid', $calls);
        $this->assertContains('current', $calls);
        $this->assertContains('key', $calls);
        $this->assertContains('next', $calls);
        $this->assertTrue($hook->isInstalled());
    }

    #[RunInSeparateProcess]
    public function testNestedForeachOverTwoInstances(): void
    {
        $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<string, int> $outer */
        $outer = new NativeIterable(['a' => 1, 'b' => 2]);
        /** @var NativeIterable&\Traversable<string, int> $inner */
        $inner = new NativeIterable(['x' => 10, 'y' => 20]);

        $pairs = [];
        foreach ($outer as $outerKey => $outerValue) {
            foreach ($inner as $innerKey => $innerValue) {
                $pairs[] = "{$outerKey}{$innerKey}:" . ($outerValue + $innerValue);
            }
        }

        $this->assertSame(['ax:11', 'ay:21', 'bx:12', 'by:22'], $pairs);
        $this->assertSame(0, IteratorBridge::activeIteratorCount());
    }

    #[RunInSeparateProcess]
    public function testNestedForeachOverSameInstance(): void
    {
        $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<string, int> $iterable */
        $iterable = new NativeIterable(['a' => 1, 'b' => 2]);

        $pairs = [];
        foreach ($iterable as $outerKey => $outerValue) {
            foreach ($iterable as $innerKey => $innerValue) {
                $pairs[] = $outerKey . $innerKey;
            }
        }

        $this->assertSame(['aa', 'ab', 'ba', 'bb'], $pairs, 'Each loop must get its own iterator instance');
        $this->assertSame(0, IteratorBridge::activeIteratorCount());
    }

    #[RunInSeparateProcess]
    public function testBreakOutOfLoopReleasesIteratorState(): void
    {
        $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<string, int> $iterable */
        $iterable = new NativeIterable(['a' => 1, 'b' => 2, 'c' => 3]);

        $seen = [];
        foreach ($iterable as $key => $value) {
            $seen[$key] = $value;
            if ($value === 2) {
                break;
            }
        }

        $this->assertSame(['a' => 1, 'b' => 2], $seen);
        // FE_FREE released the engine iterator: the dtor callback dropped the bridge state
        $this->assertSame(0, IteratorBridge::activeIteratorCount());
    }

    #[RunInSeparateProcess]
    public function testByReferenceIterationIsRejectedCleanly(): void
    {
        $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<string, int> $iterable */
        $iterable = new NativeIterable(['a' => 1]);

        // The hook returns no iterator for by_ref requests (without touching EG(exception)),
        // so the engine raises its standard "did not create an Iterator" exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Object of type ZEngine\Stub\NativeIterable did not create an Iterator');

        foreach ($iterable as &$value) {
            $this->fail('By-reference iteration must not produce any value');
        }
    }

    #[RunInSeparateProcess]
    public function testThrowingIteratorTerminatesIterationWithWarning(): void
    {
        $refClass = new ReflectionClass(NativeIterable::class);
        $refClass->setGetIteratorHandler(static function (GetIteratorHook $hook): Iterator {
            return new class implements Iterator {
                public function current(): mixed
                {
                    throw new RuntimeException('current is broken');
                }

                public function key(): mixed
                {
                    return 0;
                }

                public function next(): void {}

                public function rewind(): void {}

                public function valid(): bool
                {
                    return true;
                }
            };
        });

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $iterations = 0;
            /** @var NativeIterable&\Traversable<int, int> $iterable */
            $iterable = new NativeIterable([1]);
            foreach ($iterable as $value) {
                $iterations++;
            }
        } finally {
            restore_error_handler();
        }

        // Exceptions cannot cross the FFI boundary (issue #50): the bridge converts the
        // Throwable into a warning and ends the iteration instead of crashing the engine
        $this->assertSame(0, $iterations);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('current is broken', $warnings[0]);
        $this->assertStringContainsString('issue #50', $warnings[0]);
        $this->assertSame(0, IteratorBridge::activeIteratorCount());
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresDefaultPropertyIteration(): void
    {
        $hook = $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<string, array<string, int>|int> $iterable */
        $iterable = new NativeIterable(['a' => 1, 'b' => 2]);

        $hooked = [];
        foreach ($iterable as $key => $value) {
            $hooked[$key] = $value;
        }
        $this->assertSame(['a' => 1, 'b' => 2], $hooked);

        $hook->uninstall();
        $this->assertFalse($hook->isInstalled());

        // ce->get_iterator is NULL again: foreach falls back to public property iteration
        $properties = [];
        foreach ($iterable as $key => $value) {
            $properties[$key] = $value;
        }
        $this->assertSame(['items' => ['a' => 1, 'b' => 2]], $properties);
    }

    #[RunInSeparateProcess]
    public function testArgumentUnpackingGoesThroughTheHook(): void
    {
        $this->installIteratorHook();
        /** @var NativeIterable&\Traversable<int, int> $iterable */
        $iterable  = new NativeIterable([1, 2, 3]);
        $collector = static fn(mixed ...$arguments): array => $arguments;

        $this->assertSame([1, 2, 3], $collector(...$iterable));
    }

    private function installIteratorHook(): GetIteratorHook
    {
        $refClass = new ReflectionClass(NativeIterable::class);

        return $refClass->setGetIteratorHandler(
            Closure::fromCallable([NativeIterable::class, '__getIterator']),
        );
    }
}
