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
 * Raised by remove() when the given key is not present in the heap
 */
final class HeapKeyNotFoundException extends PersistentHeapException
{
    public static function forKey(string $key): self
    {
        return new self("Persistent heap key \"{$key}\" does not exist");
    }
}
