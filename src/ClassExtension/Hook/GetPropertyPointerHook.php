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
 * Receiving hook for indirect property access (by reference or via $this->field++)
 */
class GetPropertyPointerHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'get_property_ptr_ptr';

    /**
     * Hook access type
     */
    protected int $type;

    /**
     * typedef zval *(*zend_object_get_property_ptr_ptr_t)(zend_object *object, zend_string *member, int type, void **cache_slot)
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments)
    {
        [$this->object, $this->member, $this->type, $this->cacheSlot] = $rawArguments;

        $result = ($this->userHandler)($this);

        return $result;
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

        $previousScope = Core::$executor->setFakeScope($object->ce);
        $result        = ($originalHandler)($object, $member, $type, $cacheSlot);
        Core::$executor->setFakeScope($previousScope);

        return $result;
    }
}
