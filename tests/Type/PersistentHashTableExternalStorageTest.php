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
use ZEngine\Generated\Bucket;
use ZEngine\Memory\Allocator;
use ZEngine\Memory\EngineAllocator;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\RecordingArenaAllocator;

/**
 * Bucket storage that the CALLER allocated: the second half of the arena story
 *
 * A table whose struct lives in an arena but whose buckets the engine pemallocs is still
 * half in the process heap. installExternalStorage() closes that gap - and the growth guard
 * is what keeps the engine from silently reopening it with a perealloc.
 */
class PersistentHashTableExternalStorageTest extends TestCase
{
    public function testStorageSizeMatchesTheEngineLayout(): void
    {
        // HT_SIZE_EX(nTableSize, HT_SIZE_TO_MASK(nTableSize)): two uint32_t hash slots per
        // bucket, then the buckets themselves
        $bucketSize = Core::sizeOfType(Bucket::class);
        foreach ([8, 16, 64, 1024] as $capacity) {
            $this->assertSame(
                $capacity * 2 * 4 + $capacity * $bucketSize,
                PersistentHashTable::externalStorageSize($capacity),
            );
        }
    }

    public function testCapacityMustBeAPowerOfTwoAboveTheEngineMinimum(): void
    {
        foreach ([0, 1, 4, 12, 100] as $invalid) {
            try {
                PersistentHashTable::externalStorageSize($invalid);
                $this->fail("Capacity {$invalid} should have been refused");
            } catch (TypeOperationException $exception) {
                $this->assertMatchesRegularExpression('/power of two/', $exception->getMessage());
            }
        }

        $this->assertGreaterThan(0, PersistentHashTable::externalStorageSize(Core::engineConstant('HT_MIN_SIZE')));
    }

    public function testInstalledStorageBacksEveryBucketOfTheTable(): void
    {
        $capacity = 16;
        $table    = $this->tableWithExternalStorage($capacity);
        $address  = $table->getExternalStorageAddress();

        $this->assertTrue($table->hasExternalStorage());
        $this->assertNotNull($address);
        $this->assertSame($capacity, $table->getCapacity());
        $this->assertSame($capacity, $table->getRemainingCapacity());

        for ($index = 0; $index < $capacity; $index++) {
            $value = new ReflectionValue($index * 3);
            $table->add("key{$index}", $value);
            $value->release();
        }

        $this->assertCount($capacity, $table);
        $this->assertSame(0, $table->getRemainingCapacity());

        // Both lookup paths walk the installed hash part and bucket area
        $this->assertSame(21, self::valueOf($table->find('key7')));
        $this->assertNull($table->find('missing'));
        $this->assertSame(range(0, ($capacity - 1) * 3, 3), array_values(array_map(
            static function (ReflectionValue $value): int {
                $value->getNativeValue($native);
                assert(is_int($native));

                return $native;
            },
            iterator_to_array($table->getIterator()),
        )));

        // The engine never moved the block it was given
        $table->assertNoGrowth();
        $arData = $table->getRawValue()->arData;
        assert($arData !== null);
        $this->assertSame($address + $capacity * 2 * 4, Core::addressOf($arData));
    }

    public function testIntegerKeysUseTheInstalledStorageToo(): void
    {
        $table = $this->tableWithExternalStorage(8);

        foreach ([5, 9, 17] as $key) {
            $value = new ReflectionValue($key * 100);
            $table->addIndex($key, $value);
            $value->release();
        }

        $this->assertSame(900, self::valueOf($table->findIndex(9)));
        $this->assertSame(5, $table->getRemainingCapacity());
        $table->assertNoGrowth();
    }

    public function testAnInsertThatWouldGrowTheStorageIsRefused(): void
    {
        $capacity = 8;
        $table    = $this->tableWithExternalStorage($capacity);

        for ($index = 0; $index < $capacity; $index++) {
            $value = new ReflectionValue($index);
            $table->addIndex($index, $value);
            $value->release();
        }

        $overflow = new ReflectionValue(999);
        try {
            $table->addIndex($capacity, $overflow);
            $this->fail('The insert that would have made the engine perealloc was not refused');
        } catch (TypeOperationException $exception) {
            $this->assertMatchesRegularExpression('/bucket slots/', $exception->getMessage());
        }
        // Refused BEFORE the engine saw the write: the table is untouched and intact
        $this->assertCount($capacity, $table);
        $table->assertNoGrowth();

        // A string key is guarded on the very same boundary
        try {
            $table->add('one more', $overflow);
            $this->fail('The string-keyed insert past the capacity was not refused');
        } catch (TypeOperationException $exception) {
            $this->assertMatchesRegularExpression('/bucket slots/', $exception->getMessage());
        }
        $overflow->release();
        $table->assertNoGrowth();
    }

    public function testUpsertingAnExistingKeyStaysAllowedAtFullCapacity(): void
    {
        $capacity = 8;
        $table    = $this->tableWithExternalStorage($capacity);

        for ($index = 0; $index < $capacity; $index++) {
            $value = new ReflectionValue($index);
            $table->add("key{$index}", $value);
            $value->release();
        }
        $this->assertSame(0, $table->getRemainingCapacity());

        // An upsert consumes no bucket slot, so the guard must let it through
        $replacement = new ReflectionValue(4242);
        $table->add('key3', $replacement);
        $replacement->release();

        $this->assertSame(4242, self::valueOf($table->find('key3')));
        $this->assertCount($capacity, $table, 'An upsert must not add an element');
        $table->assertNoGrowth();
    }

    public function testStorageCannotBeInstalledOnATableTheEngineAlreadyInitialized(): void
    {
        $table = new PersistentHashTable();
        $value = new ReflectionValue(1);
        $table->add('first', $value);
        $value->release();

        $capacity = 8;
        $address  = EngineAllocator::persistent()->allocate(
            PersistentHashTable::externalStorageSize($capacity),
            Allocator::DEFAULT_ALIGNMENT,
        );

        try {
            $table->installExternalStorage($address, $capacity);
            $this->fail('Storage was installed over an engine-allocated data block');
        } catch (TypeOperationException $exception) {
            $this->assertMatchesRegularExpression('/untouched table/', $exception->getMessage());
        }

        $table->destroy();
    }

    public function testAZeroStorageAddressIsRefused(): void
    {
        $table = new PersistentHashTable();

        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/must not be zero/');
        try {
            $table->installExternalStorage(0, 8);
        } finally {
            $table->destroy();
        }
    }

    public function testATableBackedByExternalStorageRefusesToBeDestroyed(): void
    {
        $table = $this->tableWithExternalStorage(8);

        // zend_hash_destroy() would pefree() the caller's block - that is the whole hazard
        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/externally allocated memory/');
        $table->destroy();
    }

    public function testAStorageRelocationIsDiagnosed(): void
    {
        $table   = $this->tableWithExternalStorage(8);
        $address = $table->getExternalStorageAddress();
        $this->assertNotNull($address);

        // Simulate what a perealloc by engine code would leave behind: arData somewhere else
        $table->getRawValue()->arData = Core::pointerAtAddress(Bucket::class, $address + 4096);

        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/no longer the one in use/');
        $table->assertNoGrowth();
    }

    public function testAssertNoGrowthIsANoOpForEngineAllocatedStorage(): void
    {
        $table = new PersistentHashTable();
        $value = new ReflectionValue(1);
        $table->add('grown by the engine', $value);
        $value->release();

        $table->assertNoGrowth();
        $this->assertFalse($table->hasExternalStorage());
        $this->assertSame(0, $table->getCapacity());
        $this->assertSame(0, $table->getRemainingCapacity());

        $table->destroy();
    }

    public function testStructAndStorageCanBothLiveInTheSameForeignRegion(): void
    {
        // The end state the seam exists for: nothing of the table is in the process heap
        $arena    = new RecordingArenaAllocator();
        $capacity = 8;
        $address  = $arena->allocate(
            PersistentHashTable::externalStorageSize($capacity),
            Allocator::DEFAULT_ALIGNMENT,
        );
        $table = PersistentHashTable::withExternalStorage($address, $capacity, $arena);

        $this->assertTrue($arena->holds($table->getRawValue()));
        $this->assertTrue($arena->contains($address));

        $value = new ReflectionValue('in the arena');
        $table->add('key', $value);
        $value->release();

        $this->assertSame('in the arena', self::valueOf($table->find('key')));

        $arData = $table->getRawValue()->arData;
        assert($arData !== null);
        $this->assertTrue($arena->contains(Core::addressOf($arData)), 'The engine reallocated the buckets');
        $table->assertNoGrowth();
    }

    /**
     * Reads the PHP value behind a lookup result, asserting the lookup found anything at all
     */
    private static function valueOf(?ReflectionValue $value): mixed
    {
        self::assertNotNull($value);
        $value->getNativeValue($native);

        return $native;
    }

    /**
     * Mints a persistent table with a freshly allocated external bucket block installed
     */
    private function tableWithExternalStorage(int $capacity): PersistentHashTable
    {
        $address = EngineAllocator::persistent()->allocate(
            PersistentHashTable::externalStorageSize($capacity),
            Allocator::DEFAULT_ALIGNMENT,
        );

        return PersistentHashTable::withExternalStorage($address, $capacity);
    }
}
