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
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for casting to array, debugging, etc
 */
class GetPropertiesForHook extends AbstractHook
{

    protected const HOOK_FIELD = 'get_properties_for';

    /**
     * Object instance
     */
    protected CData $object;

    /**
     * Calling reason
     *
     * @see zend_prop_purpose enumeration
     */
    protected int $purpose;

    /**
     * zend_array *(*zend_object_get_properties_for_t)(zend_object *object, zend_prop_purpose purpose);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments)
    {
        [$this->object, $this->purpose] = $rawArguments;

        $result   = ($this->userHandler)($this);
        $refValue = new ReflectionValue($result);

        return $refValue->getRawArray();
    }

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Returns the purpose
     */
    public function getPurpose(): int
    {
        return $this->purpose;
    }

    /**
     * Proceeds with default handler
     */
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->originalHandler;

        $object  = $this->object;
        $purpose = $this->purpose;

        $previousScope = Core::$executor->setFakeScope($object->ce);
        $result        = ($originalHandler)($object, $purpose);
        Core::$executor->setFakeScope($previousScope);

        return $result;
    }
}
