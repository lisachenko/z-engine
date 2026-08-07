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

use ZEngine\EngineExtension\AbstractModule;

/**
 * Test module (engine name "array_globals") declaring array-typed globals
 *
 * Exercises the C array-to-pointer decay in AbstractModule::getGlobals() (issue #109):
 * the README documents exactly this globals shape for a per-process counter module.
 */
final class ArrayGlobalsModule extends AbstractModule
{
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    public static function targetPersistent(): bool
    {
        return false;
    }

    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    public static function globalType(): string
    {
        return 'unsigned int[10]';
    }
}
