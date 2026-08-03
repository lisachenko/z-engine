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

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Type\HashTable;

/**
 * Base for hooks that resolve a method for the engine (get_method, get_constructor)
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The engine expects these handlers to return a BORROWED zend_function*: the standard
 *    handlers return entries owned by the class method table and perform no refcounting.
 *    resolveRawFunction() therefore only ever hands out pointers borrowed from a class
 *    entry (or the exact pointer the original handler returned) - never a copy that
 *    somebody would have to free, and never a pointer that could be freed underneath the VM.
 *  - proceed() results are identity-tracked: returning the wrapper produced by proceed()
 *    from the user handler reuses the raw pointer of the original handler unchanged, so
 *    engine-managed special functions (e.g. __call trampolines, which the VM releases
 *    itself after the call) round-trip safely.
 */
abstract class AbstractMethodResolutionHook extends AbstractHook
{
    /**
     * Most recent wrapper produced by proceed(), tracked by identity
     */
    protected ?ReflectionMethod $proceedResult = null;

    /**
     * Raw zend_function pointer behind $proceedResult
     */
    protected ?CData $proceedRawFunction = null;

    /**
     * Wraps a raw zend_function* handed back by the original handler
     */
    final protected function wrapRawFunction(CData $rawFunction): ReflectionMethod
    {
        $wrapper = ReflectionMethod::fromCData($rawFunction);

        $this->proceedResult      = $wrapper;
        $this->proceedRawFunction = $rawFunction;

        return $wrapper;
    }

    /**
     * Resolves a userland ReflectionMethod back to a borrowed zend_function pointer
     *
     * The wrapper returned by proceed() resolves to the exact pointer the original handler
     * produced; any other reflection is resolved through the method table of its class
     * entry, which owns the returned function for the whole class lifetime.
     */
    final protected function resolveRawFunction(\ReflectionMethod $method): CData
    {
        if ($this->proceedRawFunction !== null && $method === $this->proceedResult) {
            return $this->proceedRawFunction;
        }

        $className       = $method->class;
        $classEntryValue = Core::$executor->classTable->find(strtolower($className));
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine");
        }
        $functionTable = $classEntryValue->getRawClass()->function_table;
        assert($functionTable instanceof CData);
        $methodTable      = HashTable::fromCData(Core::addr($functionTable));
        $methodEntryValue = $methodTable->find(strtolower($method->name));
        if ($methodEntryValue === null) {
            throw new \ReflectionException("Method {$className}::{$method->name} was not found in the class");
        }

        return $methodEntryValue->getRawFunction();
    }
}
