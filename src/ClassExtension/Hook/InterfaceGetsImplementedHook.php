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

use ZEngine\Generated\zend_class_entry;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionClass;

/**
 * Receiving hook for interface implementation
 */
final class InterfaceGetsImplementedHook extends AbstractHook
{
    protected const HOOK_FIELD = 'interface_gets_implemented';

    /**
     * Interface type that is implemented
     *
     * @var zend_class_entry Typed view of the engine handle; the runtime value is the raw
     *                       FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $interfaceType;

    /**
     * Class that implements interface
     *
     * @var zend_class_entry Typed view of the engine handle; the runtime value is the raw
     *                       FFI\CData pointer
     */
    protected object $classType;

    /**
     * int (*interface_gets_implemented)(zend_class_entry *iface, zend_class_entry *class_type);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_class_entry $interfaceType Narrowed to the stub views at the engine callback boundary
         * @var zend_class_entry $classType
         */
        [$interfaceType, $classType] = $rawArguments;
        $this->interfaceType         = $interfaceType;
        $this->classType             = $classType;

        $result = ($this->userHandler)($this);
        assert(is_int($result));

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
     *
     * @return int Core::SUCCESS when the original handler accepted the implementation,
     *             Core::FAILURE when it rejected it
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->interfaceType, $this->classType);
        assert(is_int($result));

        return $result;
    }
}
