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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Stub\TestChildClass;
use ZEngine\Stub\TestParentClass;

/**
 * Covers the full parent detachment performed by removeParentClass() and the re-linking
 * via setParent(). Every test mutates the engine state of TestChildClass destructively and
 * therefore runs in the process-isolated `internal` group.
 */
class ReflectionClassParentTest extends TestCase
{
    private ReflectionClass $refClass;

    public function setUp(): void
    {
        $this->refClass = new ReflectionClass(TestChildClass::class);
    }

    #[Group('internal')]
    public function testRemoveParentClassDetachesParentLink(): void
    {
        $this->refClass->removeParentClass();

        // The engine-level class name breaks static analysis constant folding on purpose:
        // these relations exist only at runtime after the surgery
        $className = $this->refClass->getName();
        $this->assertFalse(get_parent_class($className));
        $this->assertFalse(is_subclass_of($className, TestParentClass::class));
        // An instance created after the detachment does not pass the parent type check
        $instance = new TestChildClass();
        $this->assertFalse(is_subclass_of(get_class($instance) . '', TestParentClass::class));
    }

    #[Group('internal')]
    public function testRemoveParentClassDetachesConstants(): void
    {
        $this->refClass->removeParentClass();

        $this->assertFalse(defined(TestChildClass::class . '::PARENT_CONST'));
        // Own constants stay reachable, also through the low-level constants table
        $this->assertSame('child-const', TestChildClass::CHILD_CONST);
        $refConstant = $this->refClass->getReflectionConstant('CHILD_CONST');
        $this->assertSame(TestChildClass::class, $refConstant->getDeclaringClass()->getName());

        // The parent class itself must keep its constant untouched
        $this->assertSame('parent-const', TestParentClass::PARENT_CONST);

        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessage('Constant PARENT_CONST does not exist');
        $this->refClass->getReflectionConstant('PARENT_CONST');
    }

    #[Group('internal')]
    public function testRemoveParentClassDetachesProperties(): void
    {
        // Four slots before the detachment: parentProperty + private parentSecret from the
        // parent, childProperty and the dead slot left by the parentProperty override
        $this->assertCount(4, $this->refClass->getDefaultProperties());

        $this->refClass->removeParentClass();

        // Parent-declared properties (including the private shadow slot) are gone,
        // own properties - including the override that adopted a parent slot - survive
        $this->assertFalse(property_exists(TestChildClass::class, 'parentSecret'));
        $this->assertTrue(property_exists(TestChildClass::class, 'childProperty'));
        $this->assertTrue(property_exists(TestChildClass::class, 'parentProperty'));

        // The compacted default properties table contains exactly the two own slots
        $defaultProperties = $this->refClass->getDefaultProperties();
        $this->assertCount(2, $defaultProperties);

        $nativeDefaults = (new \ReflectionClass(TestChildClass::class))->getDefaultProperties();
        $this->assertArrayNotHasKey('parentSecret', $nativeDefaults);
        $this->assertSame(20, $nativeDefaults['childProperty']);
        $this->assertSame(30, $nativeDefaults['parentProperty']);
    }

    #[Group('internal')]
    public function testRemoveParentClassDetachesStaticMembers(): void
    {
        // Touch the statics first so the engine materializes the live static members table:
        // the historically segfault-prone path goes through that second table
        TestChildClass::$childStaticProperty[] = 'materialized';

        $this->refClass->removeParentClass();

        $staticMembers = (new \ReflectionClass(TestChildClass::class))->getStaticProperties();
        $this->assertArrayNotHasKey('parentStaticProperty', $staticMembers);
        // Own static keeps its live (materialized) value, stays readable and writable
        $this->assertSame(['child', 'materialized'], $staticMembers['childStaticProperty']);
        TestChildClass::$childStaticProperty[] = 'after-detach';
        $this->assertSame(['child', 'materialized', 'after-detach'], TestChildClass::$childStaticProperty);

        // The parent class keeps its own static member untouched
        $this->assertSame(['parent'], TestParentClass::$parentStaticProperty);
    }

    #[Group('internal')]
    public function testRemoveParentClassDetachesMethods(): void
    {
        $this->refClass->removeParentClass();

        // The runtime class name avoids static analysis folding of method_exists()
        $className = $this->refClass->getName();
        $this->assertFalse(method_exists($className, 'parentMethod'));
        $this->assertTrue(method_exists($className, 'childMethod'));
        $this->assertSame('child', (new TestChildClass())->childMethod());
    }

    #[Group('internal')]
    public function testDetachedClassInstancesAreFullyUsable(): void
    {
        $this->refClass->removeParentClass();

        // Instances created after the detachment must construct, read/write their own
        // properties, expose consistent property lists and destruct without crashing
        $instance = new TestChildClass();
        $this->assertSame(20, $instance->childProperty);
        $this->assertSame(30, $instance->parentProperty);

        $instance->childProperty = 21;
        $instance->parentProperty += 100;
        $this->assertSame(21, $instance->childProperty);
        $this->assertSame(130, $instance->parentProperty);

        $expectedState = ['parentProperty' => 130, 'childProperty' => 21];
        $this->assertSame($expectedState, get_object_vars($instance));
        $this->assertSame($expectedState, (array) $instance);

        unset($instance);
        gc_collect_cycles();
        // Surviving the destruction of a detached-layout instance is the point here: a new
        // instance must still come up with pristine defaults afterwards
        $this->assertSame(20, (new TestChildClass())->childProperty);
    }

    #[Group('internal')]
    public function testRemoveParentClassRequiresParent(): void
    {
        $this->refClass->removeParentClass();

        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessage('Could not remove non-existent parent class');
        $this->refClass->removeParentClass();
    }

    #[Group('internal')]
    public function testSetParentRestoresDetachedParent(): void
    {
        // Materialized statics exercise the fold-back path inside setParent()
        TestChildClass::$childStaticProperty[] = 'live-value';

        $this->refClass->removeParentClass();
        $this->refClass->setParent(TestParentClass::class);

        $this->assertSame(TestParentClass::class, get_parent_class(TestChildClass::class));
        $this->assertSame('parent-const', TestChildClass::PARENT_CONST);
        // All four property slots (incl. the private parent shadow and the dead override
        // slot) are back after re-linking
        $this->assertCount(4, $this->refClass->getDefaultProperties());
        $this->assertSame('parent', (new TestChildClass())->parentMethod());

        $staticMembers = (new \ReflectionClass(TestChildClass::class))->getStaticProperties();
        // Inherited static is attached again and the own live value survived the round trip
        $this->assertSame(['parent'], $staticMembers['parentStaticProperty']);
        $this->assertSame(['child', 'live-value'], $staticMembers['childStaticProperty']);

        $nativeDefaults = (new \ReflectionClass(TestChildClass::class))->getDefaultProperties();
        $this->assertSame(20, $nativeDefaults['childProperty']);
        $this->assertSame(30, $nativeDefaults['parentProperty'], 'Own override must win again');
    }
}
