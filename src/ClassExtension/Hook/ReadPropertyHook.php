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
 * Receiving hook for object field read operation
 */
final class ReadPropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'read_property';

    /**
     * Hook access type
     */
    protected int $type;

    /**
     * Internal pointer of retval (for native callback only)
     *
     * @var zval Engine-provided scratch slot; every VM caller supplies one
     */
    private object $rv;

    /**
     * typedef zval *(*zend_object_read_property_t)(zend_object *object, zend_string *member, int type, void **cache_slot, zval *rv);
     *
     * @inheritDoc
     * @return zval
     */
    #[\Override]
    public function handle(...$rawArguments): object
    {
        /**
         * @var zend_object $object    Narrowed to the stub views at the engine callback boundary
         * @var zend_string $member
         * @var int         $type
         * @var CData|null  $cacheSlot
         * @var zval        $rv
         */
        [$object, $member, $type, $cacheSlot, $rv] = $rawArguments;
        $this->object                              = $object;
        $this->member                              = $member;
        $this->type                                = $type;
        $this->cacheSlot                           = $cacheSlot;
        $this->rv                                  = $rv;

        $result = ($this->userHandler)($this);

        // Return the result through the engine-provided retval slot: the slot is uninitialized
        // scratch memory owned by the caller and the VM consumes the reference left in it,
        // so nothing leaks - unlike a heap zval container which nobody would ever free
        $refValue = new ReflectionValue($result);
        $refValue->copy($this->rv);
        $refValue->release();

        return $this->rv;
    }

    /**
     * Returns the access type
     */
    public function getAccessType(): int
    {
        return $this->type;
    }

    /**
     * Proceeds with default handler
     *
     * @return mixed The property value the engine handler produced, as a PHP value
     */
    public function proceed(): mixed
    {
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object    = $this->object;
        $member    = $this->member;
        $type      = $this->type;
        $cacheSlot = $this->cacheSlot;
        $rv        = $this->rv;

        $result = Core::$executor->withFakeScope(
            $object->ce,
            static fn() => ($originalHandler)($object, $member, $type, $cacheSlot, $rv),
        );
        // Engine contract: zend_std_read_property always reports a slot (&EG(uninitialized_zval)
        // on the error paths), so there is no NULL result to guard against here
        assert($result instanceof CData);

        ReflectionValue::fromValueEntry($result)->getNativeValue($phpResult);

        return $phpResult;
    }
}
