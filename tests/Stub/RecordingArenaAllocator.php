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

namespace ZEngine\Stub;

use ZEngine\Core;
use ZEngine\Memory\AllocationException;
use ZEngine\Memory\Allocator;
use ZEngine\Memory\EngineAllocator;

/**
 * Bump allocator over one owned region: the shape a fork-shared arena has, without the mmap
 *
 * The region itself is a single malloc block (that part is irrelevant to the seam - what
 * matters is that the allocator hands out addresses INSIDE memory it owns and says so), and
 * every block is carved out of it by bumping a cursor. Individual blocks are never released:
 * the owner drops the whole region, which is exactly why ownsAllocations() is true and why
 * structures built on it must refuse their destroy() path.
 *
 * Every request is recorded so a test can assert what a primitive actually asked for.
 */
final class RecordingArenaAllocator implements Allocator
{
    /**
     * Requests served so far, in order
     *
     * @var list<array{size: int, alignment: int, address: int}>
     */
    public private(set) array $allocations = [];

    private readonly int $regionAddress;

    private int $cursor;

    public function __construct(private readonly int $regionSize = 1 << 20)
    {
        // The region is zeroed once here; the bump cursor never revisits a byte, so every
        // block handed out satisfies the "reads as zero" half of the Allocator contract
        $this->regionAddress = EngineAllocator::persistent()->allocate($regionSize);
        $this->cursor        = $this->regionAddress;
    }

    #[\Override]
    public function allocate(int $size, int $alignment = Allocator::DEFAULT_ALIGNMENT): int
    {
        if ($size <= 0) {
            throw AllocationException::invalidSize($size);
        }
        if ($alignment <= 0 || ($alignment & ($alignment - 1)) !== 0) {
            throw AllocationException::invalidAlignment($alignment);
        }

        $address = ($this->cursor + $alignment - 1) & ~($alignment - 1);
        if ($address + $size > $this->regionAddress + $this->regionSize) {
            throw AllocationException::invalidSize($size);
        }
        $this->cursor = $address + $size;

        $this->allocations[] = ['size' => $size, 'alignment' => $alignment, 'address' => $address];

        return $address;
    }

    #[\Override]
    public function ownsAllocations(): bool
    {
        return true;
    }

    /**
     * Whether the given address points into this arena's region
     */
    public function contains(int $address): bool
    {
        return $address >= $this->regionAddress && $address < $this->regionAddress + $this->regionSize;
    }

    /**
     * Whether the block the given engine pointer refers to lives in this arena's region
     *
     * @param object $pointer Runtime value is always CData; stub-typed views are accepted
     */
    public function holds(object $pointer): bool
    {
        return $this->contains(Core::addressOf($pointer));
    }

    /**
     * Total number of bytes handed out so far
     */
    public function allocatedBytes(): int
    {
        return array_sum(array_column($this->allocations, 'size'));
    }
}
