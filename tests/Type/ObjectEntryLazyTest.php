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

namespace ZEngine\Type;

use PHPUnit\Framework\TestCase;
use ReflectionClass as NativeReflectionClass;
use ZEngine\Stub\TestClass;

/**
 * Read-path tests for PHP 8.4 lazy-object introspection on ObjectEntry
 *
 * Fixtures are produced by the native lazy-object API (ReflectionClass::newLazyGhost()
 * and newLazyProxy()); the assertions cross-check the engine extra_flags read against
 * the native ReflectionClass::isUninitializedLazyObject() view where one exists.
 */
class ObjectEntryLazyTest extends TestCase
{
    public function testPlainObjectIsNotLazy(): void
    {
        $entry = new ObjectEntry(new TestClass());

        $this->assertFalse($entry->isLazy());
        $this->assertFalse($entry->isLazyProxy());
        // Mirrors zend_lazy_object_initialized(): an object that never was lazy reports true
        $this->assertTrue($entry->isLazyInitialized());
    }

    public function testUninitializedGhostReportsLazyAndUninitialized(): void
    {
        $reflector = new NativeReflectionClass(TestClass::class);
        $ghost     = $reflector->newLazyGhost(static function (TestClass $object): void {
            $object->property = 2026;
        });

        $entry = new ObjectEntry($ghost);

        $this->assertTrue($reflector->isUninitializedLazyObject($ghost));
        $this->assertTrue($entry->isLazy());
        $this->assertFalse($entry->isLazyProxy());
        $this->assertFalse($entry->isLazyInitialized());
    }

    public function testInitializedGhostIsNoLongerLazy(): void
    {
        $reflector = new NativeReflectionClass(TestClass::class);
        $ghost     = $reflector->newLazyGhost(static function (TestClass $object): void {
            $object->property = 2026;
        });

        $entry = new ObjectEntry($ghost);
        // Property access triggers the ghost initialization in the engine
        $this->assertSame(2026, $ghost->property);

        $this->assertFalse($reflector->isUninitializedLazyObject($ghost));
        $this->assertFalse($entry->isLazy(), 'An initialized ghost drops all lazy flags');
        $this->assertFalse($entry->isLazyProxy());
        $this->assertTrue($entry->isLazyInitialized());
    }

    public function testUninitializedProxyReportsLazyProxy(): void
    {
        $reflector = new NativeReflectionClass(TestClass::class);
        $proxy     = $reflector->newLazyProxy(static fn (): TestClass => new TestClass());

        $entry = new ObjectEntry($proxy);

        $this->assertTrue($reflector->isUninitializedLazyObject($proxy));
        $this->assertTrue($entry->isLazy());
        $this->assertTrue($entry->isLazyProxy());
        $this->assertFalse($entry->isLazyInitialized());
    }

    public function testInitializedProxyStaysLazyProxyButInitialized(): void
    {
        $reflector = new NativeReflectionClass(TestClass::class);
        $proxy     = $reflector->newLazyProxy(static fn (): TestClass => new TestClass());

        $entry = new ObjectEntry($proxy);
        $reflector->initializeLazyObject($proxy);

        $this->assertFalse($reflector->isUninitializedLazyObject($proxy));
        // The proxy keeps IS_OBJ_LAZY_PROXY after initialization: reads/writes still forward
        $this->assertTrue($entry->isLazy());
        $this->assertTrue($entry->isLazyProxy());
        $this->assertTrue($entry->isLazyInitialized());
    }
}
