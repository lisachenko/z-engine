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
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Support\ResidentMemory;

class PersistentHashTableTest extends TestCase
{
    public function testCreateMintsPersistentNonCollectableTable(): void
    {
        $table = new PersistentHashTable();

        $this->assertTrue($table->isPersistent());
        $this->assertFalse($table->isImmutable());
        $this->assertSame(1, $table->getReferenceCount());
        $this->assertCount(0, iterator_to_array($table->getIterator(), false));
    }

    public function testStringKeyUpsertAndFind(): void
    {
        $table = new PersistentHashTable();

        $value = new ReflectionValue(42);
        $table->add('answer', $value);
        $value->release();

        $found = $table->find('answer');
        $this->assertNotNull($found);
        $found->getNativeValue($native);
        $this->assertSame(42, $native);

        // add() is an upsert for persistent registries: same key replaces in place
        $replacement = new ReflectionValue(43);
        $table->add('answer', $replacement);
        $replacement->release();

        $table->find('answer')->getNativeValue($updated);
        $this->assertSame(43, $updated);
        $this->assertNull($table->find('missing'));
    }

    public function testIndexKeyUpsertAndFind(): void
    {
        $table = new PersistentHashTable();

        $value = new ReflectionValue(100);
        $table->addIndex(7, $value);
        $value->release();

        $found = $table->findIndex(7);
        $this->assertNotNull($found);
        $found->getNativeValue($native);
        $this->assertSame(100, $native);

        $replacement = new ReflectionValue(200);
        $table->addIndex(7, $replacement);
        $replacement->release();

        $table->findIndex(7)->getNativeValue($updated);
        $this->assertSame(200, $updated);
        $this->assertNull($table->findIndex(8));
    }

    public function testSealedTableMaterializesAndCopiesOnWrite(): void
    {
        $table = new PersistentHashTable();

        $value = new ReflectionValue(42);
        $table->add('answer', $value);
        $value->release();

        $table->markImmutable();
        $this->assertTrue($table->isImmutable());
        $this->assertSame(2, $table->getReferenceCount());

        // An immutable payload materializes into a non-refcounted zval: userland copies
        // share the pointer and copy-on-write into request memory on mutation
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $table->getRawValue()[0]);
        $entry->getNativeValue($native);
        $entry->release();

        $this->assertSame(['answer' => 42], $native);

        $copy           = $native;
        $copy['answer'] = 1000;

        // The persistent block must stay untouched by the userland write
        $table->find('answer')->getNativeValue($stillPersistent);
        $this->assertSame(42, $stillPersistent);
        $this->assertSame(1000, $copy['answer']);
        $this->assertSame(2, $table->getReferenceCount());
    }

    public function testDeleteIndexRemovesTheBucket(): void
    {
        $table = new PersistentHashTable();

        foreach ([1, 2, 3] as $index) {
            $value = new ReflectionValue($index * 10);
            $table->addIndex($index, $value);
            $value->release();
        }

        $table->deleteIndex(2);

        $this->assertNull($table->findIndex(2));
        $table->findIndex(1)->getNativeValue($first);
        $table->findIndex(3)->getNativeValue($third);
        $this->assertSame(10, $first);
        $this->assertSame(30, $third);

        // Deleting a key that is not there is a failure, not a silent no-op
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/index 2/');
        $table->deleteIndex(2);
    }

    public function testDestroyReleasesAnUninitializedTable(): void
    {
        // An empty table still points at the shared uninitialized-bucket sentinel, which
        // zend_hash_destroy() must NOT free - only the struct block goes away here. The
        // sentinel is shared process-wide, so the next table minted after the drop proves
        // it survived (freeing it would have taken every other persistent table with it)
        new PersistentHashTable()->destroy();

        $survivor = new PersistentHashTable();
        $value    = new ReflectionValue('still alive');
        $survivor->add('probe', $value);
        $value->release();

        $survivor->find('probe')->getNativeValue($native);
        $this->assertSame('still alive', $native);

        $survivor->destroy();
    }

    public function testDestroyReleasesFilledAndSealedTables(): void
    {
        $handles = 0;
        foreach ([false, true] as $sealed) {
            $table = new PersistentHashTable();

            $value = new ReflectionValue(1);
            $table->add('interned-key', $value);
            $table->addIndex(42, $value);
            $value->release();

            if ($sealed) {
                // Sealed tables sit at refcount 2; destroy() re-baselines it for the engine
                $table->markImmutable();
            }
            $table->destroy();
            $handles++;
        }

        $this->assertSame(2, $handles, 'Both the mutable and the sealed table were destroyed');
    }

    public function testDestroyReturnsProcessMemoryToTheAllocator(): void
    {
        $residentKiloBytes = static fn(): int => ResidentMemory::kiloBytes();

        if ($residentKiloBytes() === 0) {
            self::markTestSkipped('Resident set size is not measurable on this platform');
        }

        $churn = static function (int $cycles): void {
            for ($cycle = 0; $cycle < $cycles; $cycle++) {
                $table = new PersistentHashTable();
                for ($index = 0; $index < 64; $index++) {
                    $value = new ReflectionValue($index);
                    $table->addIndex($index, $value);
                    $value->release();
                }
                $table->destroy();
            }
        };

        // Warm up first: the very first cycles also grow the interned-string table and
        // the request heap, which has nothing to do with the tables being churned
        $churn(200);
        $baseline = $residentKiloBytes();

        // 1000 tables x (struct + a 64-element persistent data block) is several megabytes
        // of malloc traffic - if destroy() did not free it, RSS would climb accordingly
        $churn(1_000);

        $this->assertLessThan(
            2_048,
            $residentKiloBytes() - $baseline,
            'Destroyed persistent tables did not return their memory to the allocator',
        );
    }

    public function testStringKeysAreStoredAsPersistentInterned(): void
    {
        $table = new PersistentHashTable();

        $value = new ReflectionValue(1);
        $table->add('registry-key', $value);
        $value->release();

        foreach ($table->getIterator() as $key => $item) {
            $this->assertSame('registry-key', $key);
        }

        // The minted key itself must be interned-style: immutable + persistent
        $interned = StringEntry::persistentInterned('registry-key');
        $this->assertTrue($interned->isInterned());
        $this->assertTrue($interned->isPersistent());
        $this->assertFalse($interned->isPermanent());
        $this->assertSame(2, $interned->getReferenceCount());
        $this->assertSame('registry-key', $interned->getStringValue());
    }
}
