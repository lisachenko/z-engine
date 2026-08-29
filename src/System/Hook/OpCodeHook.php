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

namespace ZEngine\System\Hook;

use Closure;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Hook\HookInterface;
use ZEngine\System\ExecutionData;

/**
 * OpCodeHook integrates user opcode handlers with the engine hook lifecycle
 *
 * Not an AbstractHook subclass: AbstractHook's final install() writes an engine struct
 * field, while user opcode handlers are installed via zend_set_user_opcode_handler().
 * This class implements HookInterface directly with the same chaining semantics:
 * install() captures the previously installed handler via zend_get_user_opcode_handler()
 * and registers itself in the Core hook registry under the synthetic per-opcode key
 * "user-opcode::<opcode>", uninstall() restores the captured handler, and Core::shutdown()
 * unwinds still-installed opcode hooks exactly like struct-field hooks.
 *
 * A user handler that returns Core::ZEND_USER_OPCODE_DISPATCH chains to the previously
 * installed user handler (if any) before the engine falls back to the VM opcode handler.
 */
final class OpCodeHook implements HookInterface
{
    /**
     * Prefix of the synthetic Core registry key: user opcode handlers live in the engine's
     * zend_user_opcode_handlers table (not in a hookable struct field), and every opcode
     * forms its own independent chain
     */
    private const string FIELD_KEY_PREFIX = 'user-opcode';

    /**
     * Class-name prefix of z-engine's own code: opcodes executed by a frame whose scope
     * starts with it never reach a user handler, which keeps the framework from calling
     * back into itself (see docs/self-debugging.md)
     */
    private const string ENGINE_SCOPE_PREFIX = 'ZEngine';

    /**
     * Custom user handler with signature function($scope): int
     */
    private Closure $userHandler;

    /**
     * Hooked operation code, one of OpCode::* constants
     */
    private int $opCode;

    /**
     * Previously installed user opcode handler (if present), captured at install() time
     *
     * typedef int (*user_opcode_handler_t)(zend_execute_data *execute_data);
     */
    private ?CData $originalHandler = null;

    /**
     * Whether this hook's trampoline is currently installed as the user opcode handler
     */
    private bool $installed = false;

    public function __construct(int $opCode, Closure $userHandler)
    {
        self::ensureValidOpCodeHandler($userHandler);
        $this->opCode      = $opCode;
        $this->userHandler = $userHandler;
    }

    /**
     * Performs installation of current hook (idempotent)
     *
     * The previous user opcode handler is captured here (not in the constructor), so
     * stacking several hooks on the same opcode forms a well-defined chain where each hook
     * restores its predecessor. The Core registry keeps a strong reference to the installed
     * hook: the trampoline can never be collected while the engine still points at it.
     */
    #[\Override]
    public function install(): void
    {
        if ($this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            throw new \LogicException('Cannot install an engine hook after Core::shutdown()');
        }
        // PHP 8.6's tail-call VM mis-resumes execution after a user opcode handler: the
        // generated ZEND_USER_OPCODE_SPEC_TAILCALL_HANDLER returns the single-step
        // dispatch result up the musttail chain, and execute_ex() then continues with its
        // stale frame pointer - any handler firing outside execute_ex's entry frame
        // executes the following oplines against the WRONG frame (wrong run-time cache,
        // wrong CVs), corrupting the debuggee. Refuse loudly instead (issue #280).
        if (Core::vmKind() === Core::VM_KIND_TAILCALL) {
            throw OpCodeHookException::tailCallVmUnsupported();
        }
        $previousHandler = Core::call('zend_get_user_opcode_handler', $this->opCode);
        assert($previousHandler === null || $previousHandler instanceof CData);
        $this->originalHandler = $previousHandler;

        $result = Core::call('zend_set_user_opcode_handler', $this->opCode, $this->handle(...));
        if ($result === Core::FAILURE) {
            throw OpCodeHookException::handlerInstallFailed();
        }
        $this->installed = true;
        Core::registerHook($this);
    }

    /**
     * Restores the previously captured user opcode handler (idempotent)
     *
     * Only the most recently installed hook of an opcode may be uninstalled: restoring an
     * older hook first would clobber the newer trampoline with a stale pointer.
     */
    #[\Override]
    public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            // The engine handler table was already restored/abandoned during shutdown
            $this->installed = false;

            return;
        }
        if (!Core::isTopHook($this)) {
            throw new \LogicException(
                'Another hook was installed over this one on the same engine field; uninstall it first',
            );
        }

        $result = Core::call('zend_set_user_opcode_handler', $this->opCode, $this->originalHandler);
        if ($result === Core::FAILURE) {
            throw OpCodeHookException::handlerRestoreFailed();
        }
        $this->installed = false;
        Core::unregisterHook($this);
    }

    /**
     * Re-installs the hook with a freshly minted trampoline (uninstall + install)
     */
    public function reinstall(): void
    {
        $this->uninstall();
        $this->install();
    }

    /**
     * Checks if this hook is currently installed as the user opcode handler
     */
    #[\Override]
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * Checks if a previously installed user opcode handler was captured at install() time
     */
    #[\Override]
    public function hasOriginalHandler(): bool
    {
        return $this->originalHandler !== null;
    }

    /**
     * Returns the hooked operation code (one of OpCode::* constants)
     */
    public function getOpCode(): int
    {
        return $this->opCode;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getHookFieldKey(): string
    {
        return self::fieldKeyFor($this->opCode);
    }

    /**
     * Returns the synthetic Core registry key for the given opcode
     *
     * @internal used by OpCode::restoreHandler() to look up the active chain
     */
    public static function fieldKeyFor(int $opCode): string
    {
        return self::FIELD_KEY_PREFIX . '::' . $opCode;
    }

    /**
     * @inheritDoc
     *
     * Core::reinstallHooks() refreshes chains in installation order, so for stacked hooks
     * the top hook's trampoline is written last and stays the one the engine dispatches to.
     */
    #[\Override]
    public function refreshTrampoline(): void
    {
        if (!$this->installed) {
            return;
        }
        Core::call('zend_set_user_opcode_handler', $this->opCode, $this->handle(...));
    }

    /**
     * Handles the hooked opcode and returns one of ZEND_USER_OPCODE_* codes
     *
     * typedef int (*user_opcode_handler_t)(zend_execute_data *execute_data);
     *
     * The frame that executes the opcode is the callback argument itself, so the class
     * scope that decides whether z-engine's own code is running is read straight off it
     * (see ExecutionData::getScopeClass()) - no stack walk is needed, and none is
     * affordable here: this runs on EVERY execution of the hooked opcode. Stacked hooks
     * pass the very same execute_data down the chain, so a delegated call resolves the
     * same executing frame as the top hook did, with no delegation frames to skip.
     *
     * @param mixed ...$rawArguments Raw C arguments of this callback (zend_execute_data*)
     */
    #[\Override]
    public function handle(...$rawArguments): int
    {
        [$state] = $rawArguments;
        assert($state instanceof CData);

        $executionState = new ExecutionData($state);
        $frameScope     = $executionState->getScopeClass()?->getName();
        if ($frameScope !== null && str_starts_with($frameScope, self::ENGINE_SCOPE_PREFIX)) {
            // For all our internal classes just proceed with default opcode handler

            return Core::ZEND_USER_OPCODE_DISPATCH;
        }

        $handleResult = ($this->userHandler)($executionState);
        assert(is_int($handleResult));

        if ($handleResult === Core::ZEND_USER_OPCODE_DISPATCH && $this->originalHandler !== null) {
            // Chain to the user handler that was installed on this opcode before this hook
            $dispatchResult = ($this->originalHandler)($state); // @phpstan-ignore callable.nonCallable (CData function pointer is callable)
            assert(is_int($dispatchResult));

            return $dispatchResult;
        }

        return $handleResult;
    }

    /**
     * Best-effort restore for hooks that were dropped without uninstall()
     *
     * The Core registry keeps installed hooks alive, so this destructor only runs for hooks
     * that were never installed, already uninstalled, or after Core::shutdown() cleared the
     * registry - in the last case no engine memory is touched anymore.
     */
    public function __destruct()
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
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'opCode'      => $this->opCode,
            'userHandler' => $this->userHandler,
            'installed'   => $this->installed,
        ];
    }

    /**
     * Ensures that given callback can be used as opcode handler, otherwise throws an error
     *
     * @param Closure $userHandler User-defined opcode handler
     */
    private static function ensureValidOpCodeHandler(Closure $userHandler): void
    {
        $reflection = new \ReflectionFunction($userHandler);

        $hasOneArgument     = $reflection->getNumberOfParameters() === 1;
        $returnType         = $reflection->getReturnType();
        $hasValidReturnType = $returnType instanceof \ReflectionNamedType && $returnType->getName() === 'int';
        if (!$hasValidReturnType || !$hasOneArgument) {
            throw new \InvalidArgumentException('Opcode handler signature should be: function($scope): int {}');
        }
    }
}
