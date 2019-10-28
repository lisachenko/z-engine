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

/**
 * Interface for all refcounted entries
 */
interface ReferenceCountedInterface
{
    public const GC_COLLECTABLE      = (1 << 4);
    public const GC_PROTECTED        = (1 << 5); // used for recursion detection
    public const GC_IMMUTABLE        = (1 << 6); // can't be canged in place
    public const GC_PERSISTENT       = (1 << 7); // allocated using malloc
    public const GC_PERSISTENT_LOCAL = (1 << 8); // persistent, but thread-local

    /**
     * Returns an internal reference counter value
     */
    public function getReferenceCount(): int;

    /**
     * Increments a reference counter, so this object will live more than current scope
     */
    public function incrementReferenceCount(): int;

    /**
     * Decrements a reference counter
     */
    public function decrementReferenceCount(): int;

    /**
     * Checks if this variable is immutable or not
     */
    public function isImmutable(): bool;

    /**
     * Checks if this variable is persistent (allocated using malloc)
     */
    public function isPersistent(): bool;

    /**
     * Checks if this variable is persistent for thread via thread-local-storage (TLS)
     */
    public function isPersistentLocal(): bool;
}
