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


use Closure;
use PHPUnit\Framework\TestCase;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Stub\NativeNumber;
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestInterface;
use ZEngine\Stub\TestTrait;
use ZEngine\System\OpCode;

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

    public function testGetClassConstantsReturnsExtendedClass()
    {
        $refConstant = $this->refClass->getReflectionConstant('SOME_CONST');
        $this->assertInstanceOf(ReflectionClassConstant::class, $refConstant);
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
        $this->markTestSkipped('Sometimes it segfaults, skip it right now');
        $this->refClass->removeTraits(TestTrait::class);

        // Trait should not be in the list of trait names for this class
        $this->assertNotContains(TestTrait::class, $this->refClass->getTraitNames());
        // TODO: Check that methods were also removed to the TestClass class
    }

    public function testAddInterfaces(): void
    {
        $object = new TestClass();

        $this->refClass->addInterfaces(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $object);

        // As we adjusted list of interfaces, typehint should pass
        $checkTypehint = function (TestInterface $e): TestInterface {
            return $e;
        };

        $value  = $checkTypehint($object);
        $this->assertSame($object, $value);

        // Also, interface should be in the list of interface names for this class
        $this->assertContains(TestInterface::class, $this->refClass->getInterfaceNames());
    }

    /**
     * @depends testAddInterfaces
     */
    public function testRemoveInterfaces(): void
    {
        $this->refClass->removeInterfaces(TestInterface::class);
        $this->assertNotInstanceOf(TestInterface::class, $this);

        // Also, interface should not be in the list of interface names for this class
        $this->assertNotContains(TestInterface::class, $this->refClass->getInterfaceNames());
    }

    public function testAddRemoveInterfacesToInternalClass(): void
    {
        $refClosureClass = new ReflectionClass(\Closure::class);
        $refClosureClass->addInterfaces(TestInterface::class);

        $checkTypeHint = function (TestInterface $e): TestInterface {
            return $e;
        };
        // Closure should implements TestInterface right now, so it should pass itself
        $result = $checkTypeHint($checkTypeHint);
        $this->assertInstanceOf(TestInterface::class, $result);

        $refClosureClass->removeInterfaces(TestInterface::class);
        $this->assertNotInstanceOf(TestInterface::class, $result);
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

    /**
     * @runInSeparateProcess
     */
    public function testInstallUserCreateObjectHandler(): void
    {
        $log = '';
        $this->refClass->setCreateObjectHandler(function ($classType, $initializer) use (&$log) {
            $log    .= 'Before initialization.' . PHP_EOL;
            $object = $initializer($classType);
            $log    .= 'After initialization.';

            return $object;
        });
        $instance = new TestClass();
        // We should get instance of our original object, because we are calling default handler
        $this->assertInstanceOf(TestClass::class, $instance);

        $this->assertStringStartsWith('Before initialization.', $log);
        $this->assertStringEndsWith('After initialization.', $log);

        $this->markTestIncomplete('Initialization object handler brings segfaults thus run it separately');
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallCastObjectHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setCastObjectHandler(function (object $object, int $castTo) {
            switch ($castTo) {
                case ReflectionValue::IS_LONG:
                case ReflectionValue::_IS_NUMBER:
                    return 1;
                case ReflectionValue::IS_DOUBLE:
                    return 2.0;
                case ReflectionValue::IS_STRING:
                    return 'test';
                case ReflectionValue::_IS_BOOL:
                    return false;
            }
            throw new \UnexpectedValueException("Unknown type " . ReflectionValue::name($castTo));
        });

        $testClass = new TestClass();
        $long      = (int)$testClass;
        $this->assertSame(1, $long);
        $double = (float)$testClass;
        $this->assertSame(2.0, $double);
        $string = (string)$testClass;
        $this->assertSame('test', $string);
        $bool = (bool)$testClass;
        $this->assertSame(false, $bool);
        $this->markTestIncomplete('Initialization object handler brings segfaults thus run it separately');
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallCompareValuesHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setCompareValuesHandler(function ($left, $right) {
            if (is_object($left)) {
                $left = spl_object_id($left);
            }
            if (is_object($right)) {
                $right = spl_object_id($right);
            }

            return $left <=> $right;
        });

        $first    = new TestClass();
        $second   = new TestClass();
        $firstId  = spl_object_id($first);
        $secondId = spl_object_id($second);

        // As we compare values by object_id, then we should expect same values as simple int comparison
        $this->assertSame($firstId < $secondId, $first < $second);
        $this->assertSame($firstId == $secondId, $first == $second);
        $this->assertSame($firstId >= $secondId, $first >= $second);

        // We can also compare objects with values directly, look at $secondId arg
        $this->assertSame($firstId < $secondId, $first < $secondId);
        $this->assertSame($firstId > $secondId, $firstId > $second);

        $this->markTestIncomplete('Initialization object handler brings segfaults thus run it separately');
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallDoOperationHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setDoOperationHandler(function (int $opcode, $left, $right) {
            if (is_object($left)) {
                $left = spl_object_id($left);
            }
            if (is_object($right)) {
                $right = spl_object_id($right);
            }
            switch ($opcode) {
                case OpCode::ADD:
                    return $left + $right;
                case OpCode::SUB:
                    return $left - $right;
                case OpCode::MUL:
                    return $left * $right;
                case OpCode::DIV:
                    return $left / $right;
            }
            throw new \UnexpectedValueException("Opcode " . OpCode::name($opcode) . " wasn't held.");
        });

        $first    = new TestClass();
        $second   = new TestClass();
        $firstId  = spl_object_id($first);
        $secondId = spl_object_id($second);

        // As we compare values by object_id, then we should expect same values as simple int comparison
        $this->assertSame($firstId + $secondId, $first + $second);
        $this->assertSame($firstId - $secondId, $first - $second);
        $this->assertSame($firstId * $secondId, $first * $second);
        $this->assertSame($firstId / $secondId, $first / $second);

        $this->markTestIncomplete('Initialization object handler brings segfaults thus run it separately');
    }


    public function testInstallExtensionHandlers(): void
    {
        $refClass = new ReflectionClass(NativeNumber::class);
        $refClass->installExtensionHandlers();

        $a = new NativeNumber(46);
        $b = new NativeNumber(2);

        $c = $a + $b;
        $this->assertSame(48, (int) $c);
        $d = $a / $b;
        $this->assertSame(23.0, (float) $d);
        $e = $a > 10 && $a < 50;
        $this->assertTrue($e, 'Number should be equal to 46');
        $f = ($a * 2) < 100;
        $this->assertTrue($f, '46*2=92 is less than 100');
    }
}
