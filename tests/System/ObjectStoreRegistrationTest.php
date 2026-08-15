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

namespace ZEngine\System;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\TestPersistentCandidate;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;

/**
 * The public object-store surface a package outside z-engine needs
 *
 * A package that mints objects the engine did not allocate (persistent clones re-attached
 * for this request) has to register them for the request and hand the slot back afterwards.
 * Both used to require reaching through Core::$executor, which is core-layer state and not
 * a consumer API (AGENTS.md) - these are the named methods that replace the reach-through.
 */
class ObjectStoreRegistrationTest extends TestCase
{
    public function testCurrentReturnsTheStoreOfTheRunningRequest(): void
    {
        $store = ObjectStore::current();

        $this->assertSame($store, ObjectStore::current());
        $this->assertGreaterThan(0, $store->count());

        // It really is the store the engine uses: a live object is findable under its own id
        $probe = new TestPersistentCandidate();
        $entry = $store[spl_object_id($probe)];
        $this->assertNotNull($entry);
        $this->assertSame(TestPersistentCandidate::class, $entry->getClass()->getName());
    }

    public function testRegisterMakesAForeignObjectVisibleForThisRequest(): void
    {
        $source      = new TestPersistentCandidate();
        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        $store = ObjectStore::current();
        $entry = ObjectEntry::fromCData($clone);
        // A clone starts with the byte-copied handle of its source; registration is what
        // gives it one of its own
        $entry->setHandle(0);

        $handle          = $store->register($entry);
        $registeredEntry = $store[$handle];

        $this->assertGreaterThan(0, $handle);
        $this->assertSame($handle, $entry->getHandle());
        $this->assertTrue($store->offsetExists($handle));
        $this->assertNotNull($registeredEntry);
        $this->assertSame(Core::addressOf($clone), Core::addressOf($registeredEntry->getRawValue()));

        // ... and unregister() gives the slot back without destroying anything
        $store->unregister($handle);
        $this->assertFalse($store->offsetExists($handle));

        // The object itself survived the unregistration: its memory belongs to the caller
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE, $entry->getReferenceCount());

        Core::untrackAndFree($clone);
    }

    public function testACloneCanBeRegisteredAgainAfterBeingUnregistered(): void
    {
        $source      = new TestPersistentCandidate();
        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();

        $store = ObjectStore::current();
        $entry = ObjectEntry::fromCData($clone);
        $entry->setHandle(0);

        $first = $store->register($entry);
        $store->unregister($first);
        $second          = $store->register($entry);
        $registeredEntry = $store[$second];

        // Every request re-registers the same clone, which is what makes a persistent object
        // usable across requests: the handle is per-registration, the object is not
        $this->assertGreaterThan(0, $second);
        $this->assertSame($second, $entry->getHandle());
        $this->assertTrue($store->offsetExists($second));
        $this->assertNotNull($registeredEntry);
        $this->assertSame(Core::addressOf($clone), Core::addressOf($registeredEntry->getRawValue()));

        $store->unregister($second);
        Core::untrackAndFree($clone);
    }
}
