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
 * Receiving hook for object field unset operation
 */
class UnsetPropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'unset_property';

    /**
     * typedef void (*zend_object_unset_property_t)(zend_object *object, zend_string *member, void **cache_slot);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$this->object, $this->member, $this->cacheSlot] = $rawArguments;

        ($this->userHandler)($this);
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
        $cacheSlot = $this->cacheSlot;

        $previousScope = Core::$executor->setFakeScope(Core::$executor->getExecutionState()->getThis()->getRawObject()->ce);
        ($originalHandler)($object, $member, $cacheSlot);
        Core::$executor->setFakeScope($previousScope);
    }
}
