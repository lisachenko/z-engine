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
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for object field write operation
 */
class WritePropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'write_property';

    /**
     * Value to write
     */
    protected CData $value;

    /**
     * typedef zval *(*zend_object_write_property_t)(zend_object *object, zend_string *member, zval *value, void **cache_slot);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): CData
    {
        [$this->object, $this->member, $this->value, $this->cacheSlot] = $rawArguments;

        $result = ($this->userHandler)($this);
        ReflectionValue::fromValueEntry($this->value)->setNativeValue($result);

        return $this->proceed();
    }

    /**
     * Returns value to write
     */
    public function getValue()
    {
        ReflectionValue::fromValueEntry($this->value)->getNativeValue($value);

        return $value;
    }

    /**
     * Returns value to write
     *
     * @param mixed $newValue Value to set
     */
    public function setValue($newValue)
    {
        ReflectionValue::fromValueEntry($this->value)->setNativeValue($newValue);
    }

    /**
     * Proceeds with default handler
     */
    protected function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->originalHandler;

        $object    = $this->object;
        $member    = $this->member;
        $value     = $this->value;
        $cacheSlot = $this->cacheSlot;

        $previousScope = Core::$executor->setFakeScope($object->ce);
        $result        = ($originalHandler)($object, $member, $value, $cacheSlot);
        Core::$executor->setFakeScope($previousScope);

        return $result;
    }
}
