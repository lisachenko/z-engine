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
use ZEngine\Stub\TestIntBackedEnum;
use ZEngine\Stub\TestPureEnum;
use ZEngine\Stub\TestStringBackedEnum;
use ZEngine\Type\HashTable;

/**
 * Pure-read introspection of enum class entries: nothing here mutates engine state,
 * so the whole class belongs to the default suite (no "internal" group required)
 */
class ReflectionClassEnumTest extends TestCase
{
    public function testStringBackedEnumExposesBackedEnumTable(): void
    {
        $refClass = new ReflectionClass(TestStringBackedEnum::class);

        // The engine builds the backed-enum table lazily on the first from()/tryFrom()
        // call: before that the raw accessor sees a NULL pointer and reports null
        $tableBeforeUse = $refClass->getBackedEnumTable();
        $this->assertNull($tableBeforeUse, 'Table should not be materialized yet');

        $this->assertNotNull(TestStringBackedEnum::tryFrom('left'));
        $table = $refClass->getBackedEnumTable();
        if ($table === null) {
            self::fail('Backed string enum should expose the backed-enum table');
        }

        // The engine maps each backing value to an IS_STRING zval with the case name
        $caseEntry = $table->find('left');
        if ($caseEntry === null) {
            self::fail('Backing value "left" should be present in the table');
        }
        $this->assertSame(ReflectionValue::IS_STRING, $caseEntry->getType());
        $caseEntry->getNativeValue($caseName);
        $this->assertSame('Left', $caseName);

        $this->assertNull($table->find('unknown'), 'Unknown backing value should not be found');

        // Full iteration: backing value => case name, one entry per case
        $this->assertSame(['left' => 'Left', 'right' => 'Right'], $this->collectTable($table));
    }

    public function testIntBackedEnumExposesBackedEnumTable(): void
    {
        $refClass = new ReflectionClass(TestIntBackedEnum::class);

        // Materialize the lazily-built table through the ordinary engine path
        TestIntBackedEnum::from(1);
        $table = $refClass->getBackedEnumTable();
        if ($table === null) {
            self::fail('Backed int enum should expose the backed-enum table');
        }

        // Int-backed tables are keyed by the integer backing values, so the
        // string-keyed find() does not apply: assert the whole table via iteration
        $this->assertSame([1 => 'One', 2 => 'Two'], $this->collectTable($table));
    }

    public function testPureEnumHasNoBackedEnumTable(): void
    {
        $refClass = new ReflectionClass(TestPureEnum::class);

        $this->assertTrue($refClass->isEnum(), 'Pure enum should still be an enum');
        // Touch the cases so the null cannot be confused with a not-yet-materialized table
        $this->assertCount(2, TestPureEnum::cases());
        $this->assertNull($refClass->getBackedEnumTable(), 'Pure enum has no backed-enum table');
    }

    public function testPlainClassHasNoBackedEnumTable(): void
    {
        $refClass = new ReflectionClass(TestClass::class);

        $this->assertFalse($refClass->isEnum(), 'Plain class is not an enum');
        $this->assertNull($refClass->getBackedEnumTable(), 'Plain class has no backed-enum table');
    }

    public function testInternalBackedEnumExposesBackedEnumTable(): void
    {
        // \PropertyHookType is a string-backed enum registered by the engine itself;
        // internal enums build their backed-enum table lazily as well
        $refClass = new ReflectionClass(\PropertyHookType::class);
        $this->assertNotNull(\PropertyHookType::tryFrom('get'));
        $table = $refClass->getBackedEnumTable();
        if ($table === null) {
            self::fail('Internal backed enum should expose the backed-enum table');
        }

        $caseEntry = $table->find('get');
        if ($caseEntry === null) {
            self::fail('Backing value "get" should be present in the table');
        }
        $caseEntry->getNativeValue($caseName);
        $this->assertSame('Get', $caseName);
    }

    /**
     * Collects a borrowed backed-enum table into a plain [backing value => case name] array
     *
     * @param HashTable&iterable<int|string|null, ReflectionValue> $table
     *
     * @return array<int|string, string>
     */
    private function collectTable(HashTable $table): array
    {
        $cases = [];
        foreach ($table as $backingValue => $entry) {
            if (!is_int($backingValue) && !is_string($backingValue)) {
                self::fail('Backed-enum table keys should be backing values');
            }
            $entry->getNativeValue($caseName);
            if (!is_string($caseName)) {
                self::fail('Backed-enum table values should hold the case name');
            }
            $cases[$backingValue] = $caseName;
        }

        return $cases;
    }
}
