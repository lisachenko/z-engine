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
 * Raised when a graph is evicted (remove()/overwriting put()/destroy()) while userland
 * aliases of its objects are still alive in the current request. Freeing the malloc
 * blocks under a live alias would leave the alias dangling, so the eviction refuses
 * instead: release every alias (unset it) first.
 */
final class HeapInUseException extends PersistentHeapException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Persistent heap key \"{$key}\" is still referenced by live userland aliases; "
            . 'release them before evicting the graph',
        );
    }
}
