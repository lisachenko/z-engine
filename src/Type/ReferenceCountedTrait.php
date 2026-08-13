<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Type;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_refcounted;
use ZEngine\Generated\zend_refcounted_h;

/**
 * Trait RefcountedTrait
 *
 * Direct access to the engine reference counter of a wrapped payload. These are the raw
 * primitives underneath the ownership layer; prefer ReleasableTrait::release() (which
 * releases exactly what a wrapper owns) over manual counter surgery. Direct calls are only
 * appropriate when editing a reference owned by an engine structure, eg dropping the names
 * of a removed trait entry.
 *
 * Guard rails: increment/decrement throw on immutable payloads (interned strings, immutable
 * arrays, shared-memory data must never be mutated) and on counter underflow.
 * releaseReference() drops exactly one reference with full engine semantics - destruction
 * at zero goes through rc_dtor_func, persistent (malloc) payloads are left for the engine
 * to reclaim, and nothing is ever freed through the FFI allocator.
 */
trait ReferenceCountedTrait
{
    /**
     * Returns an internal reference counter value
     */
    public function getReferenceCount(): int
    {
        return $this->getGC()->refcount;
    }

    /**
     * Increments a reference counter, so this object will live more than current scope
     *
     * @see zend_types.h:zend_gc_addref(zend_refcounted_h *p)
     */
    public function incrementReferenceCount(): int
    {
        if ($this->isImmutable()) {
            throw new \LogicException(
                'Cannot increment the reference counter of an immutable engine value '
                . '(interned string, immutable array or shared-memory data)',
            );
        }

        return ++$this->getGC()->refcount;
    }

    /**
     * Decrements a reference counter
     *
     * @see zend_types.h:zend_gc_delref(zend_refcounted_h *p)
     */
    public function decrementReferenceCount(): int
    {
        if ($this->isImmutable()) {
            throw new \LogicException(
                'Cannot decrement the reference counter of an immutable engine value '
                . '(interned string, immutable array or shared-memory data)',
            );
        }
        if ($this->getGC()->refcount <= 0) {
            throw TypeOperationException::referenceCountUnderflow();
        }

        return --$this->getGC()->refcount;
    }

    /**
     * Releases exactly one engine reference on the payload with full engine semantics
     *
     * Interned/immutable payloads are not refcounted and stay untouched. When the last
     * reference is dropped, the payload is destroyed through the engine's rc_dtor_func
     * (never through the FFI allocator). Persistent payloads at refcount zero are left
     * allocated: z-engine never frees engine-visible malloc memory that an engine
     * structure may still reach - such blocks are bounded and reclaimed at process end.
     *
     * @see zend_types.h:GC_DTOR/rc_dtor_func
     */
    public function releaseReference(): void
    {
        if ($this->isImmutable()) {
            return;
        }
        if ($this->decrementReferenceCount() === 0) {
            if ($this->isPersistent()) {
                return;
            }
            Core::call('rc_dtor_func', Core::cast(zend_refcounted::class, Core::addr($this->getGC())));
        }
    }

    /**
     * Checks if this variable is immutable or not
     */
    public function isImmutable(): bool
    {
        return $this->hasGcFlag(ReferenceCountedInterface::GC_IMMUTABLE);
    }

    /**
     * Checks if this variable is persistent (allocated using malloc)
     */
    public function isPersistent(): bool
    {
        return $this->hasGcFlag(ReferenceCountedInterface::GC_PERSISTENT);
    }

    /**
     * Checks if this variable is persistent for thread via thread-local-storage (TLS)
     */
    public function isPersistentLocal(): bool
    {
        return $this->hasGcFlag(ReferenceCountedInterface::GC_PERSISTENT_LOCAL);
    }

    /**
     * Tests the GC flag word (zend_refcounted_h.u.type_info) of the payload against a mask
     *
     * The single read of the flag word: every storage-class predicate of a refcounted
     * payload (immutable, persistent, thread-local, and the string-specific flags on top
     * of them) goes through it.
     */
    protected function hasGcFlag(int $flagMask): bool
    {
        return ($this->getGC()->u->type_info & $flagMask) !== 0;
    }

    /**
     * This method should return the zend_refcounted_h header of the wrapped payload
     * (the runtime value is the raw CData view of it)
     *
     * @return zend_refcounted_h
     */
    abstract protected function getGC(): object;
}
