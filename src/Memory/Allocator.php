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
 * Source of raw, zeroed memory blocks for the persistent structures z-engine mints
 *
 * Every persistent primitive of the framework - the object clones of
 * PersistentObjectFactory, the interned blocks of StringEntry::persistent(), the struct
 * of a PersistentHashTable - allocates through one of these. The default implementation
 * (EngineAllocator) is the malloc-backed FFI allocator z-engine has always used, so a
 * caller that passes nothing keeps exactly the behavior it had before this seam existed.
 *
 * The seam exists for sinks that are NOT the process heap: a fork-shared mmap arena, a
 * shared-memory segment, a preallocated slab. Such an allocator hands out addresses inside
 * memory it owns itself, which is why ownsAllocations() is part of the contract: z-engine
 * must never release a block it did not allocate (destroy() refuses instead of calling
 * free(3) on somebody else's arena).
 *
 * The interface deliberately speaks in ADDRESSES rather than FFI\CData: a public API of
 * this framework never leaks engine handles (see AGENTS.md), and an implementation living
 * in a consumer package can therefore be written against nothing but integers - it binds
 * its own mmap/shm primitives with FFI::cdef and returns the address it computed.
 *
 * Implementation contract:
 *
 *  - the returned block is at least $size bytes long and ZEROED, exactly like
 *    FFI::new()/pemalloc + memset - engine structures are initialized field by field and
 *    every field the caller does not write must read as zero;
 *  - the address is aligned to at least $alignment bytes;
 *  - the block stays alive at least until the owner releases it: nothing in z-engine
 *    reclaims blocks of a foreign allocator;
 *  - allocate() throws (AllocationException or an implementation-specific exception) when
 *    it cannot satisfy the request; it never returns 0.
 */
interface Allocator
{
    /**
     * Alignment good enough for every engine structure: what malloc() guarantees on the
     * supported platforms (alignof(max_align_t) is 16 on x64 and arm64)
     */
    public const int DEFAULT_ALIGNMENT = 16;

    /**
     * Alignment an engine structure actually REQUIRES: pointer alignment
     *
     * zend_object, zend_string, HashTable and Bucket are made of pointers, zend_longs and
     * doubles - all of them 8-byte-aligned members on every supported platform, which is
     * also what the Zend memory manager itself guarantees (ZEND_MM_ALIGNMENT). The
     * framework's own allocations ask for exactly this, so a request-lifetime block is a
     * legal answer too; DEFAULT_ALIGNMENT stays the safer public default for callers who
     * do not want to reason about it.
     */
    public const int ENGINE_STRUCT_ALIGNMENT = 8;

    /**
     * Allocates one zeroed block and returns its ADDRESS
     *
     * @param int $size      Number of bytes to allocate, greater than zero
     * @param int $alignment Required alignment of the returned address, a power of two
     *
     * @return int Address of the block, never zero
     *
     * @throws AllocationException when the request cannot be satisfied
     */
    public function allocate(int $size, int $alignment = self::DEFAULT_ALIGNMENT): int;

    /**
     * Whether the blocks handed out stay owned by THIS allocator
     *
     * `true` means the memory belongs to the allocator (an arena, a shared segment): the
     * owner reclaims it as a whole and z-engine must never free an individual block - the
     * structures built on top refuse their destroy() path instead of guessing an allocator.
     *
     * `false` means the block was handed over to z-engine's own bookkeeping (the
     * tracked-block registry and the persistent allocator behind it), which is what the
     * default EngineAllocator does and what every pre-existing caller relies on.
     */
    public function ownsAllocations(): bool;
}
