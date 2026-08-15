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

class EngineAllocatorTest extends TestCase
{
    public function testModesAreSharedStatelessInstances(): void
    {
        $this->assertSame(EngineAllocator::trackedPersistent(), EngineAllocator::trackedPersistent());
        $this->assertSame(EngineAllocator::trackedRequest(), EngineAllocator::trackedRequest());
        $this->assertSame(EngineAllocator::persistent(), EngineAllocator::persistent());

        $this->assertNotSame(EngineAllocator::trackedPersistent(), EngineAllocator::persistent());
        $this->assertNotSame(EngineAllocator::trackedPersistent(), EngineAllocator::trackedRequest());
    }

    public function testEveryModeHandsOutZeroedAlignedMemory(): void
    {
        foreach ($this->allModes() as $mode => $allocator) {
            $address = $allocator->allocate(128, Allocator::ENGINE_STRUCT_ALIGNMENT);

            $this->assertNotSame(0, $address, "{$mode} returned a null address");
            $this->assertSame(0, $address % Allocator::ENGINE_STRUCT_ALIGNMENT, "{$mode} is misaligned");

            $block = Core::pointerAtAddress('char *', $address);
            for ($offset = 0; $offset < 128; $offset++) {
                $byte = $block[$offset];
                $this->assertIsString($byte);
                $this->assertSame(0, ord($byte), "{$mode} left byte {$offset} dirty");
            }
        }
    }

    public function testBlocksAreHandedOverToTheFrameworkNotKeptByTheAllocator(): void
    {
        foreach ($this->allModes() as $mode => $allocator) {
            $this->assertFalse($allocator->ownsAllocations(), "{$mode} claims ownership of its blocks");
        }
    }

    public function testOnlyTheTrackedModesEnterTheBlockRegistry(): void
    {
        // The registry is what untrackAndFree() consults, so the mode a primitive picks
        // decides whether the framework may release the block later on
        $tracked   = Core::pointerAtAddress('char *', EngineAllocator::trackedPersistent()->allocate(64));
        $untracked = Core::pointerAtAddress('char *', EngineAllocator::persistent()->allocate(64));

        $this->assertTrue(Core::isTrackedBlock($tracked));
        $this->assertFalse(Core::isTrackedBlock($untracked));

        // The tracked block is releasable through the very allocator that minted it
        Core::untrackAndFree($tracked);
        $this->assertFalse(Core::isTrackedBlock($tracked));
    }

    public function testDistinctAllocationsNeverOverlap(): void
    {
        $first  = EngineAllocator::trackedPersistent()->allocate(64);
        $second = EngineAllocator::trackedPersistent()->allocate(64);

        $this->assertNotSame($first, $second);
        $this->assertGreaterThanOrEqual(64, abs($first - $second));
    }

    public function testNonPositiveSizeIsRefused(): void
    {
        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/positive number of bytes/');

        EngineAllocator::trackedPersistent()->allocate(0);
    }

    public function testAlignmentThatIsNotAPowerOfTwoIsRefused(): void
    {
        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/power of two/');

        EngineAllocator::trackedPersistent()->allocate(16, 24);
    }

    public function testAlignmentBeyondTheAllocatorGuaranteeIsRefused(): void
    {
        // malloc answers for 16 bytes, the Zend memory manager only for its own alignment:
        // an allocator that cannot promise what was asked for says so instead of hoping
        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/guarantees 8-byte alignment/');

        EngineAllocator::trackedRequest()->allocate(16, 16);
    }

    public function testMallocBackedModesSatisfyTheDefaultAlignment(): void
    {
        $address = EngineAllocator::trackedPersistent()->allocate(32, Allocator::DEFAULT_ALIGNMENT);

        $this->assertSame(0, $address % Allocator::DEFAULT_ALIGNMENT);
    }

    /**
     * @return array<string, Allocator>
     */
    private function allModes(): array
    {
        return [
            'trackedPersistent' => EngineAllocator::trackedPersistent(),
            'trackedRequest'    => EngineAllocator::trackedRequest(),
            'persistent'        => EngineAllocator::persistent(),
        ];
    }
}
