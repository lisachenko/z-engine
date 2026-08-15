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

namespace ZEngine\Memory;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\Generated\zend_string;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\RecordingArenaAllocator;
use ZEngine\Stub\TestGraphNode;
use ZEngine\Stub\TestPersistentCandidate;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;
use ZEngine\Type\TypeOperationException;

/**
 * The allocator seam seen from the primitives: every persistent block a caller can redirect
 *
 * The arena stands in for the fork-shared mmap region of the downstream consumer: it owns its
 * memory, so a structure built on it must never be released through z-engine's own allocator.
 */
class AllocatorSeamTest extends TestCase
{
    public function testPersistentCloneCanBeMintedInsideAForeignRegion(): void
    {
        $arena           = new RecordingArenaAllocator();
        $source          = new TestPersistentCandidate();
        $source->counter = 42;

        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject(), $arena);
        $sourceValue->release();

        $this->assertTrue($arena->holds($clone), 'The clone was not allocated from the arena');
        $this->assertCount(1, $arena->allocations);
        $this->assertSame(Allocator::ENGINE_STRUCT_ALIGNMENT, $arena->allocations[0]['alignment']);

        // The clone is a full persistent clone, not a degraded one: same header surgery
        $entry = ObjectEntry::fromCData($clone);
        $this->assertSame(PersistentObjectFactory::PIN_BASELINE, $entry->getReferenceCount());
        $this->assertTrue($entry->isPersistent());
        $this->assertTrue(PersistentObjectFactory::usesStandardHandlers($clone));
        $entry->getPropertySlot(0)->getNativeValue($counter);
        $this->assertSame(42, $counter);
    }

    public function testPersistentStringsCanBeMintedInsideAForeignRegion(): void
    {
        $arena = new RecordingArenaAllocator();

        $string = StringEntry::persistent('arena resident', $arena);
        $this->assertTrue($arena->holds($string->getRawValue()));
        $this->assertSame('arena resident', $string->getStringValue());
        $this->assertTrue($string->isPersistent());
        $this->assertFalse($string->isInterned());

        $interned = StringEntry::persistentInterned('arena interned', $arena);
        $this->assertTrue($arena->holds($interned->getRawValue()));
        $this->assertSame('arena interned', $interned->getStringValue());
        $this->assertTrue($interned->isInterned());
        $this->assertSame(2, $interned->getReferenceCount());

        // Both blocks are exactly as large as the engine layout requires
        $expectedSize = Core::offsetOfField(zend_string::class, 'val') + strlen('arena resident') + 1;
        $this->assertSame($expectedSize, $arena->allocations[0]['size']);
    }

    public function testHashTableStructCanBeMintedInsideAForeignRegionAndRefusesToBeFreed(): void
    {
        $arena = new RecordingArenaAllocator();
        $table = new PersistentHashTable($arena);

        $this->assertTrue($arena->holds($table->getRawValue()));
        $this->assertSame(Core::sizeOfType(HashTableStruct::class), $arena->allocations[0]['size']);
        $this->assertTrue($table->isPersistent());

        // Releasing arena memory through the framework's own allocator is the corruption
        // this ownership report exists to prevent
        $this->expectException(TypeOperationException::class);
        $this->expectExceptionMessageMatches('/foreign allocator/');
        $table->destroy();
    }

    public function testWholeGraphsAreClonedIntoTheAllocatorTheClonerWasGiven(): void
    {
        $arena = new RecordingArenaAllocator();

        $root        = new TestGraphNode();
        $root->name  = 'root';
        $root->rank  = 1;
        $child       = new TestGraphNode();
        $child->name = 'child';
        $child->rank = 2;
        // A cycle, so the identity map is exercised while the arena is in play
        $child->parent = $root;
        $root->left    = $child;

        $graph = (new PersistentGraphCloner($arena))->persist($root);

        $this->assertCount(2, $graph->objects);
        foreach ($graph->objects as $object) {
            $this->assertTrue($arena->holds($object), 'A cloned object escaped the arena');
        }
        foreach ($graph->strings as $string) {
            $this->assertTrue($arena->holds($string), 'A cloned string escaped the arena');
        }
        $this->assertGreaterThan(0, $arena->allocatedBytes());

        // Nothing of the graph ended up in z-engine's own block registry
        foreach ($graph->objects as $object) {
            $this->assertFalse(Core::isTrackedBlock($object));
        }
    }

    public function testDefaultsKeepTheHistoricalAllocationClasses(): void
    {
        // The seam must be invisible to every existing caller: object clones and table
        // structs stay tracked malloc blocks, string blocks stay untracked malloc blocks
        $source      = new TestPersistentCandidate();
        $sourceValue = new ReflectionValue($source);
        $clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
        $sourceValue->release();
        $this->assertTrue(Core::isTrackedBlock($clone));

        $table = new PersistentHashTable();
        $this->assertTrue(Core::isTrackedBlock($table->getRawValue()));
        $table->destroy();

        $string = StringEntry::persistent('untracked by design');
        $this->assertFalse(Core::isTrackedBlock($string->getRawValue()));
        $this->assertTrue($string->isPersistent());
    }
}
