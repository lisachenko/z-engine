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

namespace ZEngine\Stub;

use FFI\CData;
use ZEngine\ClassExtension\Hook\CloneObjectHook;
use ZEngine\ClassExtension\Hook\CreateObjectHook;
use ZEngine\ClassExtension\Hook\GetDebugInfoHook;
use ZEngine\ClassExtension\ObjectCloneInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectGetDebugInfoInterface;

class DebuggableCloneable implements ObjectCreateInterface, ObjectGetDebugInfoInterface, ObjectCloneInterface
{
    public int $generation = 0;

    /**
     * @inheritDoc
     */
    public static function __init(CreateObjectHook $hook): CData
    {
        $object = $hook->proceed();
        assert($object instanceof CData);

        return $object;
    }

    /**
     * @inheritDoc
     */
    public static function __getDebugInfo(GetDebugInfoHook $hook): array
    {
        $default = $hook->proceed();

        return ['marker' => 'custom-debug-info', 'default' => $default];
    }

    /**
     * @inheritDoc
     */
    public static function __cloneObject(CloneObjectHook $hook): object
    {
        $clone = $hook->proceed();
        assert($clone instanceof self);
        $clone->generation++;

        return $clone;
    }
}
