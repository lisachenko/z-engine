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
 * Process-wide typed registry of userland engine modules
 *
 * Every module registers here exactly ONCE, explicitly, during application bootstrap
 * (after Core::init()) and is retrieved afterwards by class name with full static
 * typing - class-string generics resolve get(ZEngineModule::class) to the concrete
 * module type. Modules behave as singletons: one instance per module class per process.
 *
 * There is no hidden side-effect initialization: retrieving an unregistered module
 * throws ExtensionNotRegisteredException instead of booting it behind the caller's
 * back. This keeps initialization controllable by the user - the module instance is
 * constructed (and configured) by the caller and only then handed to register(), which
 * wires it into the engine:
 *
 *  - the engine module entry is registered on the FIRST registration of the process;
 *    later requests of the same process find the persistent entry already in place and
 *    only re-bind to it;
 *  - the module is started (MINIT + current-request RINIT delivery, dl() parity) when
 *    it has not been started yet.
 *
 * Registration is per PHP request (the registry is PHP state that dies with the
 * request); the underlying engine entry of a persistent module survives for the whole
 * process, as documented in docs/long-running.md.
 */
final class ExtensionManager
{
    /**
     * Registered modules, keyed by their concrete class name
     *
     * @var array<class-string<AbstractModule>, AbstractModule>
     */
    private static array $modules = [];

    /**
     * Registers a module instance and wires it into the engine
     *
     * @template T of AbstractModule
     *
     * @param T $module Configured module instance to register
     *
     * @return T The same instance, now retrievable via get()
     */
    public static function register(AbstractModule $module): AbstractModule
    {
        $moduleClass = $module::class;
        if (isset(self::$modules[$moduleClass])) {
            throw new \LogicException("Module {$moduleClass} is already registered; use get() to access it");
        }

        if (!$module->isModuleRegistered()) {
            $module->register();
        }
        if (!$module->wasModuleStarted()) {
            $module->startup();
        }

        self::$modules[$moduleClass] = $module;

        return $module;
    }

    /**
     * Returns the registered singleton of the given module class
     *
     * @template T of AbstractModule
     *
     * @param class-string<T> $moduleClass
     *
     * @return T
     *
     * @throws ExtensionNotRegisteredException when the module was never registered
     */
    public static function get(string $moduleClass): AbstractModule
    {
        $module = self::$modules[$moduleClass] ?? throw ExtensionNotRegisteredException::forClass($moduleClass);
        assert($module instanceof $moduleClass);

        return $module;
    }

    /**
     * Checks whether a module class has been registered in this request
     *
     * @param class-string<AbstractModule> $moduleClass
     */
    public static function has(string $moduleClass): bool
    {
        return isset(self::$modules[$moduleClass]);
    }
}
