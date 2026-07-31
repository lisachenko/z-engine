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
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestHookedClass;
use ZEngine\Stub\TestStubAttribute;
use ZEngine\Type\HashTable;

#[TestStubAttribute('functionValue', second: [1, 2])]
function annotatedTestFunction(): void {}

class ReflectionAttributeEntryTest extends TestCase
{
    public function testClassAttributesMatchNativeReflection(): void
    {
        $refClass = new ReflectionClass(TestHookedClass::class);
        $entries  = self::attributeEntries($refClass->getAttributesTable());

        $native = (new \ReflectionClass(TestHookedClass::class))->getAttributes();
        $this->assertSameAttributeData($native, $entries);
    }

    public function testClassWithoutAttributesHasNoTable(): void
    {
        $refClass = new ReflectionClass(TestClass::class);
        $this->assertNull($refClass->getAttributesTable());
    }

    public function testPropertyAttributesMatchNativeReflection(): void
    {
        $refProperty = new ReflectionProperty(TestHookedClass::class, 'hooked');
        $entries     = self::attributeEntries($refProperty->getAttributesTable());

        $native = (new \ReflectionProperty(TestHookedClass::class, 'hooked'))->getAttributes();
        $this->assertSameAttributeData($native, $entries);

        $plain = new ReflectionProperty(TestHookedClass::class, 'plain');
        $this->assertNull($plain->getAttributesTable());
    }

    public function testMethodAttributesMatchNativeReflection(): void
    {
        $refMethod = new ReflectionMethod(TestHookedClass::class, 'annotatedMethod');
        $entries   = self::attributeEntries($refMethod->getAttributesTable());

        $native = (new \ReflectionMethod(TestHookedClass::class, 'annotatedMethod'))->getAttributes();
        $this->assertSameAttributeData($native, $entries);
    }

    public function testConstantAttributesMatchNativeReflection(): void
    {
        $refConstant = new ReflectionClassConstant(TestHookedClass::class, 'SOME_CONST');
        $entries     = self::attributeEntries($refConstant->getAttributesTable());

        $native = (new \ReflectionClassConstant(TestHookedClass::class, 'SOME_CONST'))->getAttributes();
        $this->assertSameAttributeData($native, $entries);
    }

    public function testFunctionAttributesMatchNativeReflection(): void
    {
        $functionName = __NAMESPACE__ . '\annotatedTestFunction';
        $refFunction  = new ReflectionFunction($functionName);
        $entries      = self::attributeEntries($refFunction->getAttributesTable());

        $native = (new \ReflectionFunction($functionName))->getAttributes();
        $this->assertSameAttributeData($native, $entries);
    }

    public function testExposesEngineOnlyAttributeDetails(): void
    {
        $refClass = new ReflectionClass(TestHookedClass::class);
        $entries  = self::attributeEntries($refClass->getAttributesTable());
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame(TestStubAttribute::class, $entry->getName());
        $this->assertSame(strtolower(TestStubAttribute::class), $entry->getLoweredName());
        $this->assertSame(2, $entry->getArgumentCount());
        // Class attributes always use offset 0 (only parameter attributes are offset-based)
        $this->assertSame(0, $entry->getOffset());
        $this->assertGreaterThan(0, $entry->getLineNumber());
        // Compiled userland attributes keep declaration-site bits in the flags word
        $this->assertIsInt($entry->getTarget());
    }

    /**
     * Extracts attribute entries from an engine attributes table
     *
     * @param HashTable|ReflectionValue[]|null $attributesTable
     *
     * @return list<ReflectionAttributeEntry>
     */
    private static function attributeEntries(?HashTable $attributesTable): array
    {
        if ($attributesTable === null) {
            return [];
        }
        $entries = [];
        foreach ($attributesTable as $valueEntry) {
            $entries[] = ReflectionAttributeEntry::fromValueEntry($valueEntry);
        }

        return $entries;
    }

    /**
     * Asserts that the engine-level attribute data matches the native reflection view
     *
     * @param list<\ReflectionAttribute<object>> $nativeAttributes
     * @param list<ReflectionAttributeEntry>     $entries
     */
    private function assertSameAttributeData(array $nativeAttributes, array $entries): void
    {
        $this->assertNotEmpty($nativeAttributes);
        $this->assertCount(count($nativeAttributes), $entries);
        foreach ($nativeAttributes as $index => $nativeAttribute) {
            $entry = $entries[$index];
            $this->assertSame($nativeAttribute->getName(), $entry->getName());

            $arguments = [];
            foreach ($entry->getArguments() as $key => $valueEntry) {
                $nativeValue = null;
                $valueEntry->getNativeValue($nativeValue);
                $arguments[$key] = $nativeValue;
            }
            $this->assertSame($nativeAttribute->getArguments(), $arguments);
        }
    }
}
