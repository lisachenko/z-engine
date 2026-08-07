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
 * Test module (engine name "globals") declaring a zval-typed globals block
 *
 * Exercises AbstractModule::getGlobals() with a globals type larger than a pointer
 * (issue #109: the base-class cast must go through the pointer type).
 */
final class GlobalsModule extends AbstractModule
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
        return 'zval';
    }
}
