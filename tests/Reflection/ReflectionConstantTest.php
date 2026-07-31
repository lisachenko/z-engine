<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;

class ReflectionConstantTest extends TestCase
{
    public function testReflectsPersistentEngineConstant(): void
    {
        $reflection = new ReflectionConstant('PHP_VERSION');

        $this->assertSame('PHP_VERSION', $reflection->getName());
        $this->assertTrue($reflection->isPersistent());
        $this->assertNotSame(Core::engineConstant('PHP_USER_CONSTANT'), $reflection->getModuleNumber());

        $reflection->getReflectionValue()->getNativeValue($value);
        $this->assertSame(PHP_VERSION, $value);
    }

    public function testReflectsUserDefinedConstant(): void
    {
        define('ZENGINE_REFLECTION_TEST_CONSTANT', 42);

        $reflection = new ReflectionConstant('ZENGINE_REFLECTION_TEST_CONSTANT');

        $this->assertSame('ZENGINE_REFLECTION_TEST_CONSTANT', $reflection->getName());
        $this->assertFalse($reflection->isPersistent());
        $this->assertSame(0, $reflection->getFlags() & Core::engineConstant('CONST_PERSISTENT'));
        $this->assertSame(Core::engineConstant('PHP_USER_CONSTANT'), $reflection->getModuleNumber());

        $reflection->getReflectionValue()->getNativeValue($value);
        $this->assertSame(42, $value);
    }

    public function testThrowsForUnknownConstant(): void
    {
        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessageMatches('/should be registered in the engine/');

        new ReflectionConstant('ZENGINE_DEFINITELY_MISSING_CONSTANT');
    }

    public function testFromCDataWrapsConstantStructure(): void
    {
        $constantEntry = Core::$executor->constantTable->find('PHP_INT_MAX');
        $this->assertNotNull($constantEntry);
        $constantPointer = Core::cast('zend_constant *', $constantEntry->getRawPointer());

        $reflection = ReflectionConstant::fromCData($constantPointer);

        $this->assertSame('PHP_INT_MAX', $reflection->getName());
        $reflection->getReflectionValue()->getNativeValue($value);
        $this->assertSame(PHP_INT_MAX, $value);
    }

    public function testRemoveMakesUserConstantUndefined(): void
    {
        define('ZENGINE_REMOVABLE_CONSTANT', 'to-be-removed');
        $this->assertTrue(defined('ZENGINE_REMOVABLE_CONSTANT'));

        $reflection = new ReflectionConstant('ZENGINE_REMOVABLE_CONSTANT');
        // The wrapper must not be accessed anymore after this call (bucket destroyed)
        $this->assertTrue($reflection->remove());

        $this->assertFalse(defined('ZENGINE_REMOVABLE_CONSTANT'));
        $this->assertNull(Core::$executor->constantTable->find('ZENGINE_REMOVABLE_CONSTANT'));
    }

    public function testRemoveRefusesPersistentConstant(): void
    {
        $reflection = new ReflectionConstant('PHP_VERSION');

        $this->assertFalse($reflection->remove());
        // The constant is still registered and readable after the refused removal
        $this->assertNotNull(Core::$executor->constantTable->find('PHP_VERSION'));
        $this->assertSame('PHP_VERSION', $reflection->getName());
    }

    public function testRemoveRefusesInternalModuleConstant(): void
    {
        // E_ERROR is registered by the Core module, not by userland define()
        $reflection = new ReflectionConstant('E_ERROR');

        $this->assertFalse($reflection->remove());
        $this->assertNotNull(Core::$executor->constantTable->find('E_ERROR'));
    }

    public function testDebugInfoIsSegfaultFree(): void
    {
        $reflection = new ReflectionConstant('PHP_VERSION');

        $this->assertSame(
            ['name' => 'PHP_VERSION', 'persistent' => true],
            $reflection->__debugInfo(),
        );
    }
}
