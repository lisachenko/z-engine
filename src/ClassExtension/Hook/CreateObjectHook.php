<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionClass;

/**
 * Receiving hook for performing operation on object
 */
final class CreateObjectHook extends AbstractHook
{
    protected const HOOK_FIELD = 'create_object';

    /**
     * Class entry the object is created for
     *
     * @var zend_class_entry Typed view of the engine handle; the runtime value is the raw
     *                       FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    private object $classType;

    /**
     * Returns a raw class type (zend_class_entry)
     *
     * @return zend_class_entry
     */
    public function getClassType(): object
    {
        return $this->classType;
    }

    /**
     * Changes a class type to create
     *
     * @param \FFI\CData|zend_class_entry $classType
     */
    public function setClassType(object $classType): void
    {
        /** @var zend_class_entry $classType Narrowed to the stub view at the owning boundary */
        $this->classType = $classType;
    }

    /**
     * zend_object* (*create_object)(zend_class_entry *class_type);
     *
     * @inheritDoc
     * @return \FFI\CData
     */
    public function handle(...$rawArguments): object
    {
        /** @var zend_class_entry $classType Narrowed to the stub view at the engine callback boundary */
        [$classType]     = $rawArguments;
        $this->classType = $classType;

        $rawObject = ($this->userHandler)($this);
        assert($rawObject instanceof CData);

        return $rawObject;
    }

    /**
     * Proceeds with object creation
     *
     * @return \FFI\CData
     */
    public function proceed(): object
    {
        if (!$this->hasOriginalHandler()) {
            $object = ReflectionClass::newInstanceRaw($this->classType);
        } else {
            $originalHandler = $this->getOriginalCallable();
            $object          = ($originalHandler)($this->classType);
            assert($object instanceof CData);
        }

        return $object;
    }
}
