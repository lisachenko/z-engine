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

namespace ZEngine\Type;

/**
 * Raised when an operation on a Type-layer wrapper is refused by the engine: a hashtable
 * bucket that could not be added, stored or deleted, a value entry that did not appear in
 * the table it was published into, a dangling wrapper over destroyed engine memory or a
 * reference counter that would go below zero.
 *
 * These are engine-state failures, not argument validation: the call was well-formed and
 * the engine still said no, so they extend \RuntimeException exactly like the inline
 * throws they replace - every existing `catch (\RuntimeException)` keeps matching.
 *
 * Every failure mode has a named static constructor (project convention, see AGENTS.md).
 */
class TypeOperationException extends \RuntimeException
{
    public static function cannotAddKey(string $key): self
    {
        return new self("Can not add an item with key {$key}");
    }

    public static function cannotAddIndex(int $key): self
    {
        return new self("Can not add an item with index {$key}");
    }

    public static function cannotStoreKey(string $key): self
    {
        return new self("Can not store an item with key {$key}");
    }

    public static function cannotStoreIndex(int $key): self
    {
        return new self("Can not store an item with index {$key}");
    }

    public static function cannotDeleteKey(string $key): self
    {
        return new self("Can not delete an item with key {$key}");
    }

    public static function cannotDeleteIndex(int $key): self
    {
        return new self("Can not delete an item with index {$key}");
    }

    public static function functionNotPublished(string $key): self
    {
        return new self("Function {$key} was not published in the table");
    }

    public static function danglingObjectEntry(): self
    {
        return new self('The underlying object has been destroyed, this entry is dangling');
    }

    public static function referenceCountUnderflow(): self
    {
        return new self('Reference counter underflow: the value has already been released');
    }
}
