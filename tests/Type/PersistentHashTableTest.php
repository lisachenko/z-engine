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

class PersistentHashTableTest extends TestCase
{
    public function testCreateMintsPersistentNonCollectableTable(): void
    {
        $table = PersistentHashTable::create();

        $this->assertTrue($table->isPersistent());
        $this->assertFalse($table->isImmutable());
        $this->assertSame(1, $table->getReferenceCount());
        $this->assertCount(0, iterator_to_array($table->getIterator(), false));
    }

    public function testStringKeyUpsertAndFind(): void
    {
        $table = PersistentHashTable::create();

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
        $table = PersistentHashTable::create();

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
        $table = PersistentHashTable::create();

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

    public function testStringKeysAreStoredAsPersistentInterned(): void
    {
        $table = PersistentHashTable::create();

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
