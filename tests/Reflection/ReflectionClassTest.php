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
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestTrait;

class ReflectionClassTest extends TestCase
{
    private ReflectionClass $refClass;

    public function setUp(): void
    {
        $this->refClass = new ReflectionClass(TestClass::class);
    }

    public function testRemoveMethods()
    {
        $this->refClass->removeMethods('methodToRemove');
        $isMethodExists = method_exists(TestClass::class, 'methodToRemove');
        $this->assertFalse($isMethodExists, 'Method should be removed');
    }

    public function testAddMethod()
    {
        $methodName = 'newMethod';
        $this->refClass->addMethod($methodName, function (string $argument): string {
            return $argument;
        });
        $isMethodExists = method_exists(TestClass::class, $methodName);
        $this->assertTrue($isMethodExists);
        $instance = new TestClass();
        $result = $instance->$methodName('Test');
        $this->assertSame('Test', $result);
    }

    public function testSetAbstract()
    {
        $this->refClass->setAbstract(true);
        $this->assertTrue($this->refClass->isAbstract());
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate abstract class ' . TestClass::class);
        // If we try to instantiate an abstract class then it will be an Error
        new TestClass();
    }

    /**
     * We use a result from previous setAbstract() call to revert it
     *
     * @depends testSetAbstract
     */
    public function testSetNonAbstract()
    {
        $this->refClass->setAbstract(false);
        $this->assertFalse($this->refClass->isAbstract());
        $instance = new TestClass();
        $this->assertInstanceOf(TestClass::class, $instance);
    }

    public function testSetFinal()
    {
        $this->refClass->setFinal(true);
        $this->assertTrue($this->refClass->isFinal());
        // Unfortunately, next line wil produce a fatal error, thus can not be tested
        // new class extends TestClass {};
    }

    /**
     * We use a result from previous setFinal() call to revert it
     *
     * @depends testSetFinal
     */
    public function testSetNonFinal()
    {
        $this->refClass->setFinal(false);
        $this->assertFalse($this->refClass->isFinal());

        $instance = new class extends TestClass {};
        $this->assertInstanceOf(TestClass::class, $instance);
    }

    public function testAddTraits()
    {
        $this->refClass->addTraits(TestTrait::class);

        // Trait should be in the list of trait names for this class
        $this->assertContains(TestTrait::class, $this->refClass->getTraitNames());
        // TODO: Check that methods were also added to the TestClass class
    }

    /**
     * @depends testAddTraits
     */
    public function testRemoveTraits()
    {
        $this->refClass->removeTraits(TestTrait::class);

        // Trait should not be in the list of trait names for this class
        $this->assertNotContains(TestTrait::class, $this->refClass->getTraitNames());
        // TODO: Check that methods were also removed to the TestClass class
    }

    public function testAddInterfaces(): void
    {
        $object = new TestClass();

        $this->refClass->addInterfaces(\Throwable::class);
        $this->assertInstanceOf(\Throwable::class, $object);

        // As we adjusted list of interfaces, typehint should pass
        $checkTypehint = function (\Throwable $e): \Throwable {
            return $e;
        };

        $value  = $checkTypehint($object);
        $this->assertSame($object, $value);

        // Also, interface should be in the list of interface names for this class
        $this->assertContains(\Throwable::class, $this->refClass->getInterfaceNames());
    }

    /**
     * @depends testAddInterfaces
     */
    public function testRemoveInterfaces(): void
    {
        $this->refClass->removeInterfaces(\Throwable::class);
        $this->assertNotInstanceOf(\Throwable::class, $this);

        // Also, interface should not be in the list of interface names for this class
        $this->assertNotContains(\Throwable::class, $this->refClass->getInterfaceNames());
    }

    public function testSetStartLine(): void
    {
        $this->assertSame(15, $this->refClass->getStartLine());
        $this->refClass->setStartLine(1);
        $this->assertSame(1, $this->refClass->getStartLine(), 'Start line number should be changed');
    }

    public function testSetEndLine(): void
    {
        $totalLines = count(file($this->refClass->getFileName()));
        $this->assertSame($totalLines, $this->refClass->getEndLine());
        $this->refClass->setEndLine(1);
        $this->assertSame(1, $this->refClass->getEndLine(), 'End line number should be changed');
    }

    public function testSetFileName()
    {
        // Take the file name to restore later
        $originalFileName = $this->refClass->getFileName();
        $this->refClass->setFileName('/etc/passwd');
        $this->assertEquals('/etc/passwd', $this->refClass->getFileName());
        $this->refClass->setFileName($originalFileName);
        $this->assertEquals($originalFileName, $this->refClass->getFileName());
    }
}
