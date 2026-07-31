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

use ZEngine\Core;
use ZEngine\EngineExtension\AbstractModule;
use ZEngine\EngineExtension\ModuleDependency;
use ZEngine\EngineExtension\ModuleInfoInterface;
use ZEngine\EngineExtension\ModuleLifecycleInterface;

/**
 * Test module (engine name "lifecycle") exercising lifecycle callbacks, phpinfo output
 * and dependency wiring
 */
final class LifecycleModule extends AbstractModule implements ModuleLifecycleInterface, ModuleInfoInterface
{
    /**
     * Recorded lifecycle events, in delivery order
     *
     * @var list<string>
     */
    public static array $events = [];

    /**
     * When enabled, every recorded event is echoed together with the Core::isShutdown()
     * state at delivery time (used by child-process ordering tests)
     */
    public static bool $echoEvents = false;

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

    /**
     * @inheritDoc
     */
    public function getModuleDependencies(): array
    {
        return [
            ModuleDependency::required('standard'),
            ModuleDependency::optional('spl'),
        ];
    }

    public function moduleStartup(): void
    {
        self::record('moduleStartup');
    }

    public function moduleShutdown(): void
    {
        self::record('moduleShutdown');
    }

    public function requestStartup(): void
    {
        self::record('requestStartup');
    }

    public function requestShutdown(): void
    {
        self::record('requestShutdown');
    }

    /**
     * @inheritDoc
     */
    public function getDisplayInfo(): array
    {
        return [
            'Lifecycle support' => 'enabled',
            'Module version'    => '1.0.0',
        ];
    }

    private static function record(string $event): void
    {
        self::$events[] = $event;
        if (self::$echoEvents) {
            echo $event, '(coreShutdown=', var_export(Core::isShutdown(), true), ')', PHP_EOL;
        }
    }
}
