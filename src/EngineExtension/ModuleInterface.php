<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\EngineExtension;

/**
 * Declares general ModuleInterface which is used for declaration of userland PHP extensions
 */
interface ModuleInterface
{
    /**
     * Returns the target debug mode for this module
     *
     * Use ZEND_DEBUG_BUILD as default if your module does not depend on debug mode.
     */
    public static function targetDebug(): bool;

    /**
     * Returns the target API version for this module
     *
     * @see zend_modules.h:ZEND_MODULE_API_NO
     */
    public static function targetApiVersion(): int;

    /**
     * Returns true if this module should be persistent or false if temporary
     */
    public static function targetPersistent(): bool;

    /**
     * Returns the target thread-safe mode for this module
     *
     * Use ZEND_THREAD_SAFE as default if your module does not depend on thread-safe mode.
     */
    public static function targetThreadSafe(): bool;

    /**
     * Returns global type (if present) or null if module doesn't use global memory
     */
    public static function globalType(): ?string;

    /**
     * Returns the list of dependencies of this module on other engine modules
     *
     * The dependencies are written into the module entry's `deps` field as a persistent
     * NULL-terminated `zend_module_dep[]` during registration. AbstractModule provides a
     * default implementation returning an empty list.
     *
     * Named getModuleDependencies() because AbstractModule inherits the native
     * ReflectionExtension::getDependencies(), which reads the registered entry back in the
     * engine's own `name => relationship` format.
     *
     * @return list<ModuleDependency>
     */
    public function getModuleDependencies(): array;

    /**
     * Starts this module
     *
     * Startup includes calling callbacks for global memory allocation, checking deps, etc
     */
    public function startup(): void;

    /**
     * Checks if this module loaded or not
     */
    public function isModuleRegistered(): bool;

    /**
     * Performs registration of this module in the engine
     */
    public function register(): void;
}
