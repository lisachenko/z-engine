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
 * Raised when the persistent heap registry cannot be reached because the zengine module
 * carries no globals block - the zval-typed module globals ARE the anchor slot the
 * registry is recovered from, so without them there is nothing to mint or clear.
 *
 * Stays inside the PersistentHeapException hierarchy: the anchor is heap state, and
 * every `catch (PersistentHeapException)` around a heap lookup must keep matching.
 */
final class HeapAnchorMissingException extends PersistentHeapException
{
    public static function create(): self
    {
        return new self('The zengine module has no globals block');
    }
}
