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
use ZEngine\Generated\zend_object;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for constructor resolution during `new` (factories, sealed classes, ...)
 *
 * The NEW opcode consults this handler right after the object is allocated: returning null
 * makes the VM skip the constructor call entirely (the object stays as create_object left
 * it), returning a method makes the VM invoke it with the constructor arguments.
 *
 * The user handler must not let exceptions escape: handle() is entered by the engine
 * through an FFI trampoline with no PHP frame around it to catch them (see issue #50).
 */
class GetConstructorHook extends AbstractMethodResolutionHook
{
    protected const HOOK_FIELD = 'get_constructor';

    /**
     * Object instance being constructed
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * typedef zend_function *(*zend_object_get_constructor_t)(zend_object *object);
     *
     * @inheritDoc
     * @return \FFI\CData|null
     */
    public function handle(...$rawArguments): ?object
    {
        /** @var zend_object $object Narrowed to the stub view at the engine callback boundary */
        [$object]     = $rawArguments;
        $this->object = $object;

        $result = ($this->userHandler)($this);
        if ($result === null) {
            // No constructor: the NEW opcode skips the constructor call entirely
            return null;
        }
        assert($result instanceof \ReflectionMethod);

        return $this->resolveRawFunction($result);
    }

    /**
     * Returns the object instance being constructed
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Proceeds with the original handler and returns the engine-resolved constructor
     *
     * Returns null when the class has no constructor (the engine then skips the call).
     */
    public function proceed(): ?ReflectionMethod
    {
        $originalHandler = $this->getOriginalCallable();

        $rawFunction = ($originalHandler)($this->object);
        if ($rawFunction === null) {
            return null;
        }
        assert($rawFunction instanceof CData);

        return $this->wrapRawFunction($rawFunction);
    }
}
