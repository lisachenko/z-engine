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
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for intercepting `clone $object` (deep clone, copy-on-write, clone bans)
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - handle() returns the zend_object* of the object produced by the user handler with
 *    exactly one reference transferred to the engine: the CLONE opcode stores the returned
 *    pointer into its result operand without an addref, so the reference taken here is
 *    consumed by the VM. The temporary zval container is freed right away.
 *  - proceed() invokes the original clone_obj handler, which hands back a fresh clone
 *    carrying one reference. That reference is exchanged for a PHP-level one owned by the
 *    returned object value, so returning the clone from the user handler (or dropping it)
 *    keeps the refcount balanced either way.
 *  - The user handler must not let exceptions escape: handle() is entered by the engine
 *    through an FFI trampoline with no PHP frame around it to catch them (see issue #50).
 */
class CloneObjectHook extends AbstractHook
{
    protected const HOOK_FIELD = 'clone_obj';

    /**
     * Object instance being cloned
     */
    protected CData $object;

    /**
     * typedef zend_object* (*zend_object_clone_obj_t)(zend_object *object);
     *
     * @inheritDoc
     * @return \FFI\CData
     */
    public function handle(...$rawArguments): object
    {
        [$object] = $rawArguments;
        assert($object instanceof CData);
        $this->object = $object;

        $result = ($this->userHandler)($this);

        // The engine stores the returned zend_object* into the result operand without an
        // addref, so the reference this temporary wrapper took is transferred to the VM;
        // the temporary zval container is freed right away
        $refValue  = new ReflectionValue($result);
        $rawObject = $refValue->getRawObject();
        $refValue->transferReferenceOwnership();
        $refValue->release();

        return $rawObject;
    }

    /**
     * Returns the object instance being cloned
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Proceeds with the default field-copy clone from the original handler
     */
    public function proceed(): object
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }
        $originalHandler = $this->getOriginalCallable();

        $rawClone = ($originalHandler)($this->object);
        assert($rawClone instanceof CData);

        // The original handler handed us a clone with one reference. Materializing the
        // PHP object takes an own reference, so the handler's one is released afterwards:
        // the only remaining reference is owned by the returned PHP value
        $cloneEntry = ObjectEntry::fromCData($rawClone);
        $clone      = $cloneEntry->getNativeValue();
        $cloneEntry->releaseReference();

        return $clone;
    }
}
