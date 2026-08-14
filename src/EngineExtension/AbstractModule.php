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
use ZEngine\EngineExtension\Hook\AbstractModuleLifecycleHook;
use ZEngine\EngineExtension\Hook\ExtensionConstructorHook;
use ZEngine\EngineExtension\Hook\ModuleInfoHook;
use ZEngine\EngineExtension\Hook\ModuleShutdownHook;
use ZEngine\EngineExtension\Hook\ModuleStartupHook;
use ZEngine\EngineExtension\Hook\RequestShutdownHook;
use ZEngine\EngineExtension\Hook\RequestStartupHook;
use ZEngine\Reflection\ReflectionExtension;
use ZEngine\Type\StringEntry;

/**
 * Base class for userland PHP extensions (engine modules)
 *
 * Memory model (docs/long-running.md): since PHP 8.4 zend_register_module_ex() stores the
 * given zend_module_entry pointer directly in the module registry - it no longer copies the
 * structure. The entry and every buffer it references (module name, globals, dependency
 * array and its strings) are therefore allocated persistently and stay alive for the whole
 * process: immortal-by-design allocations.
 *
 * Lifecycle callbacks (ModuleLifecycleInterface) and phpinfo() output (ModuleInfoInterface)
 * are wired opt-in through FFI-closure trampolines that follow the standard hook lifecycle:
 * Core::shutdown() restores the NULL pointers, so the persistent module entry never points
 * into freed libffi memory. See the interfaces and docs/long-running.md ("Module lifecycle
 * callbacks") for the delivery guarantees.
 */
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
     * Whether the request-end delivery of requestShutdown() has already happened
     */
    private bool $requestShutdownDelivered = false;

    /**
     * Module constructor.
     *
     * @param string|null $moduleName Module name (optional). If not set, class name will be used as module name
     */
    final public function __construct(?string $moduleName = null)
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
     * Returns the list of dependencies of this module on other engine modules
     *
     * @inheritDoc
     * @return list<ModuleDependency>
     */
    public function getModuleDependencies(): array
    {
        return [];
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
            throw ModuleRegistrationException::alreadyRegistered($this->moduleName);
        }

        // Since PHP 8.4 zend_register_module_ex stores THIS pointer directly in the
        // module registry (zend_hash_add_ptr - the old copying add_mem behaviour is
        // gone), so the entry must be malloc-backed and never FFI-collected: the engine
        // frees it itself at module destruction. Every buffer the entry references
        // (name, globals, deps) is persistent for the same reason (docs/long-running.md)
        $module     = Core::trackedNew('zend_module_entry', true);
        $moduleName = $this->moduleName;
        $moduleType = static::targetPersistent() ? self::MODULE_PERSISTENT : self::MODULE_TEMPORARY;

        $module->size       = Core::sizeof($module);
        $module->type       = $moduleType;
        $module->name       = self::newPersistentString($moduleName);
        $module->zend_api   = static::targetApiVersion();
        $module->zend_debug = (int) static::targetDebug();
        $module->zts        = (int) static::targetThreadSafe();

        $globalType = static::globalType();
        if ($globalType !== null) {
            $module->globals_size = Core::sizeOfType($globalType);
            if (\ZEND_THREAD_SAFE) {
                // On ZTS the entry carries a pointer to a ts_rsrc_id slot instead of the
                // globals block: zend_startup_module_ex() passes it to ts_allocate_id(),
                // and the TSRM allocates (and frees) the per-thread globals itself
                $resourceIdSlot         = Core::trackedNew('ts_rsrc_id', true);
                $module->globals_id_ptr = Core::addr($resourceIdSlot);
            } else {
                // The engine dereferences globals_ptr for the module's whole registry lifetime
                $memoryStructure     = Core::trackedNew($globalType, true);
                $module->globals_ptr = Core::addr($memoryStructure);
            }
        }

        $this->attachDependencies($module);

        // Since PHP 8.3 the module type is passed explicitly instead of being read from the entry.
        $realModulePointer = Core::call('zend_register_module_ex', Core::addr($module), $moduleType);
        if ($realModulePointer === null) {
            // The engine refused the entry (conflicting dependency or duplicate name) and
            // reported an E_CORE_WARNING; the persistent buffers above are bounded, error-path
            // allocations that stay in the tracked-block registry
            throw ModuleRegistrationException::registrationRefused($moduleName);
        }
        \assert($realModulePointer instanceof CData);

        $this->moduleEntry = $realModulePointer;

        if (static::targetPersistent()) {
            // The registry bucket key interned at runtime is request-lifetime: swap it for
            // a persistent interned string so the registry teardown at process shutdown
            // does not read a dangling key
            $this->makeRegistryKeyPersistent();
        } else {
            // dl() parity: a temporary module registered mid-request is only deactivated
            // and destroyed at request shutdown when the engine walks the full module
            // registry instead of the handler lists precomputed at process startup
            Core::$executor->enableFullTablesCleanup();
        }

        $this->wireOptInCallbacks();

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
            throw ModuleRegistrationException::startupFailed($this->moduleName);
        }

        // dl() parity: a module started in the middle of a request receives the current
        // request's RINIT immediately - the engine only activates modules at request start
        if ($this instanceof ModuleLifecycleInterface) {
            AbstractModuleLifecycleHook::invokeContained($this->requestStartup(...), 'requestStartup');
        }
    }

    /**
     * This getter extends general logic with automatic casting of the global memory pointer
     * to the declared globals type
     *
     * Returns a `<globalType> *` view of the globals block (never a value copy): a pointer
     * cast is size-safe for every globals type, while a value-type cast reinterprets the
     * pointer variable itself - FFI only auto-dereferences a bare `void *` source and
     * throws "attempt to cast to larger type" for anything else wider than a pointer
     * (issue #109). FFI supports `->field` access directly on a pointer-to-struct, and
     * array-typed globals (eg `unsigned int[10]`) decay to an element pointer, exactly
     * like a C array expression, so indexing keeps working.
     *
     * @inheritDoc
     * @return \FFI\CData|null
     */
    final public function getGlobals(): ?object
    {
        $rawPointer = parent::getGlobals();
        if ($rawPointer !== null) {
            $globalType = static::globalType();
            // The engine only allocates a globals block when a type was declared
            \assert($globalType !== null);
            $rawPointer = Core::cast(self::pointerTypeFor($globalType), $rawPointer);
        }

        return $rawPointer;
    }

    /**
     * Builds the pointer declaration for the given globals type (C array-to-pointer decay)
     *
     * `zend_counter_globals` => `zend_counter_globals *`, `unsigned int[10]` =>
     * `unsigned int *`, `int[4][5]` => `int(*)[5]` (a pointer to the first row, as in C).
     */
    private static function pointerTypeFor(string $globalType): string
    {
        $matched = preg_match('/^(?<element>[^\[\]]+?)\s*\[\w*\](?<rows>(?:\[\w+\])*)$/', $globalType, $match);
        if ($matched !== 1) {
            return $globalType . ' *';
        }

        return $match['rows'] === ''
            ? $match['element'] . ' *'
            : $match['element'] . '(*)' . $match['rows'];
    }

    /**
     * Renders a phpinfo()-style table for this module's information section
     *
     * Used as the default rendering of ModuleInfoInterface::getDisplayInfo() rows: plain
     * `label => value` lines in text mode, an HTML table otherwise. The engine's own
     * text/HTML switch (sapi_module.phpinfo_as_text) is not exported, PHP_SAPI is a
     * faithful proxy for it.
     *
     * @param array<string, scalar> $rows Map from row label to row value
     */
    final protected function printInfoTable(array $rows): void
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            foreach ($rows as $label => $value) {
                echo $label, ' => ', (string) $value, "\n";
            }

            return;
        }
        echo "<table>\n";
        foreach ($rows as $label => $value) {
            $renderedLabel = htmlspecialchars($label);
            $renderedValue = htmlspecialchars((string) $value);
            echo '<tr><td class="e">', $renderedLabel, '</td><td class="v">', $renderedValue, "</td></tr>\n";
        }
        echo "</table>\n";
    }

    /**
     * Wires the opt-in lifecycle and info trampolines into the registered module entry
     *
     * Installed hooks follow the standard hook lifecycle: Core::shutdown() restores the
     * NULL pointers while the trampolines are still alive, so the persistent module entry
     * never points into freed libffi memory.
     */
    private function wireOptInCallbacks(): void
    {
        if ($this instanceof ModuleLifecycleInterface) {
            // The Core hook registry keeps every installed hook (and its trampoline) alive
            $lifecycleHooks = [
                new ModuleStartupHook($this->moduleStartup(...), $this->moduleEntry),
                new ModuleShutdownHook($this->moduleShutdown(...), $this->moduleEntry),
                new RequestStartupHook($this->requestStartup(...), $this->moduleEntry),
                new RequestShutdownHook($this->requestShutdown(...), $this->moduleEntry),
            ];
            foreach ($lifecycleHooks as $hook) {
                $hook->install();
            }

            // Guaranteed request-end delivery of requestShutdown(): the engine walks the
            // module registry only after user shutdown functions have run, ie after
            // Core::shutdown() has already cleared the trampoline pointers. User shutdown
            // functions run in registration order and Core::init() registered
            // Core::shutdown() before any module could register itself, so this callback
            // always runs right after the engine pointers were restored.
            register_shutdown_function(function (): void {
                $this->deliverRequestShutdown();
            });
        }

        if ($this instanceof ModuleInfoInterface) {
            $moduleInfo = $this;
            $infoHook   = new ModuleInfoHook(function () use ($moduleInfo): void {
                $this->printInfoTable($moduleInfo->getDisplayInfo());
            }, $this->moduleEntry);
            $infoHook->install();
        }
    }

    /**
     * Delivers ModuleLifecycleInterface::requestShutdown() at request end (idempotent)
     *
     * Runs after Core::shutdown(): engine pointers are already restored, so the callback
     * must not (and cannot) write into engine structures anymore.
     */
    private function deliverRequestShutdown(): void
    {
        if ($this->requestShutdownDelivered || !$this instanceof ModuleLifecycleInterface) {
            return;
        }
        $this->requestShutdownDelivered = true;
        // A module that was registered but never started does not take part in the request
        // lifecycle (the engine skips RSHUTDOWN for non-started modules as well)
        if (!$this->wasModuleStarted()) {
            return;
        }
        AbstractModuleLifecycleHook::invokeContained($this->requestShutdown(...), 'requestShutdown');
    }

    /**
     * Writes the declared dependencies into the entry as a NULL-terminated zend_module_dep[]
     *
     * The array and its strings are persistent: the module registry references them for the
     * rest of the process (immortal-by-design, docs/long-running.md).
     *
     * @param \FFI\CData $module
     */
    private function attachDependencies(object $module): void
    {
        $dependencies = $this->getModuleDependencies();
        if ($dependencies === []) {
            return;
        }

        $count           = count($dependencies);
        $rawDependencies = Core::trackedNew('zend_module_dep[' . ($count + 1) . ']', true);
        $index           = 0;
        foreach ($dependencies as $dependency) {
            $rawDependency = $rawDependencies[$index];
            \assert($rawDependency instanceof CData);
            $rawDependency->name = self::newPersistentString($dependency->getName());
            $relation            = $dependency->versionRelation();
            if ($relation !== null) {
                $rawDependency->rel = self::newPersistentString($relation->value);
            }
            $version = $dependency->getVersion();
            if ($version !== null) {
                $rawDependency->version = self::newPersistentString($version);
            }
            $rawDependency->type = $dependency->dependencyType()->value;
            $index++;
        }
        // The trailing element stays zero-initialized: the NULL terminator the engine
        // iterates up to
        $module->deps = Core::cast('zend_module_dep *', $rawDependencies);
    }

    /**
     * Allocates a persistent NUL-terminated C string tracked in the z-engine block registry
     *
     * @return \FFI\CData
     */
    private static function newPersistentString(string $value): object
    {
        $length = strlen($value) + 1;
        $buffer = Core::trackedNew("char[{$length}]", true);
        // FFI zero-initializes the buffer, so the trailing NUL byte is already in place
        Core::memcpy($buffer, $value, $length - 1);

        return $buffer;
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
        $lowerName   = strtolower($this->moduleName);
        $registryKey = Core::$modules->findKeyEntry($lowerName);
        // A permanent interned key (registered during engine startup) is already safe, and
        // so is a module that is not in the registry at all
        if ($registryKey === null || $registryKey->isPermanent()) {
            return;
        }

        Core::$modules->replaceKey($lowerName, StringEntry::persistentInterned($lowerName));
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
        // Converts camelCase to snake_case; preg_replace_callback() returns null on a PCRE
        // error (backtrack/recursion limit), in which case the class name is used unsplit
        // instead of being handed to strtolower() as null (deprecated since PHP 8.1)
        $snakeCased = preg_replace_callback('/([a-z])([A-Z])/', function ($match) {
            return $match[1] . '_' . $match[2];
        }, $className);

        return strtolower($snakeCased ?? $className);
    }
}
