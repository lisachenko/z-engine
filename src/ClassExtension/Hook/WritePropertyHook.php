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
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zval;
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for object field write operation
 */
final class WritePropertyHook extends AbstractPropertyHook
{
    protected const string HOOK_FIELD = 'write_property';

    /**
     * Value to write
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $value;

    /**
     * typedef zval *(*zend_object_write_property_t)(zend_object *object, zend_string *member, zval *value, void **cache_slot);
     *
     * @inheritDoc
     * @return \FFI\CData
     */
    #[\Override]
    public function handle(...$rawArguments): object
    {
        /**
         * @var zend_object $object    Narrowed to the stub views at the engine callback boundary
         * @var zend_string $member
         * @var zval        $value
         * @var CData|null  $cacheSlot
         */
        [$object, $member, $value, $cacheSlot] = $rawArguments;
        $this->object                          = $object;
        $this->member                          = $member;
        $this->value                           = $value;
        $this->cacheSlot                       = $cacheSlot;

        $result = ($this->userHandler)($this);
        ReflectionValue::fromValueEntry($this->value)->setNativeValue($result);

        return $this->proceed();
    }

    /**
     * Returns value to write
     */
    public function getValue(): mixed
    {
        ReflectionValue::fromValueEntry($this->value)->getNativeValue($value);

        return $value;
    }

    /**
     * Returns value to write
     *
     * @param mixed $newValue Value to set
     */
    public function setValue($newValue): void
    {
        ReflectionValue::fromValueEntry($this->value)->setNativeValue($newValue);
    }

    /**
     * Proceeds with default handler
     *
     * The engine write_property contract guarantees a non-NULL zval*: the standard handler
     * reports either the written property slot or &EG(error_zval).
     *
     * @return \FFI\CData The value slot the engine handler wrote into
     */
    protected function proceed(): object
    {
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object    = $this->object;
        $member    = $this->member;
        $value     = $this->value;
        $cacheSlot = $this->cacheSlot;

        $writtenSlot = Core::$executor->withFakeScope(
            $object->ce,
            static fn() => ($originalHandler)($object, $member, $value, $cacheSlot),
        );
        assert($writtenSlot instanceof CData);

        return $writtenSlot;
    }
}
