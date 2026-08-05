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

namespace ZEngine\Memory;

/**
 * Raised when a mutation API targets an engine structure that lives in opcache
 * shared memory (ZEND_ACC_IMMUTABLE) and no writable copy-out path exists.
 *
 * Shared-memory structures are visible to every worker process of the pool, so
 * mutating them in place would corrupt sibling processes, and freeing them
 * would tear memory out from under the opcache. See docs/hot-swap.md for the
 * exact support matrix.
 */
class SharedMemoryException extends \ReflectionException
{
    /**
     * The requested mutation would write an opcache-shared class entry in place
     *
     * @param string $operation Human-readable operation name for the diagnostic
     */
    public static function immutableClassMutation(string $operation): self
    {
        return new self(
            "Cannot {$operation} on an immutable (opcache shared-memory) class: "
            . 'the class entry is shared by all worker processes and cannot be modified in place',
        );
    }

    /**
     * A method of an opcache-shared class cannot be redefined: its method table
     * lives inside the shared class entry, there is no writable slot to repoint
     */
    public static function immutableMethodTable(): self
    {
        return new self(
            'Cannot redefine a method of an immutable (opcache shared-memory) class: '
            . 'its method table lives inside the shared class entry',
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
