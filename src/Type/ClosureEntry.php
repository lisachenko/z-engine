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
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_closure;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zval;

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
    /**
     * @var zend_closure Typed view of the owned engine structure; the runtime value is
     *                   the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

    /**
     * Creates an entry over the engine structure backing the given closure
     *
     * The value is deliberately never named in the body: it is read back out of the
     * engine frame this very constructor runs in (argument slot 0), which is the only way
     * to reach the caller's own zval instead of a copy. Removing the parameter would
     * remove the zval the constructor is built to capture.
     *
     * @phpstan-ignore constructor.unusedParameter (captured from the frame's argument slot 0)
     */
    public function __construct(\Closure $closure)
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $closureEntry       = $selfExecutionState->getArgument(0)->getRawObject();
        $this->pointer      = Core::cast(zend_closure::class, $closureEntry);
    }

    /**
     * Creates a closure entry from the zend_closure structure
     *
     * @param CData|zend_closure $pointer Pointer to the structure
     */
    public static function fromCData(object $pointer): ClosureEntry
    {
        /** @var ClosureEntry $closureEntry */
        $closureEntry = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_closure $pointer Narrowed to the stub view at the owning boundary */
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
        $calledScope = $this->pointer->called_scope;
        if ($calledScope === null) {
            return null;
        }
        // Engine invariant: every registered class entry carries a name
        $scopeName = $calledScope->name;
        assert($scopeName !== null);

        return StringEntry::fromCData($scopeName)->getStringValue();
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
        $this->pointer->called_scope = Core::cast(zend_class_entry::class, $classEntryValue->getRawClass());
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
        Core::memcpy($this->pointer->this_ptr, StructArray::at($objectZval), Core::sizeOfType(zval::class));
        // The closure now holds its own reference on the new $this
        Core::call('zval_add_ref', $thisPtr);
    }

    /**
     * Returns raw zend_function data for this closure
     *
     * @return zend_function
     */
    public function getRawFunction(): object
    {
        return $this->pointer->func;
    }
}
