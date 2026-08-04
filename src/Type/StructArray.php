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

use Countable;
use FFI\CData;
use IteratorAggregate;
use Traversable;

/**
 * Typed view over a contiguous in-memory array of engine structures
 *
 * The engine stores many sequences as plain C arrays rather than HashTables:
 * zval tables (op_array literals, class default property/static tables), pointer
 * lists (resolved interfaces) and single-struct dereferences. Element access on
 * such arrays through raw CData is untyped; this view centralizes the bounds
 * check and resolves every element to the named object shape given by the
 * factory (see AGENTS.md "Engine structs are typed by shape"), so PHPStan works
 * with raw struct arrays in memory without call-site assertions.
 *
 * The view is BORROWED: the base pointer stays owned by the engine structure it
 * was read from and must outlive the view. Element pointer arithmetic follows
 * the FFI type of the base pointer, so the base must be a correctly typed CData
 * (eg `zval *` or `zend_class_entry **`).
 *
 * @internal
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
final class StructArray implements Countable, IteratorAggregate
{
    /**
     * @param CData       $baseAddress Typed pointer to the first element
     * @param int<0, max> $count       Number of elements behind the pointer
     */
    private function __construct(
        private CData $baseAddress,
        private int $count,
    ) {}

    /**
     * Typed view over an engine zval table (literals, default property tables)
     *
     * @param CData       $zvalTable zval* base pointer
     * @param int<0, max> $count     Number of zval slots
     *
     * @return self<ZvalShape>
     */
    public static function ofZvals(CData $zvalTable, int $count): self
    {
        /** @var self<ZvalShape> $view */
        $view = new self($zvalTable, $count);

        return $view;
    }

    /**
     * Raw view over an array of structures or pointers (interface lists, single
     * struct dereferences): elements stay plain CData handles
     *
     * @param CData       $structTable Typed base pointer
     * @param int<0, max> $count       Number of elements behind the pointer
     *
     * @return self<CData>
     */
    public static function ofStructs(CData $structTable, int $count): self
    {
        /** @var self<CData> $view */
        $view = new self($structTable, $count);

        return $view;
    }

    /**
     * Returns the element at the given index, resolved to the element shape
     *
     * @return T
     */
    public function at(int $index): object
    {
        /** @var T $element */
        $element = $this->asStructView($this->rawAt($index));

        return $element;
    }

    /**
     * Widens a CData handle to plain `object` so the element shape @var can be
     * declared on it (FFI\CData is final and cannot intersect object shapes)
     */
    private function asStructView(CData $element): object
    {
        return $element;
    }

    /**
     * Returns the element at the given index as the raw CData handle
     *
     * Use this form when the element travels into FFI primitives that require
     * CData arguments (Core::addr()/memcpy()/cast()).
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
        return $this->count;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        for ($index = 0; $index < $this->count; $index++) {
            yield $index => $this->at($index);
        }
    }
}
