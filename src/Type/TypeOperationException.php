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

    /**
     * Raised when a release path is asked to free memory a foreign allocator owns
     */
    public static function externallyAllocatedTable(): self
    {
        return new self(
            'This hashtable was built on a foreign allocator: its memory belongs to that '
            . 'allocator and only its owner may release it',
        );
    }

    /**
     * Raised when a release path is asked to free externally installed bucket storage
     */
    public static function externalStorageInstalled(): self
    {
        return new self(
            'This hashtable stores its buckets in externally allocated memory: releasing it '
            . 'here would free a block z-engine does not own',
        );
    }

    /**
     * Raised when external storage is installed on a table the engine has already initialized
     */
    public static function storageAlreadyInitialized(): self
    {
        return new self(
            'External bucket storage can only be installed on an untouched table, before the '
            . 'first insert makes the engine allocate storage of its own',
        );
    }

    /**
     * Raised when the address of an external bucket storage block is zero
     */
    public static function invalidStorageAddress(): self
    {
        return new self('The address of an external bucket storage block must not be zero');
    }

    /**
     * Raised when a bucket capacity is not one the engine can address
     */
    public static function invalidStorageCapacity(int $capacity, int $minimalCapacity): self
    {
        return new self(
            "A bucket capacity must be a power of two of at least {$minimalCapacity}, {$capacity} given",
        );
    }

    /**
     * Raised when an insert would make the engine grow externally allocated storage
     */
    public static function storageCapacityExhausted(int $capacity): self
    {
        return new self(
            "All {$capacity} bucket slots of the external storage are used: another insert would "
            . 'make the engine reallocate a block it does not own',
        );
    }

    /**
     * Raised when the engine has moved the bucket storage of an externally backed table
     */
    public static function storageRelocated(): self
    {
        return new self(
            'The engine has reallocated the bucket storage of a table backed by external memory: '
            . 'the installed block is no longer the one in use',
        );
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
