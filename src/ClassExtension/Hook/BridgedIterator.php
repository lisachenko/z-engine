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

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use Iterator;

/**
 * Per-iteration state of one live bridged iterator
 *
 * IteratorBridge keeps one of these per engine iterator, keyed by its address. It is an
 * object rather than an array on purpose: PHP arrays are value types, so a record read out
 * of the registry would be a snapshot and marking the iteration broken would have to be
 * written back through a second registry lookup. As an object the record is shared by
 * reference, which is what lets every vtable callback do exactly one lookup.
 *
 * The registry entry is also what keeps the wrapped userland Iterator alive for the
 * lifetime of the engine iterator (see the IteratorBridge memory contract).
 *
 * @internal used by IteratorBridge
 */
final class BridgedIterator
{
    /**
     * Whether the iteration was terminated by a Throwable that cannot cross the FFI boundary
     *
     * Once set, valid() reports the end of the iteration from then on (issue #50).
     */
    public bool $broken = false;

    /**
     * @param Iterator $iterator Wrapped userland iterator every vtable callback forwards to
     * @param CData    $pointer  zend_object_iterator* handed over to the engine
     */
    public function __construct(
        public readonly Iterator $iterator,
        public readonly CData $pointer,
    ) {}
}
