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
use ZEngine\Stub\TestHookedClass;

class ReflectionPropertyHooksTest extends TestCase
{
    public function testHasHooks(): void
    {
        $hooked = new ReflectionProperty(TestHookedClass::class, 'hooked');
        $this->assertTrue($hooked->hasHooks());

        $getOnly = new ReflectionProperty(TestHookedClass::class, 'virtual');
        $this->assertTrue($getOnly->hasHooks());

        $plain = new ReflectionProperty(TestHookedClass::class, 'plain');
        $this->assertFalse($plain->hasHooks());
    }

    public function testGetHookReturnsEngineBackedReflection(): void
    {
        $property = new ReflectionProperty(TestHookedClass::class, 'hooked');

        $getHook = $property->getHook(Core::ZEND_PROPERTY_HOOK_GET);
        $setHook = $property->getHook(Core::ZEND_PROPERTY_HOOK_SET);
        $this->assertNotNull($getHook);
        $this->assertNotNull($setHook);

        // The wrapped functions are the same hook bodies the native reflection exposes
        $nativeHooks = (new \ReflectionProperty(TestHookedClass::class, 'hooked'))->getHooks();
        $this->assertSame($nativeHooks['get']->getName(), $getHook->getName());
        $this->assertSame($nativeHooks['set']->getName(), $setHook->getName());
        $this->assertSame(TestHookedClass::class, $getHook->getDeclaringClass()->getName());
    }

    public function testGetHookReflectionIsCallable(): void
    {
        $property = new ReflectionProperty(TestHookedClass::class, 'hooked');
        $instance = new TestHookedClass();

        $getHook = $property->getHook(Core::ZEND_PROPERTY_HOOK_GET);
        $this->assertNotNull($getHook);
        // The raw backing value is 5, the get hook reports backing value + 1
        $this->assertSame(6, $getHook->invoke($instance));

        $setHook = $property->getHook(Core::ZEND_PROPERTY_HOOK_SET);
        $this->assertNotNull($setHook);
        // The set hook stores value * 2, visible through the get hook afterwards
        $setHook->invoke($instance, 10);
        $this->assertSame(21, $instance->hooked);
    }

    public function testGetHookReturnsNullForMissingHook(): void
    {
        $getOnly = new ReflectionProperty(TestHookedClass::class, 'virtual');
        $this->assertNotNull($getOnly->getHook(Core::ZEND_PROPERTY_HOOK_GET));
        $this->assertNull($getOnly->getHook(Core::ZEND_PROPERTY_HOOK_SET));

        $plain = new ReflectionProperty(TestHookedClass::class, 'plain');
        $this->assertNull($plain->getHook(Core::ZEND_PROPERTY_HOOK_GET));
        $this->assertNull($plain->getHook(Core::ZEND_PROPERTY_HOOK_SET));
    }

    public function testGetHookAcceptsNativeHookType(): void
    {
        $property = new ReflectionProperty(TestHookedClass::class, 'hooked');

        $getHook = $property->getHook(\PropertyHookType::Get);
        $setHook = $property->getHook(\PropertyHookType::Set);
        $this->assertNotNull($getHook);
        $this->assertNotNull($setHook);
        $this->assertSame('$hooked::get', $getHook->getName());
        $this->assertSame('$hooked::set', $setHook->getName());
    }

    public function testGetHookRejectsUnknownKind(): void
    {
        $property = new ReflectionProperty(TestHookedClass::class, 'hooked');

        $this->expectException(\InvalidArgumentException::class);
        $property->getHook(42);
    }

    public function testGetHookDoesNotPolluteTheMethodTable(): void
    {
        $property = new ReflectionProperty(TestHookedClass::class, 'hooked');
        $property->getHook(Core::ZEND_PROPERTY_HOOK_GET);

        // The transient publication used to initialize the native reflection state
        // must be fully reverted: hooks stay invisible as regular methods
        $this->assertFalse((new \ReflectionClass(TestHookedClass::class))->hasMethod('$hooked::get'));
        $refClass  = new ReflectionClass(TestHookedClass::class);
        $hookNames = [];
        foreach ($refClass->getMethods() as $method) {
            $hookNames[] = $method->getName();
        }
        $this->assertSame(['annotatedMethod'], $hookNames);
    }
}
