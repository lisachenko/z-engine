<?php

/**
 * Fixture compiled into the opcache file cache by the iterator relocation tests.
 *
 * Exercises the iterator_funcs_ptr and arrayaccess_funcs_ptr branches of
 * zend_file_cache_(un)serialize_class: a class implementing Iterator (all five
 * zf_* slots), one implementing IteratorAggregate (zf_new_iterator only) and
 * one implementing ArrayAccess (all four zf_offset* slots).
 */
declare(strict_types=1);

/** @implements Iterator<int, string> */
class ZEngineIteratorSteps implements Iterator
{
    /** @var list<string> */
    private array $steps = ['alpha', 'beta'];
    private int $index   = 0;

    public function current(): string
    {
        return $this->steps[$this->index];
    }

    public function key(): int
    {
        return $this->index;
    }

    public function next(): void
    {
        ++$this->index;
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function valid(): bool
    {
        return isset($this->steps[$this->index]);
    }
}

/** @implements IteratorAggregate<int, string> */
class ZEngineIteratorBag implements IteratorAggregate
{
    /** @return ArrayIterator<int, string> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator(['gamma']);
    }
}

/** @implements ArrayAccess<string, string> */
class ZEngineArrayShelf implements ArrayAccess
{
    /** @var array<string, string> */
    private array $items = [];

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): ?string
    {
        return is_string($offset) ? ($this->items[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset) && is_string($value)) {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->items[$offset]);
        }
    }
}

function zengine_bin_iterators_run(): string
{
    $collected = [];
    foreach (new ZEngineIteratorSteps() as $step) {
        $collected[] = $step;
    }
    foreach (new ZEngineIteratorBag() as $extra) {
        $collected[] = $extra;
    }

    $shelf          = new ZEngineArrayShelf();
    $shelf['delta'] = 'delta';
    $collected[]    = $shelf['delta'] ?? 'missing';
    unset($shelf['delta']);

    return implode(':', $collected) . ':it-ok';
}
