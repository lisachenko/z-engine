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
 * Raised when the heap is used while it is inert: between the request-shutdown delivery
 * (which follows Core::shutdown()) and the next request startup, no heap operation may
 * run - engine writes are forbidden in that window (docs/long-running.md).
 */
final class HeapInertException extends PersistentHeapException
{
    public static function create(): self
    {
        return new self(
            'The persistent heap is inert: the request is shutting down (or Core::shutdown() '
            . 'already ran) and no engine memory may be written anymore',
        );
    }
}
