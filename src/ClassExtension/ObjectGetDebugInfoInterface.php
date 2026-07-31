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

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\GetDebugInfoHook;

/**
 * Interface ObjectGetDebugInfoInterface allows to control what var_dump() and debuggers see
 */
interface ObjectGetDebugInfoInterface
{
    /**
     * Returns the debug info for the given object, replacing the default engine output
     *
     * The handler must not throw: it is called by the engine across the FFI boundary
     * where escaping exceptions cannot be handled (see issue #50). Call
     * $hook->proceed() to get the default engine debug info.
     *
     * @return array<array-key, mixed> Debug info to show
     */
    public static function __getDebugInfo(GetDebugInfoHook $hook): array;
}
