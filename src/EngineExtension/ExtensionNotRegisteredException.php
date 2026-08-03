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

namespace ZEngine\EngineExtension;

/**
 * Raised when a module is retrieved from the ExtensionManager without having been
 * registered during bootstrap - there is deliberately no hidden side-effect
 * initialization on first access.
 */
final class ExtensionNotRegisteredException extends \RuntimeException
{
    /**
     * @param class-string $moduleClass
     */
    public static function forClass(string $moduleClass): self
    {
        return new self(
            "Module {$moduleClass} is not registered; register it explicitly during bootstrap "
            . '(after Core::init()) via ExtensionManager::register()',
        );
    }
}
