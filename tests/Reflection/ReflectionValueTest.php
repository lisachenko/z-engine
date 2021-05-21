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

use FFI\CData;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

class ReflectionValueTest extends TestCase
{
    /**
     * @dataProvider valueTypeProvider
     */
    public function testConstructorWorks($value, int $expectedType)
    {
        $refValue = new ReflectionValue($value);
        $type     = $refValue->getType() & 0xFF;

        $this->assertSame($expectedType, $type);
    }

    /**
     * @dataProvider valueProvider
     */
    public function testGetNativeValue($value): void
    {
        // This prevents optimization of opcodes and $value variable GC
        static $currentValue;
        $currentValue = $value;
        $argument     = Core::$executor->getExecutionState()->getArgument(0);
        $argument->getNativeValue($returnedValue);
        $this->assertSame($currentValue, $returnedValue);
    }

    public function valueProvider(): array
    {
        return [
            [1],
            [1.0],
            ['Test'],
            [new \stdClass()],
            [[1, 2, 3]]
        ];
    }

    /**
     * @dataProvider valueTypeProvider
     */
    public function testGetType($value, int $expectedType): void
    {
        $argument         = Core::$executor->getExecutionState()->getArgument(0);
        $argType          = ($argument->getType() & 0xFF); // Use only low byte to get type name
        $expectedTypeName = ReflectionValue::name($expectedType);
        $argTypeName      = ReflectionValue::name($argType);
        $this->assertSame(
            $expectedType,
            $argType,
            "Expect type to be ". $expectedTypeName . ', but ' . $argTypeName . ' given.'
        );
    }

    public function valueTypeProvider(): array
    {
        $valueByRef = new \stdClass();
        return [
            [1, ReflectionValue::IS_LONG],
            [1.0, ReflectionValue::IS_DOUBLE],
            ['Test', ReflectionValue::IS_STRING],
            [new \stdClass(), ReflectionValue::IS_OBJECT],
            [[1, 2, 3], ReflectionValue::IS_ARRAY],
            [null, ReflectionValue::IS_NULL],
            [false, ReflectionValue::IS_FALSE],
            [true, ReflectionValue::IS_TRUE],
            [fopen(__FILE__, 'r'), ReflectionValue::IS_RESOURCE]
        ];
    }

    public function testGetRawClass()
    {
        $classEntry = Core::$executor->classTable->find(strtolower(self::class));
        $rawClass   = $classEntry->getRawClass();
        $this->assertInstanceOf(CData::class, $rawClass);

        // Let's check the name from this structure
        $className = StringEntry::fromCData($rawClass->name);
        $this->assertSame(self::class, $className->getStringValue());
    }

    public function testGetRawFunction()
    {
        $functionEntry = Core::$executor->functionTable->find('var_dump');
        $rawFunction   = $functionEntry->getRawFunction();
        $this->assertInstanceOf(CData::class, $rawFunction);

        // Let's check the name from this structure
        $functionName = StringEntry::fromCData($rawFunction->function_name);
        $this->assertSame('var_dump', $functionName->getStringValue());
    }

    public function testGetRawValue()
    {
        $classEntry = Core::$executor->classTable->find(strtolower(self::class));
        $rawValue   = $classEntry->getRawValue();

        $valueEntry = ReflectionValue::fromValueEntry($rawValue);
        $this->assertEquals(ReflectionValue::IS_PTR, $valueEntry->getType());
    }

    public function testSetNativeValue()
    {
        $this->markTestSkipped('Can not construct ReflectionValue by hand now');
    }

    public function testGetRawObject()
    {
        $thisValue = Core::$executor->getExecutionState()->getThis();
        $rawObject = $thisValue->getRawObject();
        $this->assertInstanceOf(CData::class, $rawObject);

        $object = ObjectEntry::fromCData($rawObject);
        // Check that we have the same object by checking handle
        $this->assertSame(spl_object_id($this), $object->getHandle());
    }

    public function testGetRawString()
    {
        $value = self::class;
        get_defined_vars(); // This triggers Symbol Table rebuilt under the hood

        $valueEntry = Core::$executor->getExecutionState()->getSymbolTable()->find('value');

        // We know that $valueEntry is indirect pointer to string
        $this->assertSame(ReflectionValue::IS_INDIRECT, $valueEntry->getType());
        $rawString = $valueEntry->getIndirectValue()->getRawString();

        $stringEntry = StringEntry::fromCData($rawString);
        $this->assertSame(self::class, $stringEntry->getStringValue());
    }
}
