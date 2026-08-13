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

/**
 * Base class of every PersistentHeap failure (see docs/persistent-heap.md)
 *
 * All heap errors are raised BEFORE any engine memory is corrupted: put() rejects
 * unsupported graphs before allocating a single persistent byte, and get() refuses to
 * re-attach a graph whose classes or payloads no longer match the recorded metadata.
 */
class PersistentHeapException extends \RuntimeException
{
    /**
     * Raised when a wrapper whose registry was dismantled by destroy() is used again
     */
    public static function heapDestroyed(): self
    {
        return new self('This persistent heap has been destroyed');
    }

    /**
     * Raised when an inventory table is missing an entry the descriptor layout requires
     *
     * Every table the heap writes is filled with a contiguous integer key range, so a gap
     * means the metadata was mutated from outside the heap.
     */
    public static function corruptMetadata(int $index): self
    {
        return new self("Corrupt heap metadata: missing entry #{$index}");
    }
}
