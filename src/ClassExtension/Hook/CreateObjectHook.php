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
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionClass;

/**
 * Receiving hook for performing operation on object
 */
class CreateObjectHook extends AbstractHook
{
    protected const HOOK_FIELD = 'create_object';

    private CData $classType;

    /**
     * Returns a raw class type (zend_class_entry)
     */
    public function getClassType(): CData
    {
        return $this->classType;
    }

    /**
     * Changes a class type to create
     */
    public function setClassType(CData $classType): void
    {
        $this->classType = $classType;
    }

    /**
     * zend_object* (*create_object)(zend_class_entry *class_type);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): CData
    {
        [$this->classType] = $rawArguments;

        return ($this->userHandler)($this);
    }

    /**
     * Proceeds with object creation
     */
    public function proceed()
    {
        if ($this->originalHandler === null) {
            $object = ReflectionClass::newInstanceRaw($this->classType);
        } else {
            $object = ($this->originalHandler)($this->classType);
        }

        return $object;
    }
}
