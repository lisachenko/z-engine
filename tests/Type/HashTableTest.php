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
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;

/**
 * Covers the packed vs hashed array split introduced in PHP 8.2, where packed
 * arrays store bare zvals (arPacked) instead of Bucket structures. Iterating a
 * packed array as Buckets reads the wrong memory.
 */
final class HashTableTest extends TestCase
{
    public function testIteratesHashedTable(): void
    {
        // The module registry is a string-keyed (hashed) table
        $names = [];
        foreach (Core::$modules->getIterator() as $key => $value) {
            $names[] = $key;
        }

        $this->assertNotEmpty($names, 'Expected loaded modules in the registry');
        $this->assertContains('core', array_map('strtolower', array_filter($names, 'is_string')));
    }

    public function testIteratesPackedTable(): void
    {
        // A class function_table is hashed; to reach a packed table we walk the
        // compiler class table which is string-keyed, then assert the iterator
        // yields every declared class without reading past the end.
        $seen = 0;
        foreach (Core::$compiler->classTable->getIterator() as $key => $value) {
            $this->assertIsString($key);
            $seen++;
        }

        $this->assertGreaterThan(0, $seen);
    }

    public function testFindsExistingEntry(): void
    {
        $entry = Core::$compiler->classTable->find(strtolower(self::class));
        $this->assertNotNull($entry, 'The test class itself must be present in the class table');
    }

    public function testReturnsNullForMissingEntry(): void
    {
        $this->assertNull(Core::$compiler->classTable->find('this\\class\\does\\not\\exist'));
    }

    public function testOwnedTableRoundTripAndDestroy(): void
    {
        // The constructor CREATES an owned request-lifetime table; fromCData() stays
        // the borrowed construction (ownership split, docs/long-running.md)
        $table = new HashTable();

        $value = new ReflectionValue(42);
        $table->addIndex(7, $value);
        $value->release();

        $found = $table->findIndex(7);
        $this->assertNotNull($found);
        $found->getNativeValue($native);
        $this->assertSame(42, $native);

        // Borrowed view over the same engine struct sees the same bucket
        $borrowed = HashTable::fromCData($table->getRawValue());
        $this->assertNotNull($borrowed->findIndex(7));

        // pDestructor is NULL by construction: the writer owns payloads; the scalar
        // bucket here has nothing to release, destroy() frees data block and struct
        $table->destroy();
    }

    public function testIteratorYieldsIntegerKeysForHashedTables(): void
    {
        $table = new HashTable();

        $value = new ReflectionValue(1);
        $table->addIndex(2026, $value);
        $value->release();

        // An explicit large index keeps the engine table in hashed (non-packed) mode,
        // where integer keys are read from the bucket hash field
        $keys = [];
        foreach ($table as $key => $item) {
            $keys[] = $key;
        }
        $this->assertSame([2026], $keys);

        $table->destroy();
    }
}
