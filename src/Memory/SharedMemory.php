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

namespace ZEngine\Memory;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Type\HashTable;

/**
 * Shared detection and copy-out mechanics for opcache shared memory (SHM)
 *
 * Opcache stores cached scripts in shared memory and marks every class entry and
 * function it publishes from there with ZEND_ACC_IMMUTABLE. Two hard rules follow
 * for every mutation API (see docs/hot-swap.md for the support matrix):
 *
 *  1. SHM structures are never written: they are shared by all worker processes.
 *  2. SHM structures are never freed: they belong to the opcache, not the request.
 *
 * The reflection wrappers expose the detection as ReflectionClass::isImmutable()
 * and ReflectionFunction/ReflectionMethod::isImmutable(); both delegate to the
 * mechanics kept here. Where the engine keeps a writable per-process indirection,
 * a mutation can proceed after copying the structure out of SHM: the global
 * function table buckets are per-process, so an immutable function entry can be
 * repointed at a writable copy (copyOutFunctionEntry()). A class method table,
 * however, lives inside the SHM class entry itself - there is no writable slot to
 * repoint, so class-level mutations on immutable classes are rejected with
 * SharedMemoryException.
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
        return ReflectionClass::fromCData($classEntry)->isImmutable();
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
            throw SharedMemoryException::immutableClassMutation($operation);
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
     * @param CData     $functionEntry zend_function pointer into SHM
     * @param HashTable $table         Function table whose bucket should be repointed
     * @param string    $lowerKey      Lowercased bucket key of the entry
     *
     * @return CData Writable zend_function pointer now published in the table
     */
    public static function copyOutFunctionEntry(CData $functionEntry, HashTable $table, string $lowerKey): CData
    {
        $bucketValue = $table->find($lowerKey);
        if ($bucketValue === null) {
            throw SharedMemoryException::functionNotPublished($lowerKey);
        }

        $writableEntry = Core::trackedNew('zend_function', true);
        Core::memcpy($writableEntry, $functionEntry, Core::sizeof($writableEntry));

        // The writable copy is not opcache-shared anymore; everything it points at
        // still is, so the body must be replaced (not freed) by the caller
        $writablePointer = Core::cast('zend_function *', Core::addr($writableEntry));
        $commonPointer   = ReflectionFunction::fromCData($writablePointer)->getCommonPointer();
        $commonPointer->fn_flags &= (~Core::ZEND_ACC_IMMUTABLE);

        // Repoint the per-process bucket: the zval value is an IS_PTR payload
        $bucketValue->getZvalShape()->value->ptr = Core::cast('void *', Core::addr($writableEntry));

        return $writablePointer;
    }
}
