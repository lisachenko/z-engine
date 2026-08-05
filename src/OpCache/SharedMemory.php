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

namespace ZEngine\OpCache;

use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

/**
 * Entry point for opcache shared-memory concerns.
 *
 * Opcache publishes cached scripts from shared memory and marks their class
 * entries and functions ZEND_ACC_IMMUTABLE. That memory is visible to every
 * worker process of the pool, so z-engine never writes it in place and never
 * frees it; a mutation targeting an immutable structure is either copied out
 * of SHM first (immutable global functions, handled inside
 * ReflectionFunction::redefine()) or rejected with {@see SharedMemoryException}.
 *
 * This facade centralizes the detection side. Each check delegates to the
 * reflection object that owns the underlying engine struct (AGENTS.md: struct
 * access lives on the owning wrapper), so callers reason about "is this in
 * shared memory?" without touching engine flags directly. It is also the
 * intended home for the future SHM-loading path, which will connect a patched
 * cache binary ({@see BinaryCacheFile}) with the running accelerator.
 */
final class SharedMemory
{
    /**
     * Whether the function/method entry lives in opcache shared memory
     * (ZEND_ACC_IMMUTABLE). Only user functions can be shared; internal
     * functions live in persistent process memory.
     */
    public static function isImmutableFunction(ReflectionFunction|ReflectionMethod $function): bool
    {
        return $function->isImmutable();
    }

    /**
     * Whether the class entry lives in opcache shared memory (ZEND_ACC_IMMUTABLE):
     * its method table, constants and default tables are shared by every worker.
     */
    public static function isImmutableClass(ReflectionClass $class): bool
    {
        return $class->isImmutable();
    }
}
