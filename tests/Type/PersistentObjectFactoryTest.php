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
use ZEngine\Stub\TestPersistentCandidate;

class PersistentObjectFactoryTest extends TestCase
{
    public function testPersistentCloneRewritesGcHeaderAndHandlers(): void
    {
        $source = new TestPersistentCandidate();

        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        $entry = ObjectEntry::fromCData($clone);
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE, $entry->getReferenceCount());
        $this->assertTrue($entry->isPersistent());
        $this->assertFalse($entry->isImmutable());

        $destructorCalled = Core::engineConstant('IS_OBJ_DESTRUCTOR_CALLED');
        $this->assertSame($destructorCalled, $entry->getExtraFlags() & $destructorCalled);
        $this->assertTrue(PersistentObjectFactory::usesStandardHandlers($clone));
        $this->assertNull($entry->getDynamicPropertiesPointer());
    }

    public function testCloneRoundTripsThroughObjectStore(): void
    {
        $source          = new TestPersistentCandidate();
        $source->counter = 99;

        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        $store  = Core::$executor->objectStore;
        $handle = $store->put($clone);
        $this->assertSame($handle, $clone->handle);
        $this->assertTrue(isset($store[$handle]));

        // Materialize a userland alias of the persistent clone
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $clone[0]);
        $entry->getNativeValue($restored);
        $entry->release();

        $this->assertInstanceOf(TestPersistentCandidate::class, $restored);
        $this->assertSame(99, $restored->counter);
        $this->assertSame($handle, spl_object_id($restored));
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE + 1, ObjectEntry::fromCData($clone)->getReferenceCount());

        // Dropping the alias returns the refcount to the pin baseline
        unset($restored);
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE, ObjectEntry::fromCData($clone)->getReferenceCount());

        // Detaching hides the object from the store (and from shutdown teardown passes)
        $store->detach($handle);
        $this->assertFalse(isset($store[$handle]));
    }

    public function testRecycleKeepsStoreSizeBoundedAcrossPutCycles(): void
    {
        $source = new TestPersistentCandidate();

        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        $store = Core::$executor->objectStore;

        // Warm up allocator/CData churn, then measure: recycled slots must be reused,
        // so many put/recycle cycles may not grow the store top by more than a few
        // slots of unrelated CData churn. (Slot-level assertions are unmeasurable
        // here: every FFI CData is itself an object in this very store, so any
        // inspection of a freed slot immediately reuses it.)
        for ($i = 0; $i < 5; $i++) {
            $store->recycle($store->put($clone));
        }
        $baselineTop = count($store);

        for ($i = 0; $i < 50; $i++) {
            $handle = $store->put($clone);
            $this->assertSame($handle, $clone->handle);
            $store->recycle($handle);
        }

        $this->assertLessThanOrEqual($baselineTop + 4, count($store));
    }

    public function testPropertySlotAccessors(): void
    {
        $source          = new TestPersistentCandidate();
        $source->counter = 7;

        $entry = ObjectEntry::weakFor($source);

        $slot = $entry->getPropertySlot(0);
        $slot->getNativeValue($value);
        $this->assertSame(7, $value);

        // Slots past the first exercise the pointer-arithmetic path (zval[1] flexible member)
        $entry->getPropertySlot(1)->getNativeValue($ratio);
        $this->assertSame(1.5, $ratio);
        $entry->getPropertySlot(2)->getNativeValue($enabled);
        $this->assertFalse($enabled);

        $this->assertInstanceOf(\FFI\CData::class, $entry->getPropertyTablePointer());

        $this->expectException(\OutOfBoundsException::class);
        $entry->getPropertySlot(42);
    }
}
