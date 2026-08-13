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
 * Raised when a module cannot be published into (or started up inside) the engine module
 * registry: the name is already taken, the engine refused the entry (conflicting
 * dependency, duplicate name - it reports an E_CORE_WARNING of its own) or the module
 * startup callbacks failed.
 *
 * Extends \RuntimeException exactly like the inline throws it replaces, so existing
 * `catch (\RuntimeException)` around register()/startup() keeps matching.
 */
final class ModuleRegistrationException extends \RuntimeException
{
    public static function alreadyRegistered(string $moduleName): self
    {
        return new self("Module {$moduleName} was already registered.");
    }

    public static function registrationRefused(string $moduleName): self
    {
        return new self("Can not register module {$moduleName} in the engine");
    }

    public static function startupFailed(string $moduleName): self
    {
        return new self("Can not startup module {$moduleName}");
    }
}
