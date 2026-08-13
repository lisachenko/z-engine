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

/**
 * Receiving hook for object field unset operation
 */
final class UnsetPropertyHook extends AbstractPropertyHook
{
    protected const HOOK_FIELD = 'unset_property';

    /**
     * typedef void (*zend_object_unset_property_t)(zend_object *object, zend_string *member, void **cache_slot);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        /**
         * @var zend_object $object    Narrowed to the stub views at the engine callback boundary
         * @var zend_string $member
         * @var CData|null  $cacheSlot
         */
        [$object, $member, $cacheSlot] = $rawArguments;
        $this->object                  = $object;
        $this->member                  = $member;
        $this->cacheSlot               = $cacheSlot;

        ($this->userHandler)($this);
    }

    /**
     * Proceeds with default handler
     */
    public function proceed(): void
    {
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object    = $this->object;
        $member    = $this->member;
        $cacheSlot = $this->cacheSlot;

        $callerThis = Core::$executor->getExecutionState()->getThis();
        if ($callerThis === null) {
            throw new \LogicException('Unset property hook expects a calling frame with a bound $this');
        }

        Core::$executor->withFakeScope(
            $callerThis->getRawObject()->ce,
            static fn() => ($originalHandler)($object, $member, $cacheSlot),
        );
    }
}
