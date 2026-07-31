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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\ClassExtension\Hook\CountElementsHook;
use ZEngine\ClassExtension\Hook\HasDimensionHook;
use ZEngine\ClassExtension\Hook\ReadDimensionHook;
use ZEngine\ClassExtension\Hook\UnsetDimensionHook;
use ZEngine\ClassExtension\Hook\WriteDimensionHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Stub\NativeCollection;
use ZEngine\Stub\TestClass;

/**
 * Tests for the dimension family of object handlers (read/write/has/unset_dimension + count_elements)
 *
 * The hooked classes intentionally do NOT implement ArrayAccess - engine-level overloading
 * is the feature under test - so instances are annotated with the intersection types
 * describing their actual runtime behavior once the handlers are installed. Countable IS
 * implemented by the count-hooked classes: debug builds verify count()'s arginfo before
 * the count_elements handler is consulted (see ObjectCountElementsInterface), exactly like
 * the engine's own count_elements classes do.
 */
#[Group('internal')]
class ReflectionClassDimensionTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesDimensionRead(): void
    {
        $collection = $this->createHookedCollection(['a', 'b']);

        $this->assertSame('b', $collection[1]);
        $this->assertSame('a', $collection[0]);
        // Unknown offsets are resolved by the stub handler to null
        $this->assertNull($collection[42]);
        $this->assertSame('b', $collection['1'], 'Numeric string offsets should behave like integer ones');
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesDimensionWrite(): void
    {
        $collection = $this->createHookedCollection();

        $collection[10] = 'value';
        $this->assertSame('value', $collection[10]);

        $collection['key'] = 42;
        $this->assertSame(42, $collection['key']);
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesDimensionAppendWithNullOffset(): void
    {
        $collection = $this->createHookedCollection(['a', 'b']);

        // Append receives offset == NULL in the engine handler, getOffset() must return null
        $collection[] = 'c';
        $this->assertSame('c', $collection[2]);
        $this->assertSame(3, count($collection));
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesDimensionIssetAndEmpty(): void
    {
        $collection = $this->createHookedCollection(['value', '']);

        $this->assertTrue(isset($collection[0]));
        $this->assertFalse(isset($collection[42]));

        // empty() dispatches with check type 1 and looks at the value itself
        $this->assertFalse(empty($collection[0]));
        $this->assertTrue(empty($collection[1]));
        $this->assertTrue(empty($collection[42]));
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesDimensionUnset(): void
    {
        $collection = $this->createHookedCollection(['a', 'b']);

        unset($collection[0]);
        $this->assertFalse(isset($collection[0]));
        $this->assertTrue(isset($collection[1]));
        $this->assertSame(1, count($collection));
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesCountElements(): void
    {
        $collection = $this->createHookedCollection(['a', 'b']);

        $this->assertSame(2, count($collection));

        $collection[] = 'c';
        $this->assertSame(3, count($collection));
    }

    #[RunInSeparateProcess]
    public function testReadDimensionHandlerReceivesOffsetAndAccessType(): void
    {
        $refClass = $this->createTestClassReflection();
        $offsets  = [];
        $types    = [];
        $refClass->setReadDimensionHandler(function (ReadDimensionHook $hook) use (&$offsets, &$types) {
            $offsets[] = $hook->getOffset();
            $types[]   = $hook->getAccessType();

            return 'intercepted';
        });

        $instance = $this->newHookedTestClass();
        $this->assertSame('intercepted', $instance[5]);
        $this->assertSame('intercepted', $instance['name']);
        $this->assertSame([5, 'name'], $offsets);
        // Plain reads are performed with BP_VAR_R == 0 access type
        $this->assertSame([0, 0], $types);
    }

    #[RunInSeparateProcess]
    public function testProceedFallsThroughToOriginalArrayAccessHandlers(): void
    {
        $refClass = $this->createFixtureReflection();
        $refClass->setReadDimensionHandler(function (ReadDimensionHook $hook) {
            // Fall through to the engine handler, which dispatches to offsetGet()
            $value = $hook->proceed();
            assert(is_string($value));

            return 'hooked-' . $value;
        });
        $refClass->setHasDimensionHandler(function (HasDimensionHook $hook) {
            // Fall through to the engine handler (offsetExists()) and invert its result
            return (int) !$hook->proceed();
        });

        $instance = new DimensionArrayAccessFixture(['value']);

        $this->assertSame('hooked-value', $instance[0]);
        $this->assertFalse(isset($instance[0]));
        $this->assertTrue(isset($instance[42]));
    }

    #[RunInSeparateProcess]
    public function testWriteDimensionHandlerInterceptsWritesAndAppends(): void
    {
        $refClass = $this->createTestClassReflection();
        $log      = [];
        $refClass->setWriteDimensionHandler(function (WriteDimensionHook $hook) use (&$log) {
            $log[] = [$hook->getOffset(), $hook->getValue()];
        });

        $instance      = $this->newHookedTestClass();
        $instance[1]   = 'one';
        $instance[]    = 'appended';
        $instance['x'] = 42;

        $this->assertSame([[1, 'one'], [null, 'appended'], ['x', 42]], $log);
    }

    #[RunInSeparateProcess]
    public function testUnsetDimensionHandlerInterceptsUnset(): void
    {
        $refClass = $this->createTestClassReflection();
        $log      = [];
        $refClass->setUnsetDimensionHandler(function (UnsetDimensionHook $hook) use (&$log) {
            $log[] = $hook->getOffset();
        });

        $instance = $this->newHookedTestClass();
        unset($instance[7], $instance['key']);

        $this->assertSame([7, 'key'], $log);
    }

    #[RunInSeparateProcess]
    public function testCountElementsHookWinsOverCountableAndHasNoOriginalHandler(): void
    {
        $refClass = $this->createFixtureReflection();
        $refClass->setCountElementsHandler(function (CountElementsHook $hook) {
            // std_object_handlers has no count_elements entry, so nothing to proceed to
            $this->assertFalse($hook->hasOriginalHandler());

            return 42;
        });

        $instance = new DimensionArrayAccessFixture(['value']);

        // The engine consults the count_elements handler before Countable::count(),
        // which would have reported 1 - proof that the hook intercepted the call
        $this->assertSame(42, count($instance));
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalReadDimensionBehavior(): void
    {
        $refClass = $this->createTestClassReflection();
        $hook     = $refClass->setReadDimensionHandler(function (ReadDimensionHook $hook) {
            return 'intercepted';
        });

        $instance = $this->newHookedTestClass();
        $this->assertSame('intercepted', $instance[0]);

        $hook->uninstall();

        // With the hook removed, the engine's original handler rejects array access again
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot use object of type ' . TestClass::class . ' as array');
        $unused = $instance[0];
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalCountBehavior(): void
    {
        $refClass = $this->createFixtureReflection();
        $hook     = $refClass->setCountElementsHandler(function (CountElementsHook $hook) {
            return 42;
        });

        $instance = new DimensionArrayAccessFixture(['value']);
        $this->assertSame(42, count($instance));

        $hook->uninstall();

        // With the hook removed, count() falls back to the real Countable::count()
        $this->assertSame(1, count($instance));
    }

    /**
     * Creates a NativeCollection with all extension handlers installed
     *
     * @param array<array-key, mixed> $items
     *
     * @return NativeCollection&\ArrayAccess<array-key, mixed>&\Countable
     */
    private function createHookedCollection(array $items = [])
    {
        $refClass = new ReflectionClass(NativeCollection::class);
        $refClass->installExtensionHandlers();

        /** @var NativeCollection&\ArrayAccess<array-key, mixed>&\Countable $collection */
        $collection = new NativeCollection($items);

        return $collection;
    }

    /**
     * Creates a TestClass instance that received the adjustable object handlers structure
     *
     * @return TestClass&\ArrayAccess<array-key, mixed>
     */
    private function newHookedTestClass()
    {
        /** @var TestClass&\ArrayAccess<array-key, mixed> $instance */
        $instance = new TestClass();

        return $instance;
    }

    /**
     * Creates a TestClass reflection with the create_object handler installed,
     * so new instances receive the adjustable object handlers structure
     */
    private function createTestClassReflection(): ReflectionClass
    {
        $refClass = new ReflectionClass(TestClass::class);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));

        return $refClass;
    }

    /**
     * Creates a DimensionArrayAccessFixture reflection with the create_object handler
     * installed, so new instances receive the adjustable object handlers structure
     */
    private function createFixtureReflection(): ReflectionClass
    {
        $refClass = new ReflectionClass(DimensionArrayAccessFixture::class);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));

        return $refClass;
    }
}

/**
 * Fixture with real ArrayAccess/Countable behavior behind the hooks: used to prove
 * proceed() falls through to the original engine handlers, and that count() falls back
 * to Countable::count() once the count_elements hook is uninstalled. Countable is also
 * what keeps debug builds happy - they verify count()'s arginfo ("Countable|array")
 * before the count_elements handler is consulted (see ObjectCountElementsInterface)
 *
 * @implements \ArrayAccess<array-key, mixed>
 */
class DimensionArrayAccessFixture implements \ArrayAccess, \Countable
{
    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(private array $items = []) {}

    public function offsetExists(mixed $offset): bool
    {
        assert(is_int($offset) || is_string($offset));

        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        assert(is_int($offset) || is_string($offset));

        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        assert($offset === null || is_int($offset) || is_string($offset));
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        assert(is_int($offset) || is_string($offset));
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
