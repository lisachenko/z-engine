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

namespace ZEngine\HotSwap;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Reflection\ReflectionClass;

class ClassDeltaTest extends TestCase
{
    /**
     * Declares a uniquely named test class and returns its name
     *
     * The classes exist only at runtime (eval), so all member access in the tests
     * goes through the native reflection API to stay statically analyzable.
     */
    private function declareClass(string $suffix, string $bodySource): string
    {
        $className = 'HotSwapTarget' . $suffix;
        eval("class {$className} {$bodySource}");

        return $className;
    }

    private static function newInstance(string $className): object
    {
        assert(class_exists($className));
        $instance = (new \ReflectionClass($className))->newInstance();

        return $instance;
    }

    private static function callMethod(object $instance, string $methodName, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($instance, $methodName))->invoke($instance, ...$arguments);
    }

    private static function readProperty(object $instance, string $propertyName): mixed
    {
        return (new \ReflectionProperty($instance, $propertyName))->getValue($instance);
    }

    public function testBodyChangeAppliesToLiveInstances(): void
    {
        $className = $this->declareClass('Body', '{ public function greet(): string { return "v1"; } }');
        $instance  = self::newInstance($className);
        $this->assertSame('v1', self::callMethod($instance, 'greet'));

        $delta = HotSwap::prepare($className, "class {$className} { public function greet(): string { return \"v2\"; } }");
        $this->assertSame(['greet'], $delta->getChangedMethods());
        $this->assertSame([], $delta->getAddedMethods());
        $this->assertSame([], $delta->getRemovedMethods());
        $delta->apply();

        // The already-created instance dispatches through the swapped body
        $this->assertSame('v2', self::callMethod($instance, 'greet'));
        $this->assertSame('v2', self::callMethod(self::newInstance($className), 'greet'));
    }

    public function testBodyChangePropagatesToLinkedSubclasses(): void
    {
        $className = $this->declareClass('Parent', '{ public function greet(): string { return "parent-v1"; } }');
        $childName = $className . 'Child';
        eval("class {$childName} extends {$className} {}");
        $this->assertSame('parent-v1', self::callMethod(self::newInstance($childName), 'greet'));

        HotSwap::prepare($className, "class {$className} { public function greet(): string { return \"parent-v2\"; } }")
            ->apply();

        // Subclass method buckets share the parent's function structure
        $this->assertSame('parent-v2', self::callMethod(self::newInstance($childName), 'greet'));
    }

    public function testAddedMethod(): void
    {
        $className = $this->declareClass('Add', '{ public function one(): int { return 1; } }');

        $delta = HotSwap::prepare(
            $className,
            "class {$className} { public function one(): int { return 1; } public function two(): int { return 2; } }",
        );
        $this->assertSame(['two'], $delta->getAddedMethods());
        $delta->apply();

        $this->assertTrue(method_exists($className, 'two'));
        // The added method dispatches through the ordinary VM and is reflectable
        $this->assertSame(2, self::callMethod(self::newInstance($className), 'two'));
    }

    public function testRemovedMethod(): void
    {
        $className = $this->declareClass(
            'Remove',
            '{ public function keep(): int { return 1; } public function drop(): int { return 2; } }',
        );

        $delta = HotSwap::prepare($className, "class {$className} { public function keep(): int { return 1; } }");
        $this->assertSame(['drop'], $delta->getRemovedMethods());
        $delta->apply();

        $this->assertFalse(method_exists($className, 'drop'));
        $instance = self::newInstance($className);
        $this->assertSame(1, self::callMethod($instance, 'keep'));
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Call to undefined method');
        // The removed name comes from the runtime delta, dispatch through the VM
        $removedName = $delta->getRemovedMethods()[0];
        $instance->{$removedName}();
    }

    public function testRemovedOverrideRestoresInheritedMethod(): void
    {
        $baseName  = 'HotSwapBaseForRemoval';
        $className = 'HotSwapOverrideRemoval';
        eval("class {$baseName} { public function speak(): string { return \"base\"; } }");
        eval("class {$className} extends {$baseName} { public function speak(): string { return \"override\"; } }");
        $this->assertSame('override', self::callMethod(self::newInstance($className), 'speak'));

        HotSwap::prepare($className, "class {$className} extends {$baseName} {}")->apply();

        // The ancestor declaration becomes visible again, like ordinary inheritance
        $restoredInstance = self::newInstance($className);
        $this->assertTrue(method_exists($restoredInstance, 'speak'));
        $this->assertSame('base', self::callMethod($restoredInstance, 'speak'));
    }

    public function testConstantChangeAndAddition(): void
    {
        $className = $this->declareClass('Const', '{ public const VERSION = 1; }');
        $this->assertSame(1, constant("{$className}::VERSION"));

        $delta = HotSwap::prepare(
            $className,
            "class {$className} { public const VERSION = 2; public const CODENAME = \"swapped\"; }",
        );
        $this->assertSame(['VERSION'], $delta->getChangedConstants());
        $this->assertSame(['CODENAME'], $delta->getAddedConstants());
        $delta->apply();

        $this->assertSame(2, constant("{$className}::VERSION"));
        $this->assertSame('swapped', constant("{$className}::CODENAME"));
    }

    public function testDefaultPropertyChange(): void
    {
        $className       = $this->declareClass('Prop', '{ public int $counter = 10; public static string $mode = "cold"; }');
        $preSwapInstance = self::newInstance($className);

        $delta = HotSwap::prepare(
            $className,
            "class {$className} { public int \$counter = 42; public static string \$mode = \"hot\"; }",
        );
        $this->assertSame(['counter'], $delta->getChangedProperties());
        $this->assertSame(['mode'], $delta->getStaticChangedProperties());
        $delta->apply();

        // New instances see the new default, live instances keep their state
        $this->assertSame(42, self::readProperty(self::newInstance($className), 'counter'));
        $this->assertSame(10, self::readProperty($preSwapInstance, 'counter'));
        // The static default was updated before the statics were materialized
        assert(class_exists($className));
        $this->assertSame('hot', (new \ReflectionProperty($className, 'mode'))->getValue());
    }

    public function testGenuineFailureRollsBackStagedOperations(): void
    {
        $className = $this->declareClass(
            'Rollback',
            '{ public const K = 1; public int $value = 5; public function a(): int { return 1; } }',
        );

        // The delta plans to change a()'s body AND add a new method b(); it is computed
        // while b() does not exist yet
        $delta = HotSwap::prepare(
            $className,
            "class {$className} { public const K = 2; public int \$value = 7; "
            . 'public function a(): int { return 10; } public function b(): int { return 20; } }',
        );
        $this->assertSame(['a'], $delta->getChangedMethods());
        $this->assertSame(['b'], $delta->getAddedMethods());

        // Genuinely make the added-method publish fail: install b() out of band between
        // prepare() and apply(), so the engine refuses the duplicate table key. apply()
        // has already staged the a() body swap by then, which the rollback must revert.
        $reflectionClass = new ReflectionClass($className);
        $reflectionClass->addMethod('b', static fn(): int => 99);

        try {
            $delta->apply();
            $this->fail('Apply must fail on the duplicate method publish');
        } catch (HotSwapException $exception) {
            $this->assertStringContainsString('rolled back', $exception->getMessage());
        }

        // No half-swapped state is observable: the a() body swap was rolled back, the
        // constant/default changes (staged after the failing add) were never applied
        $this->assertSame(1, self::callMethod(self::newInstance($className), 'a'));
        $this->assertSame(1, constant("{$className}::K"));
        $this->assertSame(5, self::readProperty(self::newInstance($className), 'value'));
        // The out-of-band b() the failure was triggered with is still the live one
        $this->assertSame(99, self::callMethod(self::newInstance($className), 'b'));

        // The class stays fully functional for a subsequent successful swap
        HotSwap::prepare(
            $className,
            "class {$className} { public const K = 3; public int \$value = 5; "
            . 'public function a(): int { return 30; } public function b(): int { return 99; } }',
        )->apply();
        $this->assertSame(30, self::callMethod(self::newInstance($className), 'a'));
        $this->assertSame(3, constant("{$className}::K"));
    }

    public function testRejectsPropertySurfaceChange(): void
    {
        $className = $this->declareClass('Surface', '{ public int $a = 1; }');

        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('property surface');
        HotSwap::prepare($className, "class {$className} { public int \$a = 1; public int \$b = 2; }");
    }

    public function testRejectsHierarchyChange(): void
    {
        $baseName = 'HotSwapUnrelatedBase';
        eval("class {$baseName} {}");
        $className = $this->declareClass('Hierarchy', '{}');

        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('parent class');
        HotSwap::prepare($className, "class {$className} extends {$baseName} {}");
    }

    public function testRejectsSourceWithoutTheClass(): void
    {
        $className = $this->declareClass('Missing', '{}');

        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('must declare class');
        HotSwap::prepare($className, 'class SomethingCompletelyDifferent {}');
    }

    public function testRejectsUnparsableSource(): void
    {
        $className = $this->declareClass('Parse', '{}');

        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('does not parse');
        HotSwap::prepare($className, "class {$className} { this is not php }");
    }

    public function testRejectsUnknownClass(): void
    {
        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('not loaded');
        HotSwap::prepare('CompletelyUnknownHotSwapClass', 'class CompletelyUnknownHotSwapClass {}');
    }

    public function testRejectsInternalClass(): void
    {
        $this->expectException(HotSwapException::class);
        HotSwap::prepare(\ArrayObject::class, 'class ArrayObject {}');
    }

    public function testRejectsMagicMethodAddition(): void
    {
        $className = $this->declareClass('Magic', '{}');

        $this->expectException(HotSwapException::class);
        $this->expectExceptionMessage('magic method');
        HotSwap::prepare(
            $className,
            "class {$className} { public function __get(string \$name): mixed { return null; } }",
        );
    }

    public function testDiscardLeavesClassUntouched(): void
    {
        $className = $this->declareClass('Discard', '{ public function ping(): string { return "v1"; } }');

        $delta = HotSwap::prepare($className, "class {$className} { public function ping(): string { return \"v2\"; } }");
        $delta->discard();

        $this->assertSame('v1', self::callMethod(self::newInstance($className), 'ping'));
        $this->expectException(HotSwapException::class);
        $delta->apply();
    }

    public function testIdenticalSourceProducesNoFalsePositives(): void
    {
        $source    = '{ public const K = 5; public int $v = 3; public function m(): int { return self::K; } }';
        $className = $this->declareClass('Same', $source);

        // The identical source produces no false constant/default/addition entries;
        // method bodies may still be conservatively re-swapped (line numbers differ)
        $delta = HotSwap::prepare($className, "class {$className} {$source}");
        $this->assertSame([], $delta->getAddedMethods());
        $this->assertSame([], $delta->getRemovedMethods());
        $this->assertSame([], $delta->getChangedConstants());
        $this->assertSame([], $delta->getAddedConstants());
        $this->assertSame([], $delta->getChangedProperties());
        $this->assertSame([], $delta->getStaticChangedProperties());
        $delta->apply();
        $this->assertSame(5, self::callMethod(self::newInstance($className), 'm'));
    }

    #[RunInSeparateProcess]
    public function testPreservesInstalledObjectHandlersAndHooks(): void
    {
        $className = $this->declareClass('Hooked', '{ public function tag(): string { return "v1"; } }');

        $refClass = new ReflectionClass($className);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
        $refClass->setCompareValuesHandler(function (CompareValuesHook $hook) {
            // Custom semantics: every comparison reports equality
            return 0;
        });

        $first  = self::newInstance($className);
        $second = self::newInstance($className);
        $this->assertTrue($first == $second);

        HotSwap::prepare($className, "class {$className} { public function tag(): string { return \"v2\"; } }")
            ->apply();

        // The swapped class keeps the installed handlers: comparison still reports
        // equality, and freshly created objects go through the custom create handler
        $this->assertSame('v2', self::callMethod($first, 'tag'));
        $third = self::newInstance($className);
        $this->assertTrue($first == $third);
        $this->assertTrue($second == $third);
    }

    public function testTypedExceptionHierarchy(): void
    {
        // The API boundary contract: both rejection channels are ReflectionExceptions,
        // so existing catch blocks keep working while the types stay distinguishable
        $sharedMemoryParents = class_parents(SharedMemoryException::class);
        $hotSwapParents      = class_parents(HotSwapException::class);
        $this->assertIsArray($sharedMemoryParents);
        $this->assertIsArray($hotSwapParents);
        $this->assertContains(\ReflectionException::class, $sharedMemoryParents);
        $this->assertContains(\ReflectionException::class, $hotSwapParents);
    }
}
