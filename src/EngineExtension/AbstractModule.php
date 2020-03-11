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

        // We don't need persistent memory here, as PHP copies structures into persistent memory itself
        $module     = Core::new('zend_module_entry');
        $moduleName = $this->moduleName;
        $nameLength = strlen($moduleName) + 1; /* extra zero-byte */;
        $rawName    = Core::new("char[$nameLength]", false, static::targetPersistent());
        Core::memcpy($rawName, $moduleName, $nameLength - 1);
        $rawName[$nameLength - 1] = "\0";

        $module->size       = Core::sizeof($module);
        $module->type       = static::targetPersistent() ? self::MODULE_PERSISTENT : self::MODULE_TEMPORARY;
        $module->name       = $rawName;
        $module->zend_api   = static::targetApiVersion();
        $module->zend_debug = (int)static::targetDebug();
        $module->zts        = (int)static::targetThreadSafe();

        $globalType = static::globalType();
        if ($globalType !== null) {
            $module->globals_size = Core::sizeof(Core::type($globalType));
            $memoryStructure      = Core::new($globalType, false, static::targetPersistent());
            $module->globals_ptr  = Core::addr($memoryStructure);
        }

        // $module pointer will be updated, as registration method returns a copy of memory
        $realModulePointer = Core::call('zend_register_module_ex', Core::addr($module));

        $this->moduleEntry = $realModulePointer;

        $extensionConstructor = \ReflectionExtension::class . '::__construct';
        call_user_func([$this, $extensionConstructor], $moduleName);
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