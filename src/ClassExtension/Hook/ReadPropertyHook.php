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
 * Receiving hook for object field read operation
 */
class ReadPropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'read_property';

    /**
     * Hook access type
     */
    protected int $type;

    /**
     * Internal pointer of retval (for native callback only)
     */
    private ?CData $rv;

    /**
     * typedef zval *(*zend_object_read_property_t)(zval *object, zval *member, int type, void **cache_slot, zval *rv);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): CData
    {
        [$this->object, $this->member, $this->type, $this->cacheSlot, $this->rv] = $rawArguments;

        $result   = ($this->userHandler)($this);
        $refValue = new ReflectionValue($result);

        return $refValue->getRawValue();
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
     */
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->originalHandler;

        $object    = $this->object;
        $member    = $this->member;
        $type      = $this->type;
        $cacheSlot = $this->cacheSlot;
        $rv        = $this->rv;

        $previousScope = Core::$executor->setFakeScope($object->value->obj->ce);
        $result        = ($originalHandler)($object, $member, $type, $cacheSlot, $rv);
        Core::$executor->setFakeScope($previousScope);

        ReflectionValue::fromValueEntry($result)->getNativeValue($phpResult);

        return $phpResult;
    }
}
