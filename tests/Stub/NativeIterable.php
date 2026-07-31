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

namespace ZEngine\Stub;

use ArrayIterator;
use Iterator;
use ZEngine\ClassExtension\Hook\GetIteratorHook;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectGetIteratorInterface;

/**
 * Collection iterable via an engine-level iterator, without implementing \Traversable
 *
 * Engine-level iteration (ce->get_iterator, the mechanism behind Generator/ArrayIterator)
 * is the feature under test: foreach drives the userland Iterator returned by
 * __getIterator() through the native zend_object_iterator bridge.
 */
class NativeIterable implements ObjectCreateInterface, ObjectGetIteratorInterface
{
    use ObjectCreateTrait;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(public array $items = []) {}

    public static function __getIterator(GetIteratorHook $hook): Iterator
    {
        $object = $hook->getObject();
        assert($object instanceof self);

        return new ArrayIterator($object->items);
    }
}
