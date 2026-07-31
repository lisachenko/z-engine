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

use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ZEngine\Core;

/**
 * Class ClosureEntry
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The wrapper itself owns nothing: the constructor and fromCData() are BORROWED views,
 *    the caller must keep the closure object alive while the entry is used. Lifetime
 *    control goes through the closure's object header - getClosureObjectEntry() exposes it
 *    as a (borrowed) ObjectEntry.
 *  - setThis() has full ZVAL_COPY semantics: it releases the previously bound $this and
 *    takes a closure-owned reference on the new object, so the caller does NOT have to
 *    keep the bound object alive - the engine releases it with the closure.
 *  - setCalledScope() performs no refcounting: class entries are not refcounted values.
 *  - getRawFunction() returns the zend_function EMBEDDED in the closure object: anything
 *    that stores this pointer (eg a method table) must guarantee the closure object
 *    outlives that structure - ReflectionClass::addMethod() immortalizes the closure for
 *    exactly this reason.
 *
 * typedef struct _zend_closure {
 *   zend_object       std;
 *   zend_function     func;
 *   zval              this_ptr;
 *   zend_class_entry *called_scope;
 *   zif_handler       orig_internal_handler;
 * } zend_closure;
 */
class ClosureEntry
{
    private CData $pointer;

    public function __construct(\Closure $closure)
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $closureEntry       = $selfExecutionState->getArgument(0)->getRawObject();
        $this->pointer      = Core::cast('zend_closure *', $closureEntry);
    }

    /**
     * Creates a closure entry from the zend_closure structure
     *
     * @param CData $pointer Pointer to the structure
     */
    public static function fromCData(CData $pointer): ClosureEntry
    {
        /** @var ClosureEntry $closureEntry */
        $closureEntry          = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $closureEntry->pointer = $pointer;

        return $closureEntry;
    }

    /**
     * Returns a raw object that represents this closure
     */
    public function getClosureObjectEntry(): ObjectEntry
    {
        return ObjectEntry::fromCData($this->pointer->std);
    }

    /**
     * Returns the called scope (if present), otherwise null for unbound closures
     */
    public function getCalledScope(): ?string
    {
        if ($this->pointer->called_scope === null) {
            return null;
        }

        $calledScopeName = StringEntry::fromCData($this->pointer->called_scope->name);

        return $calledScopeName->getStringValue();
    }

    /**
     * Changes the scope of closure to another one
     * @internal
     */
    public function setCalledScope(?string $newScope): void
    {
        // If we have a null value, then just clean this scope internally
        if ($newScope === null) {
            $this->pointer->called_scope = null;
            return;
        }

        $name = strtolower($newScope);

        $classEntryValue = Core::$executor->classTable->find($name);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$newScope} was not found");
        }
        $this->pointer->called_scope = $classEntryValue->getRawClass();
    }

    /**
     * Changes the current $this, bound to the closure
     *
     * The previous bound object (if any) is released and the closure takes its own reference
     * on the new one, exactly like the engine's ZVAL_COPY - the caller no longer has to keep
     * the object alive for the closure lifetime.
     *
     * @param object $object New object
     *
     * @internal
     */
    public function setThis(object $object): void
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $objectArgument     = $selfExecutionState->getArgument(0);
        $objectZval         = $objectArgument->getRawValue();

        $thisPtr = Core::addr($this->pointer->this_ptr);
        // Release the previously bound $this (safe no-op for unbound IS_UNDEF/IS_NULL closures)
        Core::call('zval_ptr_dtor', $thisPtr);
        Core::memcpy($this->pointer->this_ptr, $objectZval[0], Core::sizeof(Core::type('zval')));
        // The closure now holds its own reference on the new $this
        Core::call('zval_add_ref', $thisPtr);
    }

    /**
     * Returns raw zend_function data for this closure
     */
    public function getRawFunction(): CData
    {
        return $this->pointer->func;
    }
}
