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

use ArrayAccess;
use ArrayIterator;
use Countable;
use Iterator;
use IteratorAggregate;

/**
 * Specialization template exercising the engine-filled per-class caches of
 * IteratorAggregate/ArrayAccess/Countable method pointers
 *
 * @implements IteratorAggregate<int, string>
 * @implements ArrayAccess<int, int>
 */
class TestSpecializationCollection implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var array<int, int> */
    public array $items = [1, 2, 3];

    public function getIterator(): Iterator
    {
        $labels = [];
        foreach ($this->items as $item) {
            $labels[] = static::class . ':' . $item;
        }

        return new ArrayIterator($labels);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}
