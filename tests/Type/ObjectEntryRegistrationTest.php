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
use ZEngine\Generated\zend_object;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\TestPersistentCandidate;

/**
 * Store registration seen from the object that is being registered
 *
 * A package that mints objects the engine did not allocate (persistent clones re-attached
 * for this request) has to make them visible for the request and hand the slot back
 * afterwards. Both operations belong to the object, not to a store handed out to callers:
 * the engine-global wrappers behind Core::$executor stay core-layer state (AGENTS.md) and
 * a consumer never touches EG(objects_store) at all.
 */
class ObjectEntryRegistrationTest extends TestCase
{
    public function testRegisterMakesAForeignObjectVisibleForThisRequest(): void
    {
        $clone = $this->persistentClone();
        $entry = ObjectEntry::fromCData($clone);
        // A clone starts with the byte-copied handle of its source; registration is what
        // gives it one of its own
        $entry->setHandle(0);

        $handle = $entry->register();

        $this->assertGreaterThan(0, $handle);
        $this->assertSame($handle, $entry->getHandle());

        // It really is in the store the engine uses: the object materializes under its
        // own handle, and spl_object_id() agrees with it
        $instance = $entry->getNativeValue();
        $this->assertSame($handle, spl_object_id($instance));
        $this->assertSame(TestPersistentCandidate::class, $instance::class);

        $entry->unregister();
        Core::untrackAndFree($clone);
    }

    public function testUnregisterReturnsTheSlotWithoutTouchingTheObject(): void
    {
        $clone = $this->persistentClone();
        $entry = ObjectEntry::fromCData($clone);
        $entry->setHandle(0);

        $handle = $entry->register();
        $entry->unregister();

        // The slot is free again ...
        $this->assertFalse(Core::$executor->objectStore->offsetExists($handle));
        // ... and the object itself survived: its memory belongs to the caller, and the
        // pinned refcount of a persistent clone is untouched by the detach
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE, $entry->getReferenceCount());
        $this->assertTrue($entry->isPersistent());

        Core::untrackAndFree($clone);
    }

    public function testACloneCanBeRegisteredAgainAfterBeingUnregistered(): void
    {
        $clone = $this->persistentClone();
        $entry = ObjectEntry::fromCData($clone);
        $entry->setHandle(0);

        $entry->register();
        $entry->unregister();
        $second = $entry->register();

        // Every request re-registers the same clone, which is what makes a persistent object
        // usable across requests: the handle is per-registration, the object is not
        $this->assertGreaterThan(0, $second);
        $this->assertSame($second, $entry->getHandle());
        $this->assertSame($second, spl_object_id($entry->getNativeValue()));

        $entry->unregister();
        Core::untrackAndFree($clone);
    }

    public function testUnregisteringATwiceDetachedObjectIsRefused(): void
    {
        $clone = $this->persistentClone();
        $entry = ObjectEntry::fromCData($clone);
        $entry->setHandle(0);

        $entry->register();
        $entry->unregister();

        // The stale handle now points at a free (or meanwhile reused) slot: recycling it
        // again would hand somebody else's object to the free list
        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/does not occupy the object store slot/');
        try {
            $entry->unregister();
        } finally {
            Core::untrackAndFree($clone);
        }
    }

    public function testUnregisteringANeverRegisteredObjectIsRefused(): void
    {
        $clone = $this->persistentClone();
        $entry = ObjectEntry::fromCData($clone);
        $entry->setHandle(0);

        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/never registered in this request/');
        try {
            $entry->unregister();
        } finally {
            Core::untrackAndFree($clone);
        }
    }

    public function testAnObjectTheEngineOwnsReportsItsOwnStoreSlot(): void
    {
        // An ordinary object is already registered by zend_object_std_init: the handle the
        // wrapper reports is the very slot spl_object_id() names
        $instance = new TestPersistentCandidate();
        $entry    = ObjectEntry::weakFor($instance);

        $this->assertSame(spl_object_id($instance), $entry->getHandle());
        $this->assertTrue(Core::$executor->objectStore->offsetExists($entry->getHandle()));
    }

    /**
     * Mints a persistent clone of a fresh scalar-only object
     *
     * @return zend_object zend_object* of the clone, owned by the caller
     */
    private function persistentClone(): object
    {
        $sourceValue = new ReflectionValue(new TestPersistentCandidate());
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        return $clone;
    }
}
