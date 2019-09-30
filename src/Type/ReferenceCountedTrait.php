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
     */
    public function incrementReferenceCount(): void
    {
        $this->getGC()->refcount++;
    }

    /**
     * Decrements a reference counter
     */
    public function decrementReferenceCount(): void
    {
        $this->getGC()->refcount--;
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
