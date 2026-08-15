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

use ZEngine\Core;

/**
 * The allocator z-engine has always used: raw blocks minted through PHP's FFI allocator
 *
 * Three modes, one per allocation shape the framework needs. They are exactly the three
 * calls the persistent primitives used to hardcode, so passing the matching instance (or
 * nothing at all, since every seam defaults to it) reproduces the previous behavior
 * byte for byte:
 *
 *  - trackedPersistent() - Core::trackedNew(..., persistent) -> pemalloc(size, 1), i.e.
 *    plain malloc, recorded in the tracked-block registry so untrackAndFree() may release
 *    it later through the very allocator that minted it. The allocation class of persistent
 *    object clones and of persistent hashtable structs.
 *  - trackedRequest() - Core::trackedNew(..., request) -> emalloc, registry-tracked. The
 *    allocation class of an ordinary owned HashTable, which dies with the request.
 *  - persistent() - Core::new(..., owned: false, persistent: true), malloc-backed and
 *    deliberately NOT tracked: the allocation class of persistent (interned) zend_strings,
 *    which are immortal by design and are never handed to untrackAndFree().
 *
 * Instances are stateless, so each mode is a process-wide singleton; identity comparison
 * against them is a legitimate way to ask "is this the default allocator?".
 *
 * Blocks are handed over to z-engine's own bookkeeping, never kept by the allocator, so
 * ownsAllocations() is false: the structures built on top may release them (destroy()).
 */
final class EngineAllocator implements Allocator
{
    /**
     * What malloc() guarantees for the persistent modes (alignof(max_align_t))
     */
    private const int MALLOC_ALIGNMENT = 16;

    /**
     * What the Zend memory manager guarantees for the request mode (ZEND_MM_ALIGNMENT)
     */
    private const int REQUEST_ALIGNMENT = 8;

    /**
     * One shared instance per mode, keyed "tracked?persistent?"
     *
     * @var array<string, self>
     */
    private static array $instances = [];

    private function __construct(
        private readonly bool $persistent,
        private readonly bool $tracked,
    ) {}

    /**
     * Malloc-backed blocks recorded in the tracked-block registry (the persistent default)
     */
    public static function trackedPersistent(): self
    {
        return self::$instances['tracked-persistent'] ??= new self(persistent: true, tracked: true);
    }

    /**
     * Request-lifetime blocks recorded in the tracked-block registry (the plain default)
     */
    public static function trackedRequest(): self
    {
        return self::$instances['tracked-request'] ??= new self(persistent: false, tracked: true);
    }

    /**
     * Malloc-backed blocks OUTSIDE the tracked-block registry (immortal by design)
     */
    public static function persistent(): self
    {
        return self::$instances['untracked-persistent'] ??= new self(persistent: true, tracked: false);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function allocate(int $size, int $alignment = Allocator::DEFAULT_ALIGNMENT): int
    {
        if ($size <= 0) {
            throw AllocationException::invalidSize($size);
        }
        if ($alignment <= 0 || ($alignment & ($alignment - 1)) !== 0) {
            throw AllocationException::invalidAlignment($alignment);
        }
        $guaranteed = $this->persistent ? self::MALLOC_ALIGNMENT : self::REQUEST_ALIGNMENT;
        if ($alignment > $guaranteed) {
            throw AllocationException::unsupportedAlignment($alignment, $guaranteed);
        }

        // FFI::new() zeroes whatever it allocates, in both allocation classes, which is
        // the "block reads as zero" half of the Allocator contract
        $block = $this->tracked
            ? Core::trackedNew("char[{$size}]", $this->persistent)
            : Core::new("char[{$size}]", false, $this->persistent);

        // The registry keys blocks by the address of the buffer itself, so the same number
        // is what a later untrackAndFree()/persistentFree() has to be given
        return Core::addressOf(Core::addr($block));
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function ownsAllocations(): bool
    {
        return false;
    }
}
