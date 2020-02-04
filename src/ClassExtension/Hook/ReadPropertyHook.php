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
use ZEngine\Reflection\ReflectionValue;

/**
 * Receiving hook for object field read operation
 */
class ReadPropertyHook extends AbstractHook
{
    protected const HOOK_FIELD = 'read_property';

    /**
     * Object instance
     */
    protected CData $object;

    /**
     * Member name
     */
    protected CData $member;

    /**
     * Hook access type
     */
    protected int $type;

    /**
     * Internal cache slot (for native callback only)
     */
    private ?CData $cacheSlot;

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
     * Returns an object instance
     */
    public function getObject(): object
    {
        ReflectionValue::fromValueEntry($this->object)->getNativeValue($objectInstance);

        return $objectInstance;
    }

    /**
     * Returns a member name
     */
    public function getMemberName(): string
    {
        ReflectionValue::fromValueEntry($this->member)->getNativeValue($memberName);

        return $memberName;
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

        $result = ($this->originalHandler)($this->object, $this->member, $this->type, $this->cacheSlot, $this->rv);

        ReflectionValue::fromValueEntry($result)->getNativeValue($phpResult);

        return $phpResult;
    }
}
