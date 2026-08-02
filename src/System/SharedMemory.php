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

namespace ZEngine\System;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;

/**
 * Central authority for opcache shared-memory (SHM) detection and copy-out
 *
 * Opcache stores cached scripts in shared memory and marks every class entry and
 * function it publishes from there with ZEND_ACC_IMMUTABLE. Two hard rules follow
 * for every mutation API (see docs/hot-swap.md for the support matrix):
 *
 *  1. SHM structures are never written: they are shared by all worker processes.
 *  2. SHM structures are never freed: they belong to the opcache, not the request.
 *
 * Where the engine keeps a writable per-process indirection, a mutation can proceed
 * after copying the structure out of SHM: the global function table buckets are
 * per-process, so an immutable function entry can be repointed at a writable copy
 * (copyOutFunctionEntry()). A class method table, however, lives inside the SHM
 * class entry itself - there is no writable slot to repoint, so class-level
 * mutations on immutable classes are rejected with SharedMemoryException.
 */
final class SharedMemory
{
    /**
     * This is an utility class, no instances needed
     */
    private function __construct() {}

    /**
     * Checks if the given zend_class_entry lives in opcache shared memory
     */
    public static function isImmutableClassEntry(CData $classEntry): bool
    {
        $classFlags = $classEntry->ce_flags;
        assert(is_int($classFlags));

        return ($classFlags & Core::ZEND_ACC_IMMUTABLE) !== 0;
    }

    /**
     * Checks if the given zend_function entry lives in opcache shared memory
     *
     * Only user functions can be opcache-shared; internal functions are persistent
     * process memory, which is a different lifetime class entirely.
     */
    public static function isImmutableFunctionEntry(CData $functionEntry): bool
    {
        $entryType = $functionEntry->type;
        assert(is_int($entryType));
        if (($entryType & Core::ZEND_USER_FUNCTION) === 0) {
            return false;
        }
        $commonPointer = $functionEntry->common;
        assert($commonPointer instanceof CData);
        $functionFlags = $commonPointer->fn_flags;
        assert(is_int($functionFlags));

        return ($functionFlags & Core::ZEND_ACC_IMMUTABLE) !== 0;
    }

    /**
     * Refuses a mutation on an opcache-shared class entry
     *
     * @param string $operation Human-readable operation name for the diagnostic
     *
     * @throws SharedMemoryException When the class entry is stored in opcache SHM
     */
    public static function assertMutableClassEntry(CData $classEntry, string $operation): void
    {
        if (self::isImmutableClassEntry($classEntry)) {
            throw new SharedMemoryException(
                "Cannot {$operation} on an immutable (opcache shared-memory) class: "
                . 'the class entry is shared by all worker processes and cannot be modified in place',
            );
        }
    }

    /**
     * Copies an opcache-shared function entry out of SHM into a writable container
     * and repoints the given per-process table bucket at the copy
     *
     * The SHM original is left completely untouched (never written, never freed).
     * The writable container is a malloc-backed immortal-by-design block (see
     * docs/long-running.md): the engine's function table destructor releases the
     * body it will eventually carry but never frees user zend_function containers,
     * and a request-lifetime block would dangle if the engine walked it after the
     * FFI request memory was reclaimed.
     *
     * @param CData                        $functionEntry zend_function pointer into SHM
     * @param HashTable|ReflectionValue[]  $table         Function table whose bucket should be repointed
     * @param string                       $lowerKey      Lowercased bucket key of the entry
     *
     * @return CData Writable zend_function pointer now published in the table
     */
    public static function copyOutFunctionEntry(CData $functionEntry, HashTable $table, string $lowerKey): CData
    {
        $bucketValue = $table->find($lowerKey);
        if ($bucketValue === null) {
            throw new SharedMemoryException(
                "Cannot copy out function {$lowerKey}: it is not published in the given table",
            );
        }

        $writableEntry = Core::trackedNew('zend_function', true);
        Core::memcpy($writableEntry, $functionEntry, Core::sizeof($writableEntry));

        // The writable copy is not opcache-shared anymore; everything it points at
        // still is, so the body must be replaced (not freed) by the caller
        $commonPointer = $writableEntry->common;
        assert($commonPointer instanceof CData);
        $entryFlags = $commonPointer->fn_flags;
        assert(is_int($entryFlags));
        $commonPointer->fn_flags = $entryFlags & (~Core::ZEND_ACC_IMMUTABLE);

        // Repoint the per-process bucket: the zval value is an IS_PTR payload
        $rawBucketZval  = $bucketValue->getRawValue();
        $rawBucketValue = $rawBucketZval->value;
        assert($rawBucketValue instanceof CData);
        $rawBucketValue->ptr = Core::cast('void *', Core::addr($writableEntry));

        return Core::cast('zend_function *', Core::addr($writableEntry));
    }
}
