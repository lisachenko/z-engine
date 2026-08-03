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
 * Raised at re-attachment (first get() of a request) when a property slot of a stored
 * object points at a payload that is not part of the graph's recorded persistent
 * inventory - the telltale of a refcounted value written into a persistent object during
 * an earlier request (the written payload was request memory and is gone). The check
 * compares addresses only, so the stale pointer is never dereferenced; the graph is
 * refused instead of returning corrupted data. remove() is the only safe operation left.
 */
final class GraphCorruptedException extends PersistentHeapException
{
    public static function forSlot(string $key, string $className, int $slot): self
    {
        return new self(
            "Cannot re-attach persistent heap key \"{$key}\": property slot #{$slot} of a stored "
            . "{$className} object points outside the persistent graph (a refcounted value was "
            . 'written into the persistent object during an earlier request)',
        );
    }
}
