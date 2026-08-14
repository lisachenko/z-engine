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
 * elements through ordinary array access (`$structArray[$i]`) instead of hand-rolled
 * pointer arithmetic (see AGENTS.md).
 *
 * The element type is the generic parameter T: callers parameterize the view with the
 * generated struct stub of the element (eg `StructArray<\ZEngine\Generated\zval>` for a zval table) and
 * every read (`$structArray[$i]`, iteration) and `replace()` is typed as that shape, so
 * PHPStan carries the field types statically without any runtime assertion. T defaults to
 * FFI\CData when left unspecified.
 *
 * The view is BORROWED: the base pointer stays owned by the engine structure it was
 * read from and must outlive the view. Element pointer arithmetic follows the FFI
 * type of the base pointer, so the base must be a correctly typed CData (eg `zval *`
 * or `zend_class_entry **`).
 *
 * @internal
 *
 * @template T of object = \FFI\CData
 * @implements IteratorAggregate<int, T>
 * @implements ArrayAccess<int, T>
 */
final class StructArray implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param CData|T $baseAddress Typed pointer to the first element; the runtime value is
     *                             always the raw CData handle, a stub-typed pointer view is
     *                             accepted statically (see stubs/zend-engine-structs.php)
     * @param int     $count       Number of elements behind the pointer (negatives read as empty)
     */
    public function __construct(
        private object $baseAddress,
        private int $count,
    ) {}

    /**
     * Raw dereference of one element of a C array/pointer, returning the bare CData.
     *
     * The single-element counterpart of the instance view: for the `*ptr` / `ptr[0]`
     * deref that used to lean on the stubs' (now removed) ArrayAccess. Like the instance
     * offsetGet(), this is the one audited place a raw pointer is indexed - the generated
     * struct stubs are structs, not arrays, so callers never index them directly.
     *
     * @param CData|object $baseAddress Pointer whose runtime value is always CData; a stub-typed
     *                                  pointer view is accepted statically
     *
     * @return \FFI\CData
     */
    public static function at(object $baseAddress, int $index = 0): object
    {
        /** @var CData $element */
        $element = $baseAddress[$index];

        return $element;
    }

    /**
     * @param int $offset
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset >= 0 && $offset < $this->count;
    }

    /**
     * @param int $offset
     *
     * @return T
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        if ($offset < 0 || $offset >= $this->count) {
            throw new \OutOfBoundsException(
                "Struct array index {$offset} is out of bounds, valid range is 0..{$this->count}",
            );
        }

        // The single audited raw pointer-index in the framework: the base is a CData handle
        // at runtime (the stub views used statically do not model array access, by design -
        // they are structs, not arrays), so this one read is scoped-ignored in phpstan.dist.neon.
        /** @var T $element */
        $element = $this->baseAddress[$offset];

        return $element;
    }

    /**
     * Overwrites the element at the given index, dropping the previous value
     *
     * @param int $offset
     * @param T   $value
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->replace($offset, $value);
    }

    /**
     * @param int $offset
     */
    #[\Override]
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
     *
     * @param T $value
     *
     * @return CData The previous element value, detached as an independent byte copy
     */
    public function replace(int $position, mixed $value): object
    {
        // The slot and the incoming value are raw engine memory (T is only the static
        // element shape); the byte-level copy operates on them as plain CData
        /** @var CData $previous */
        $previous = $this[$position];
        /** @var CData $rawValue */
        $rawValue     = $value;
        $elementSize  = Core::sizeof($previous);
        $previousCopy = Core::new("char[{$elementSize}]");
        Core::memcpy($previousCopy, $previous, $elementSize);
        Core::memcpy($previous, $rawValue, $elementSize);

        return $previousCopy;
    }

    #[\Override]
    public function count(): int
    {
        return max($this->count, 0);
    }

    /**
     * @return Traversable<int, T>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        for ($index = 0; $index < $this->count; $index++) {
            yield $index => $this[$index];
        }
    }
}
