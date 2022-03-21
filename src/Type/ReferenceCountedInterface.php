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

use ZEngine\Constants\Defines;

/**
 * Interface for all refcounted entries
 */
interface ReferenceCountedInterface
{
//    public const GC_NOT_COLLECTABLE  = Defines::GC_NOT_COLLECTABLE;
    public const GC_PROTECTED        = Defines::GC_PROTECTED; // used for recursion detection
    public const GC_IMMUTABLE        = Defines::GC_IMMUTABLE; // can't be canged in place
    public const GC_PERSISTENT       = Defines::GC_PERSISTENT; // allocated using malloc
    public const GC_PERSISTENT_LOCAL = Defines::GC_PERSISTENT_LOCAL; // persistent, but thread-local

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
