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
        $closureEntry = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
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
     * <span style="color:red; font-weight: bold">Warning!</span> Given object should live more than closure itself!
     * @param object $object New object
     */
    public function setThis(object $object): void
    {
        $selfExecutionState = Core::$executor->getExecutionState();
        $objectArgument     = $selfExecutionState->getArgument(0);
        $objectZval         = $objectArgument->getRawValue();
        Core::memcpy($this->pointer->this_ptr, $objectZval[0], Core::sizeof(Core::type('zval')));
    }

    /**
     * Returns raw zend_function data for this closure
     */
    public function getRawFunction(): CData
    {
        return $this->pointer->func;
    }
}
