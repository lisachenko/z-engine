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

namespace ZEngine\OpCache;

/**
 * Raised when a mutation API targets an engine structure that lives in opcache
 * shared memory (ZEND_ACC_IMMUTABLE) and the writable copy-out path does not
 * apply to it.
 *
 * Shared-memory structures are visible to every worker process of the pool, so
 * mutating them in place would corrupt sibling processes, and freeing them
 * would tear memory out from under the opcache. z-engine therefore copies the
 * structure out into a writable per-process copy and repoints the per-process
 * table bucket at it; this exception reports the cases where that copy is not
 * possible. See docs/hot-swap.md for the exact support matrix.
 */
class SharedMemoryException extends \ReflectionException
{
    /**
     * The requested mutation needs a writable class entry, but the opcache-shared
     * one could not be copied out of shared memory
     *
     * @param string     $operation Human-readable operation name for the diagnostic
     * @param \Throwable $reason    Why the copy-out was refused
     */
    public static function immutableClassMutation(string $operation, \Throwable $reason): self
    {
        return new self(
            "Cannot {$operation} on an immutable (opcache shared-memory) class: the class entry is "
            . 'shared by all worker processes and cannot be modified in place, and copying it out '
            . "of shared memory is not possible ({$reason->getMessage()})",
            0,
            $reason,
        );
    }

    /**
     * The shared-memory class entry cannot be reproduced as a writable per-process copy
     *
     * @param string     $className Class that was to be copied out
     * @param \Throwable $reason    Copy-machinery failure that refused the class shape
     */
    public static function classCopyOutFailed(string $className, \Throwable $reason): self
    {
        return new self(
            "Cannot copy the immutable (opcache shared-memory) class {$className} out of shared "
            . "memory: {$reason->getMessage()}",
            0,
            $reason,
        );
    }

    /**
     * The method disappeared from the method table of the copied-out class: the copy is
     * built from the shared-memory method table, so this can only be an engine-level
     * inconsistency
     */
    public static function methodMissingAfterCopyOut(string $className, string $methodName): self
    {
        return new self(
            "Method {$className}::{$methodName}() is not published in the method table of the "
            . 'writable copy of the shared-memory class',
        );
    }

    /**
     * Copy-out requires the entry to be reachable through a per-process bucket
     */
    public static function functionNotPublished(string $lowerKey): self
    {
        return new self("Cannot copy out function {$lowerKey}: it is not published in the given table");
    }
}
