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

/**
 * Ownership semantics of StringEntry: owning constructor, owned mints and engine-safe release
 */
final class StringEntryOwnershipTest extends TestCase
{
    public function testConstructorAddrefsHeapString(): void
    {
        $payload = 'heap string ' . random_int(1000, 9999) . str_repeat('s', 24);

        $first    = new StringEntry($payload);
        $baseline = $first->getReferenceCount();

        $second = new StringEntry($payload);
        $this->assertSame($baseline + 1, $first->getReferenceCount());

        $second->release();
        $this->assertSame($baseline, $first->getReferenceCount());
        $first->release();
    }

    public function testConstructorRoundTripsValue(): void
    {
        $payload = 'round trip ' . random_int(1000, 9999);
        $entry   = new StringEntry($payload);

        $this->assertSame($payload, $entry->getStringValue());
        $this->assertSame(strlen($payload), $entry->getLength());
    }

    public function testFromStringMintsOwnedRefcountOneString(): void
    {
        $entry = StringEntry::fromString('freshly minted string');

        $this->assertSame(1, $entry->getReferenceCount());
        $this->assertSame('freshly minted string', $entry->getStringValue());
        $this->assertFalse($entry->isInterned());
        $this->assertFalse($entry->isPersistent());
    }

    public function testPersistentMintsMallocBackedString(): void
    {
        $entry = StringEntry::persistent('persistent minted string');

        $this->assertSame(1, $entry->getReferenceCount());
        $this->assertTrue($entry->isPersistent());
        $this->assertSame('persistent minted string', $entry->getStringValue());

        // The hash must match what the engine computes for an equal request-lifetime string
        $twin     = StringEntry::fromString('persistent minted string');
        $twinHash = Core::call('zend_string_hash_func', $twin->getRawValue());
        $this->assertSame($twinHash, $entry->getHash());
    }

    public function testInternedStringIsNotAddreffed(): void
    {
        $entry = new StringEntry('interned');

        $this->assertTrue($entry->isInterned());
        // release() must be a no-op and must not underflow anything
        $entry->release();
        $this->assertSame('interned', $entry->getStringValue());
    }

    public function testIncrementOnInternedStringThrows(): void
    {
        $entry = new StringEntry('interned');

        $this->expectException(\LogicException::class);
        $entry->incrementReferenceCount();
    }

    public function testDecrementUnderflowThrows(): void
    {
        $entry = StringEntry::fromString('underflow probe');
        // Take manual control: the wrapper must not auto-release in the destructor
        $entry->transferReferenceOwnership();

        // Dropping to zero is allowed (the string stays allocated, no dtor ran)...
        $this->assertSame(0, $entry->decrementReferenceCount());

        // ...but going below zero must throw instead of corrupting the counter
        try {
            $this->expectException(\RuntimeException::class);
            $entry->decrementReferenceCount();
        } finally {
            // Bring the counter back and destroy the string properly
            $entry->incrementReferenceCount();
            $entry->releaseReference();
        }
    }
}
