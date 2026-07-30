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

/**
 * Trait RefcountedTrait
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
            throw new \RuntimeException('Reference counter underflow: the value has already been released');
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
            Core::call('rc_dtor_func', Core::cast('zend_refcounted *', Core::addr($this->getGC())));
        }
    }

    /**
     * Checks if this variable is immutable or not
     */
    public function isImmutable(): bool
    {
        return (bool) ($this->getGC()->u->type_info & ReferenceCountedInterface::GC_IMMUTABLE);
    }

    /**
     * Checks if this variable is persistent (allocated using malloc)
     */
    public function isPersistent(): bool
    {
        return (bool) ($this->getGC()->u->type_info & ReferenceCountedInterface::GC_PERSISTENT);
    }

    /**
     * Checks if this variable is persistent for thread via thread-local-storage (TLS)
     */
    public function isPersistentLocal(): bool
    {
        return (bool) ($this->getGC()->u->type_info & ReferenceCountedInterface::GC_PERSISTENT_LOCAL);
    }

    /**
     * This method should return an instance of zend_refcounted_h
     */
    abstract protected function getGC(): CData;
}
