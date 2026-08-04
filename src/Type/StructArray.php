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

namespace ZEngine\Type;

use ArrayAccess;
use Countable;
use FFI\CData;
use IteratorAggregate;
use Traversable;
use ZEngine\Core;

/**
 * Typed, bounds-checked view over a contiguous in-memory array of engine structures
 *
 * The engine stores many sequences as plain C arrays rather than HashTables: zval
 * tables (op_array literals, class default property/static tables), pointer lists
 * (resolved interfaces) and single-struct dereferences. This view owns all the
 * size-related machinery (bounds checking, iteration, replace) so callers reach the
 * elements through ordinary array access instead of hand-rolled pointer arithmetic
 * (see AGENTS.md "Engine structs are typed by shape"). Each element is returned as a
 * raw CData handle - wrap it in the owning reflection/type object (eg
 * ReflectionValue::fromValueEntry()) for typed access.
 *
 * The view is BORROWED: the base pointer stays owned by the engine structure it was
 * read from and must outlive the view. Element pointer arithmetic follows the FFI
 * type of the base pointer, so the base must be a correctly typed CData (eg `zval *`
 * or `zend_class_entry **`).
 *
 * @internal
 *
 * @implements IteratorAggregate<int, CData>
 * @implements ArrayAccess<int, CData>
 */
final class StructArray implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param CData $baseAddress Typed pointer to the first element
     * @param int   $count       Number of elements behind the pointer (negatives read as empty)
     */
    public function __construct(
        private CData $baseAddress,
        private int $count,
    ) {}

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->count;
    }

    public function offsetGet(mixed $offset): CData
    {
        assert(is_int($offset));

        return $this->rawAt($offset);
    }

    /**
     * Overwrites the element at the given index, dropping the previous value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        assert(is_int($offset) && $value instanceof CData);
        $this->replace($offset, $value);
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Struct-array elements live in engine memory and cannot be unset');
    }

    /**
     * Replaces the element at the given position with a new value and returns a
     * detached byte-exact copy of the previous one
     *
     * This is a raw slot overwrite: engine reference semantics (if any) are the
     * caller's responsibility.
     */
    public function replace(int $position, CData $value): CData
    {
        $previous     = $this->rawAt($position);
        $elementSize  = Core::sizeof($previous);
        $previousCopy = Core::new("char[{$elementSize}]");
        Core::memcpy($previousCopy, $previous, $elementSize);
        Core::memcpy($previous, $value, $elementSize);

        return $previousCopy;
    }

    /**
     * Returns the element at the given index as the raw CData handle
     */
    public function rawAt(int $index): CData
    {
        if ($index < 0 || $index >= $this->count) {
            throw new \OutOfBoundsException(
                "Struct array index {$index} is out of bounds, valid range is 0..{$this->count}",
            );
        }
        $element = $this->baseAddress[$index];
        assert($element instanceof CData);

        return $element;
    }

    public function count(): int
    {
        return max($this->count, 0);
    }

    /**
     * @return Traversable<int, CData>
     */
    public function getIterator(): Traversable
    {
        for ($index = 0; $index < $this->count; $index++) {
            yield $index => $this->rawAt($index);
        }
    }
}
