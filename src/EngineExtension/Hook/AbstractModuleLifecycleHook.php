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

namespace ZEngine\EngineExtension\Hook;

use Closure;
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;

/**
 * Base for the module entry lifecycle trampolines (MINIT/MSHUTDOWN/RINIT/RSHUTDOWN)
 *
 * Safety contract (issue #75, see docs/long-running.md "Module lifecycle callbacks"):
 *
 *  - After Core::shutdown() every trampoline no-ops: the engine can reach module callbacks
 *    at points where PHP re-entry is no longer safe (eg MSHUTDOWN after request teardown).
 *  - User callbacks never throw across the FFI boundary (issue #50): ext/ffi aborts the
 *    process on an escaping exception, and a FAILURE result from MINIT escalates to a fatal
 *    E_CORE_ERROR. Failures are contained and reported as E_USER_WARNING instead, and the
 *    trampoline always reports SUCCESS to the engine.
 *  - The trampolines follow the standard hook lifecycle: Core::shutdown() restores the NULL
 *    pointers in the module entry, so the entry - which stays in the persistent module
 *    registry - never points into freed libffi memory (ext/ffi frees every callback
 *    trampoline at its own RSHUTDOWN).
 */
abstract class AbstractModuleLifecycleHook extends AbstractHook
{
    /**
     * zend_result (*callback)(int type, int module_number);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): int
    {
        if (Core::isShutdown()) {
            return Core::SUCCESS;
        }
        self::invokeContained($this->userHandler, static::HOOK_FIELD);

        return Core::SUCCESS;
    }

    /**
     * Invokes a module lifecycle callback with full exception containment
     *
     * This frame can be entered by the engine through an FFI trampoline with no PHP frame
     * around it, so nothing may escape: the failure itself is converted into E_USER_WARNING,
     * and even a user error handler turning that warning into an exception is swallowed.
     *
     * @param Closure $callback     The user lifecycle callback to invoke
     * @param string  $callbackName Name of the callback for the failure report
     */
    final public static function invokeContained(Closure $callback, string $callbackName): void
    {
        try {
            $callback();
        } catch (\Throwable $failure) {
            try {
                trigger_error(
                    sprintf('Module lifecycle callback %s failed: %s', $callbackName, $failure->getMessage()),
                    E_USER_WARNING,
                );
            } catch (\Throwable) {
                // A user error handler converted the warning into an exception: it must not
                // cross the FFI boundary either (issue #50)
            }
        }
    }
}
