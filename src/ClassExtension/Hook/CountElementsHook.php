<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
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
use ZEngine\Hook\AbstractHook;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for object count operation (count($object))
 */
final class CountElementsHook extends AbstractHook
{
    protected const HOOK_FIELD = 'count_elements';

    /**
     * Object instance
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Holds a pointer to the zend_long count out-parameter (for native callback only)
     *
     * A raw `zend_long *` out-parameter: no engine struct and therefore no stub view.
     */
    protected CData $count;

    /**
     * typedef zend_result (*zend_object_count_elements_t)(zend_object *object, zend_long *count);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_object $object Narrowed to the stub view at the engine callback boundary
         * @var CData       $count
         */
        [$object, $count] = $rawArguments;
        $this->object     = $object;
        $this->count      = $count;

        $result = ($this->userHandler)($this);
        assert(is_int($result));
        // The count slot is a plain zend_long out-parameter owned by the engine caller
        // @phpstan-ignore offsetAssign.dimType (writing through a zend_long* CData pointer)
        $count[0] = $result;

        return Core::SUCCESS;
    }

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Proceeds with default handler and returns the number of elements it reported
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        ($originalHandler)($this->object, $this->count);

        // @phpstan-ignore offsetAccess.nonOffsetAccessible (reading through a zend_long* CData pointer)
        $count = $this->count[0];
        assert(is_int($count));

        return $count;
    }
}
