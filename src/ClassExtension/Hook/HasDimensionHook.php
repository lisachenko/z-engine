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

use ZEngine\Generated\zend_object;
use ZEngine\Generated\zval;

/**
 * Receiving hook for object dimension check operation (isset($object[$offset])/empty($object[$offset]))
 */
class HasDimensionHook extends AbstractDimensionHook
{
    protected const HOOK_FIELD = 'has_dimension';

    /**
     * Check type:
     *  - 0 (isset) whether dimension exists and is not NULL
     *  - 1 (empty) whether dimension exists and is true
     */
    protected int $type;

    /**
     * typedef int (*zend_object_has_dimension_t)(zend_object *object, zval *member, int check_empty);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_object $object Narrowed to the stub views at the engine callback boundary
         * @var zval|null   $offset
         * @var int         $type
         */
        [$object, $offset, $type] = $rawArguments;
        $this->object             = $object;
        $this->offset             = $offset;
        $this->type               = $type;

        $result = ($this->userHandler)($this);
        assert(is_int($result));

        return $result;
    }

    /**
     * Returns the check type:
     *  - 0 (isset) whether dimension exists and is not NULL
     *  - 1 (empty) whether dimension exists and is true
     */
    public function getCheckType(): int
    {
        return $this->type;
    }

    /**
     * Proceeds with default handler
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->object, $this->offset, $this->type);
        assert(is_int($result));

        return $result;
    }
}
