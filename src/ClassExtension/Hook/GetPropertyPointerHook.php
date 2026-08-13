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
     *
     * Declared `mixed` on purpose: the value is whatever the user handler produced (a zval*
     * or NULL to make the engine fall back to read_property), and a stricter declaration
     * would turn a userland contract violation into a TypeError raised inside an FFI
     * trampoline, where it cannot be caught (see issue #50).
     */
    public function handle(...$rawArguments): mixed
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
     *
     * @return \FFI\CData|null zval* of the property slot, NULL when the engine handler
     *                         declined to hand one out (the VM then uses read_property)
     */
    public function proceed(): ?object
    {
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object    = $this->object;
        $member    = $this->member;
        $type      = $this->type;
        $cacheSlot = $this->cacheSlot;

        $propertySlot = Core::$executor->withFakeScope(
            $object->ce,
            static fn() => ($originalHandler)($object, $member, $type, $cacheSlot),
        );
        assert($propertySlot === null || $propertySlot instanceof CData);

        return $propertySlot;
    }
}
