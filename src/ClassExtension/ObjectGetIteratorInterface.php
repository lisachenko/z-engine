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

namespace ZEngine\ClassExtension;

use Iterator;
use ZEngine\ClassExtension\Hook\GetIteratorHook;

/**
 * Interface ObjectGetIteratorInterface gives a class an engine-level iterator (ce->get_iterator)
 *
 * When installed, `foreach` (and every other engine consumer of ce->get_iterator) drives a
 * native zend_object_iterator that is bridged to the userland Iterator returned by the
 * handler - without the class implementing \Traversable.
 *
 * Semantics and limitations (see GetIteratorHook for the full contract):
 *  - by-reference iteration (`foreach ($obj as &$v)`) is rejected: the handler bridge
 *    returns no iterator and the engine raises the standard Error;
 *  - the returned Iterator's methods are invoked from inside engine opcodes through an FFI
 *    trampoline, so exceptions cannot propagate out of them (issue #50): a Throwable is
 *    reported as E_USER_WARNING and cleanly terminates the iteration instead.
 */
interface ObjectGetIteratorInterface
{
    /**
     * Returns the userland iterator that will drive this engine-level iteration
     *
     * Called once per started iteration (each foreach gets its own iterator instance, so
     * nested loops over the same object do not interfere). Use $hook->getObject() to access
     * the instance being iterated.
     *
     * @param GetIteratorHook $hook Hook instance with additional context
     */
    public static function __getIterator(GetIteratorHook $hook): Iterator;
}
