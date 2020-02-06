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

/**
 * Receiving hook for object field check operation
 */
class HasPropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'has_property';

    /**
     * Check type:
     *  - 0 (has) whether property exists and is not NULL
     *  - 1 (set) whether property exists and is true
     *  - 2 (exists) whether property exists
     */
    protected int $type;

    /**
     * typedef int (*zend_object_has_property_t)(zval *object, zval *member, int has_set_exists, void **cache_slot);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        [$this->object, $this->member, $this->type, $this->cacheSlot] = $rawArguments;

        $result = ($this->userHandler)($this);

        return $result;
    }

    /**
     * Returns the check type:
     *  - 0 (has) whether property exists and is not NULL
     *  - 1 (set) whether property exists and is true
     *  - 2 (exists) whether property exists
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * Proceeds with default handler
     */
    public function proceed(): int
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

        $previousScope = Core::$executor->setFakeScope($object->value->obj->ce);
        $result        = ($originalHandler)($object, $member, $type, $cacheSlot);
        Core::$executor->setFakeScope($previousScope);

        return $result;
    }
}
