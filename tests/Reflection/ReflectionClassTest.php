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
use ZEngine\ClassExtension\Hook\CastObjectHook;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\CreateObjectHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\Hook\GetPropertiesForHook;
use ZEngine\ClassExtension\Hook\HasPropertyHook;
use ZEngine\ClassExtension\Hook\InterfaceGetsImplementedHook;
use ZEngine\ClassExtension\Hook\ReadPropertyHook;
use ZEngine\ClassExtension\Hook\UnsetPropertyHook;
use ZEngine\ClassExtension\Hook\WritePropertyHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
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
        $this->refClass = new class(TestClass::class) extends ReflectionClass{};
    }

    /**
     * @group internal
     */
    public function testRemoveMethods()
    {
        $this->refClass->removeMethods('methodToRemove');
        $isMethodExists = method_exists(TestClass::class, 'methodToRemove');
        $this->assertFalse($isMethodExists, 'Method should be removed');
    }

    /**
     * @group internal
     */
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

    /**
     * @group internal
     */
    public function testAddTraits()
    {
        $this->refClass->addTraits(TestTrait::class);

        // Trait should be in the list of trait names for this class
        $this->assertContains(TestTrait::class, $this->refClass->getTraitNames());
        // TODO: Check that methods were also added to the TestClass class
    }

    /**
     * @depends testAddTraits
     * @group internal
     */
    public function testRemoveTraits()
    {
        $this->markTestSkipped('Sometimes it segfaults, skip it right now');
        $this->refClass->removeTraits(TestTrait::class);

        // Trait should not be in the list of trait names for this class
        $this->assertNotContains(TestTrait::class, $this->refClass->getTraitNames());
        // TODO: Check that methods were also removed to the TestClass class
    }

    /**
     * @group internal
     */
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
     * @group internal
     */
    public function testRemoveInterfaces(): void
    {
        $this->refClass->removeInterfaces(TestInterface::class);
        $this->assertNotInstanceOf(TestInterface::class, $this);

        // Also, interface should not be in the list of interface names for this class
        $this->assertNotContains(TestInterface::class, $this->refClass->getInterfaceNames());
    }

    /**
     * @group internal
     */
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
        $this->refClass->setCreateObjectHandler(function (CreateObjectHook $hook) use (&$log) {
            $log    .= 'Before initialization.' . PHP_EOL;
            $object = $hook->proceed();
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

    public function testInstallInterfaceGetsImplementedHandler(): void
    {
        $log = '';
        $refInterface = new ReflectionClass(TestInterface::class);
        $refInterface->setInterfaceGetsImplementedHandler(function (InterfaceGetsImplementedHook $hook) use (&$log) {
            $log = 'Class ' . $hook->getClass()->getName() . ' implements interface';

            return Core::SUCCESS;
        });

        // Check that log line is empty now
        $this->assertSame('', $log);

        // Now we expect that at this point of time our callback will be called
        $anonymousInterfaceImplementation = new class implements TestInterface {};

        // Of course, we should get an instance of our TestInterface
        $this->assertInstanceOf(TestInterface::class, $anonymousInterfaceImplementation);

        // ... and log entry will contain a record about anonymous class that implements interface
        $this->assertStringContainsString('@anonymous', $log);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallCastObjectHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setCastObjectHandler(function (CastObjectHook $hook) {
            $castType = $hook->getCastType();
            switch ($castType) {
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
            throw new \UnexpectedValueException("Unknown type " . ReflectionValue::name($castType));
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
    public function testInstallReadPropertyHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setReadPropertyHandler(function (ReadPropertyHook $hook) {
            $value = $hook->proceed();
            return $value * 2;
        });
        $instance = new TestClass();
        $value    = $instance->property;
        $this->assertNotSame(42, $value);
        $this->assertSame(42 * 2, $value);

        // This check address https://github.com/lisachenko/z-engine/issues/32
        $secret = $instance->tellSecret();
        $this->assertSame(100500 * 2, $secret);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallWritePropertyHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setWritePropertyHandler(function (WritePropertyHook $hook) {
            // We can change value, for example by multiply it
            return $hook->getValue() * 2;
        });
        $instance = new TestClass();
        $instance->property = 10;
        $this->assertNotSame(42, $instance->property);
        $this->assertSame(20, $instance->property);

        // This check address https://github.com/lisachenko/z-engine/issues/32
        $instance->setSecret(200);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallUnsetPropertyHandler(): void
    {
        $logEntry = '';
        $handler  = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setUnsetPropertyHandler(function (UnsetPropertyHook $hook) use (&$logEntry) {
            // do nothing, so property will exist
            $logEntry = $hook->getMemberName();
        });
        $instance = new TestClass();
        unset($instance->property);
        // Property should remain
        $this->assertObjectHasAttribute('property', $instance);
        // Hook should be called and we will receive the property name
        $this->assertSame('property', $logEntry);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallHasPropertyHandler(): void
    {
        $logEntry = '';
        $handler  = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setHasPropertyHandler(function (HasPropertyHook $hook) use (&$logEntry) {
            $logEntry = $hook->getMemberName();
            // Let's inverse presence of field :)
            return (int)(!$hook->proceed());
        });

        $instance = new TestClass();
        $this->assertFalse(isset($instance->property));
        $this->assertSame('property', $logEntry);
        $this->assertTrue(isset($instance->unknown));
        $this->assertSame('unknown', $logEntry);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallGetPropertiesForHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setGetPropertiesForHandler(function (GetPropertiesForHook $hook) {
            $this->assertIsObject($hook->getObject());
            return ['a' => 1, 'b' => true, 'c' => 42.0];
        });
        $instance = new TestClass();
        $instance->property = 10;
        $castValue = (array) $instance;

        // We expect that our handler is called, thus no existing public fields will be returned
        $this->assertArrayNotHasKey('property', $castValue);

        // Instead we can control how to cast object to array
        $this->assertSame(['a' => 1, 'b' => true, 'c' => 42.0], $castValue);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInstallCompareValuesHandler(): void
    {
        $handler = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
        $this->refClass->setCreateObjectHandler($handler);
        $this->refClass->setCompareValuesHandler(function (CompareValuesHook $hook) {
            $left  = $hook->getFirst();
            $right = $hook->getSecond();
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
        $this->refClass->setDoOperationHandler(function (DoOperationHook $hook) {
            $opCode = $hook->getOpcode();
            $left   = $hook->getFirst();
            $right  = $hook->getSecond();

            if (is_object($left)) {
                $left = spl_object_id($left);
            }
            if (is_object($right)) {
                $right = spl_object_id($right);
            }
            switch ($opCode) {
                case OpCode::ADD:
                    return $left + $right;
                case OpCode::SUB:
                    return $left - $right;
                case OpCode::MUL:
                    return $left * $right;
                case OpCode::DIV:
                    return $left / $right;
            }
            throw new \UnexpectedValueException("Opcode " . OpCode::name($opCode) . " wasn't held.");
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
