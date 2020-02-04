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
 * Receiving hook for interface implementation
 */
class InterfaceGetsImplementedHook extends AbstractHook
{
    protected const HOOK_FIELD = 'interface_gets_implemented';

    /**
     * Interface type that is implemented
     */
    protected CData $interfaceType;

    /**
     * Class that implements interface
     */
    protected CData $classType;

    /**
     * int (*interface_gets_implemented)(zend_class_entry *iface, zend_class_entry *class_type);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        [$this->interfaceType, $this->classType] = $rawArguments;

        $result = ($this->userHandler)($this);

        return $result;
    }

    /**
     * Returns a class that implements interface
     */
    public function getClass(): ReflectionClass
    {
        return ReflectionClass::fromCData($this->classType);
    }

    /**
     * Proceeds with default handler
     */
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        $result = ($this->originalHandler)($this->interfaceType, $this->classType);

        return $result;
    }
}
