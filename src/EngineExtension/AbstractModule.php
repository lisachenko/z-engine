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

use FFI\CData;
use ZEngine\Core;
use ZEngine\EngineExtension\Hook\ExtensionConstructorHook;
use ZEngine\Reflection\ReflectionExtension;
use ZEngine\Type\StringEntry;

abstract class AbstractModule extends ReflectionExtension implements ModuleInterface
{
    /**
     * @see zend_modules.h:MODULE_PERSISTENT
     */
    private const MODULE_PERSISTENT = 1;

    /**
     * @see zend_modules.h:MODULE_TEMPORARY
     */
    private const MODULE_TEMPORARY = 2;

    /**
     * Unique name of this module
     */
    private string $moduleName;

    /**
     * Module constructor.
     *
     * @param string|null $moduleName Module name (optional). If not set, class name will be used as module name
     */
    final public function __construct(string $moduleName = null)
    {
        $this->moduleName = $moduleName ?? self::detectModuleName();

        // if module is already registered, then we can use it immediately
        if ($this->isModuleRegistered()) {
            parent::__construct($this->moduleName);
        }
    }

    /**
     * Returns the unique name of this module
     */
    final public function getName(): string
    {
        return $this->moduleName;
    }

    /**
     * Returns the engine API version this module targets.
     *
     * By default the version of the currently running engine is used (from the
     * generated per-version constants), so modules are portable across the PHP
     * versions supported by their z-engine release. Override only when a module
     * intentionally pins a specific engine API.
     */
    public static function targetApiVersion(): int
    {
        return Core::engineConstant('ZEND_MODULE_API_NO');
    }

    /**
     * Checks if this module loaded or not
     */
    final public function isModuleRegistered(): bool
    {
        return extension_loaded($this->moduleName);
    }

    /**
     * Performs registration of this module in the engine
     */
    final public function register(): void
    {
        if ($this->isModuleRegistered()) {
            throw new \RuntimeException('Module ' . $this->moduleName . ' was already registered.');
        }

        // Since PHP 8.4 zend_register_module_ex stores THIS pointer directly in the
        // module registry (zend_hash_add_ptr - the old copying add_mem behaviour is
        // gone), so the entry must be malloc-backed and never FFI-collected: the
        // engine frees it itself with free() at module destruction
        $module     = Core::trackedNew('zend_module_entry', true);
        $moduleName = $this->moduleName;
        $nameLength = strlen($moduleName) + 1;
        /* extra zero-byte */;
        $rawName = Core::new("char[$nameLength]", false, static::targetPersistent());
        Core::memcpy($rawName, $moduleName, $nameLength - 1);
        $rawName[$nameLength - 1] = "\0";

        $module->size       = Core::sizeof($module);
        $module->type       = static::targetPersistent() ? self::MODULE_PERSISTENT : self::MODULE_TEMPORARY;
        $module->name       = $rawName;
        $module->zend_api   = static::targetApiVersion();
        $module->zend_debug = (int) static::targetDebug();
        $module->zts        = (int) static::targetThreadSafe();

        $globalType = static::globalType();
        if ($globalType !== null) {
            $module->globals_size = Core::sizeof(Core::type($globalType));
            $memoryStructure      = Core::new($globalType, false, static::targetPersistent());
            $module->globals_ptr  = Core::addr($memoryStructure);
        }

        // Since PHP 8.3 the module type is passed explicitly instead of being read from the entry.
        $realModulePointer = Core::call('zend_register_module_ex', Core::addr($module), (int) $module->type);

        $this->moduleEntry = $realModulePointer;

        if (static::targetPersistent()) {
            $this->makeRegistryKeyPersistent();
        }

        (new \ReflectionMethod(\ReflectionExtension::class, '__construct'))->invokeArgs($this, [$moduleName]);
    }

    /**
     * Starts this module
     *
     * Startup includes calling callbacks for global memory allocation, checking deps, etc
     */
    final public function startup(): void
    {
        if ($this instanceof ControlModuleGlobalsInterface) {
            $closure = (new \ReflectionMethod($this, '__globalConstruct'))->getClosure();
            $hook    = new ExtensionConstructorHook($closure, $this->moduleEntry);
            $hook->install();
        }

        $result = Core::call('zend_startup_module_ex', $this->moduleEntry);
        if ($result !== Core::SUCCESS) {
            throw new \RuntimeException('Can not startup module ' . $this->moduleName);
        }
    }

    /**
     * This getter extends general logic with automatic casting global memory to required type
     *
     * @inheritDoc
     */
    final public function getGlobals(): ?CData
    {
        $rawPointer = parent::getGlobals();
        if ($rawPointer !== null) {
            $rawPointer = Core::cast(static::globalType(), $rawPointer);
        }

        return $rawPointer;
    }

    /**
     * Swaps the module_registry bucket key for a persistent interned string
     *
     * Registering a module at runtime makes zend_register_module_ex intern the registry
     * key as a REQUEST-lifetime string (zend_new_interned_string goes to the per-request
     * interned table outside of engine startup), so a persistent module's bucket key
     * would dangle after the first request and crash the registry teardown at process
     * shutdown. A persistent interned replacement has the same content hash and is never
     * released by the engine (interned strings are release no-ops).
     */
    private function makeRegistryKeyPersistent(): void
    {
        $registryTable = Core::$modules->getRawValue();
        $lowerName     = strtolower($this->moduleName);

        $numUsed = $registryTable->nNumUsed;
        for ($index = 0; $index < $numUsed; $index++) {
            $bucket = Core::addr($registryTable->arData[$index]);
            if ($bucket->key === null) {
                continue;
            }
            $key = StringEntry::fromCData($bucket->key);
            if ($key->getStringValue() !== $lowerName) {
                continue;
            }
            // A permanent interned key (registered during engine startup) is already safe
            if (!$key->isPermanent()) {
                $bucket->key = StringEntry::persistentInterned($lowerName)->getRawValue();
            }

            return;
        }
    }

    /**
     * Detects a module name by class name
     */
    private static function detectModuleName(): string
    {
        $classNameParts = explode('\\', static::class);
        $className      = end($classNameParts);
        $prefixName     = strstr($className, 'Module', true);
        if ($prefixName !== false) {
            $className = $prefixName;
        }
        // Converts camelCase to snake_case
        $moduleName = strtolower(preg_replace_callback('/([a-z])([A-Z])/', function ($match) {
            return $match[1] . '_' . $match[2];
        }, $className));

        return $moduleName;
    }
}
