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
 * Receiving hook for object field check operation
 */
final class HasPropertyHook extends AbstractPropertyHook
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
     * typedef int (*zend_object_has_property_t)(zend_object *object, zend_string *member, int has_set_exists, void **cache_slot);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_object $object    Narrowed to the stub views at the engine callback boundary
         * @var zend_string $member
         * @var int         $type
         * @var CData|null  $cacheSlot
         */
        [$object, $member, $type, $cacheSlot] = $rawArguments;
        $this->object                         = $object;
        $this->member                         = $member;
        $this->type                           = $type;
        $this->cacheSlot                      = $cacheSlot;

        $result = ($this->userHandler)($this);
        assert(is_int($result));

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
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object    = $this->object;
        $member    = $this->member;
        $type      = $this->type;
        $cacheSlot = $this->cacheSlot;

        $result = Core::$executor->withFakeScope(
            $object->ce,
            static fn() => ($originalHandler)($object, $member, $type, $cacheSlot),
        );
        assert(is_int($result));

        return $result;
    }
}
