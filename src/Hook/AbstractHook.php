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

namespace ZEngine\Hook;

use Closure;
use FFI;
use FFI\CData;
use ZEngine\Core;

/**
 * AbstractHook provides reusable template for installing a hook in the PHP engine
 *
 * Lifecycle: install() replaces an engine function pointer with a libffi trampoline and
 * registers this hook in the Core hook registry (which keeps it alive), uninstall() restores
 * the original pointer, and Core::shutdown() force-restores every still-installed hook while
 * the trampolines are guaranteed to be alive - so no engine structure that outlives the
 * request can keep pointing at a freed trampoline.
 */
abstract class AbstractHook implements HookInterface
{
    /**
     * This field should be updated in children class and accessed through LSB
     */
    protected const string HOOK_FIELD = 'unknown';

    /**
     * Custom user handler
     */
    protected Closure $userHandler;

    /**
     * Holds an original handler (if present), captured at install() time
     */
    protected ?CData $originalHandler = null;

    /**
     * Contains a top-level structure that contains a field with hook
     *
     * @var CData|FFI Either raw C structure or global FFI object itself
     */
    private $rawStructure;

    /**
     * Whether this hook trampoline is currently written into the engine structure
     */
    private bool $installed = false;

    public function __construct(Closure $userHandler, $rawStructure)
    {
        assert($rawStructure instanceof FFI || $rawStructure instanceof CData, 'Invalid container');
        $this->userHandler  = $userHandler;
        $this->rawStructure = $rawStructure;
    }

    /**
     * Performs installation of current hook (idempotent)
     *
     * The original pointer is captured here (not in the constructor), so stacking several
     * hooks on the same field forms a well-defined chain where each hook restores its
     * predecessor. The Core registry keeps a strong reference to the installed hook: the
     * trampoline can never be collected while the engine still points at it.
     *
     * <span style="color:red; font-weight: bold">WARNING!</span>
     * Please note, that this functionality is not supported on all libffi platforms and
     * is not efficient.
     *
     * @link https://www.php.net/manual/en/ffi.examples-callback.php
     */
    #[\Override]
    final public function install(): void
    {
        if ($this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            throw new \LogicException('Cannot install an engine hook after Core::shutdown()');
        }
        $this->originalHandler = $this->rawStructure->{static::HOOK_FIELD};

        $this->rawStructure->{static::HOOK_FIELD} = Closure::fromCallable([$this, 'handle']);
        $this->installed                          = true;
        Core::registerHook($this);
    }

    /**
     * Restores the original engine pointer (idempotent)
     *
     * Only the most recently installed hook of a field may be uninstalled: restoring an
     * older hook first would clobber the newer trampoline with a stale pointer.
     */
    #[\Override]
    final public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            // The engine already restored/abandoned this pointer during shutdown
            $this->installed = false;

            return;
        }
        if (!Core::isTopHook($this)) {
            throw new \LogicException(
                'Another hook was installed over this one on the same engine field; uninstall it first',
            );
        }

        $this->rawStructure->{static::HOOK_FIELD} = $this->originalHandler;
        $this->installed                          = false;
        Core::unregisterHook($this);
    }

    /**
     * Re-installs the hook with a freshly minted trampoline (uninstall + install)
     */
    final public function reinstall(): void
    {
        $this->uninstall();
        $this->install();
    }

    /**
     * Checks if this hook is currently installed into the engine structure
     */
    #[\Override]
    final public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * Checks if an original handler is present to call it later with proceed
     */
    #[\Override]
    final public function hasOriginalHandler(): bool
    {
        return $this->originalHandler !== null;
    }

    /**
     * Returns the original engine handler as an invocable callable for proceed() implementations
     *
     * FFI\CData function pointers are invocable at runtime (FFI provides an engine-level
     * get_closure handler for them), which static analysis cannot model on the CData type
     * itself: the mixed-typed seam below erases the type so the callable check narrows
     * instead of contradicting.
     */
    final protected function getOriginalCallable(): callable
    {
        $originalHandler = $this->getOriginalHandlerErased();
        if (!is_callable($originalHandler)) {
            throw new \LogicException('Original handler is not available');
        }

        return $originalHandler;
    }

    /**
     * Type-erasing seam for getOriginalCallable()
     */
    private function getOriginalHandlerErased(): mixed
    {
        return $this->originalHandler;
    }

    /**
     * Returns the identity of the engine field this hook targets (container address + field)
     *
     * @internal used by the Core hook registry to build per-field chains
     */
    #[\Override]
    final public function getHookFieldKey(): string
    {
        if ($this->rawStructure instanceof FFI) {
            $containerKey = 'ffi-globals';
        } else {
            // Normalize struct containers to a pointer: pointers are 8 bytes, every raw
            // container struct is larger. FFI::typeof is avoided on purpose: probing a
            // CData's kind and then referencing it again leaks the FFI type structure,
            // see Core::cast
            $container = $this->rawStructure;
            if (FFI::sizeof($container) !== PHP_INT_SIZE) {
                $container = FFI::addr($container);
            }
            $containerKey = (string) Core::addressOf($container);
        }

        return $containerKey . '::' . static::HOOK_FIELD;
    }

    /**
     * Writes this hook's trampoline into the engine field again without touching the chain
     *
     * @internal escape hatch used by Core::reinstallHooks() for SAPIs that cycle FFI state
     */
    #[\Override]
    final public function refreshTrampoline(): void
    {
        if (!$this->installed) {
            return;
        }
        $this->rawStructure->{static::HOOK_FIELD} = Closure::fromCallable([$this, 'handle']);
    }

    /**
     * Best-effort restore for hooks that were dropped without uninstall()
     *
     * The Core registry keeps installed hooks alive, so this destructor only runs for hooks
     * that were never installed, already uninstalled, or after Core::shutdown() cleared the
     * registry - in the last case no engine memory is touched anymore.
     */
    final public function __destruct()
    {
        if (Core::isShutdown() || !$this->installed) {
            return;
        }
        if (Core::isTopHook($this)) {
            $this->uninstall();
        }
    }

    /**
     * Internal CData fields could result in segfaults, so let's hide everything
     */
    final public function __debugInfo(): array
    {
        return [
            'userHandler' => $this->userHandler,
            'installed'   => $this->installed,
        ];
    }
}
