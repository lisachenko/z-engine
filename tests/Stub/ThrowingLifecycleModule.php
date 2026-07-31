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
use ZEngine\EngineExtension\ModuleLifecycleInterface;

/**
 * Test module (engine name "throwing_lifecycle") whose callbacks throw: exceptions must be
 * contained by the lifecycle trampolines instead of crossing the FFI boundary (issue #50)
 */
final class ThrowingLifecycleModule extends AbstractModule implements ModuleLifecycleInterface
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

    public static function globalType(): ?string
    {
        return null;
    }

    public function moduleStartup(): void
    {
        throw new \RuntimeException('MINIT boom');
    }

    public function moduleShutdown(): void {}

    public function requestStartup(): void
    {
        throw new \RuntimeException('RINIT boom');
    }

    public function requestShutdown(): void {}
}
