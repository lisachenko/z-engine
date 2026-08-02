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
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;

/**
 * ObserverHook bridges the engine's zend_observer fcall begin/end handlers to userland callbacks.
 *
 * It targets one zend_function and attaches a begin and an end handler through the engine's
 * per-function runtime API (zend_observer_add_begin_handler / zend_observer_add_end_handler),
 * following the same install/uninstall/reinstall lifecycle and Core hook registry semantics as
 * OpCodeHook. Each installed hook lives under the synthetic registry key
 * "observer-fcall::<function address>", and Core::shutdown() unwinds still-installed hooks while
 * the FFI trampolines are guaranteed alive.
 *
 * <span style="color:red; font-weight: bold">Hard precondition.</span>
 * The zend_observer fcall machinery has to be enabled by a startup-time (MINIT) observer provider
 * BEFORE the hook is installed. z-engine cannot enable it from userland: zend_observer_post_startup()
 * freezes zend_observer_fcall_op_array_extension during engine startup, before the opcache.preload
 * script runs, and forcing it on afterwards corrupts every op_array/internal function whose stack
 * frame and run_time_cache were sized without an observer slot. install() therefore refuses with an
 * ObserverException when the machinery is disabled instead of corrupting memory. Only functions
 * compiled while observers were already enabled can be observed. See docs/observer-hook.md for the
 * timing analysis, the retroactive-stamping verdict and the observed/unobserved boundary.
 */
final class ObserverHook implements HookInterface
{
    /**
     * Prefix of the synthetic Core registry key: observer handlers are attached per zend_function,
     * not into a hookable struct field, and every target function forms its own chain
     */
    private const FIELD_KEY_PREFIX = 'observer-fcall';

    /**
     * Target zend_function pointer (the observed function/method)
     */
    private CData $function;

    /**
     * User begin callback: function(ExecutionData $frame): void
     */
    private Closure $beginHandler;

    /**
     * User end callback: function(ExecutionData $frame, ReflectionValue $returnValue): void
     */
    private Closure $endHandler;

    /**
     * Whether the observed function is a user (op_array) function; internal functions use a
     * different observer extension slot
     */
    private bool $isUserFunction;

    /**
     * Stable holder for the begin trampoline (kept as a struct field CData so the same function
     * pointer is used for both attach and removal, and so libffi never collects it while installed)
     *
     * @var CData|null single-element array of zend_observer_fcall_begin_handler
     */
    private ?CData $beginSlot = null;

    /**
     * Stable holder for the end trampoline
     *
     * @var CData|null single-element array of zend_observer_fcall_end_handler
     */
    private ?CData $endSlot = null;

    /**
     * Whether this hook's handlers are currently attached to the target function
     */
    private bool $installed = false;

    /**
     * @param CData   $function zend_function* the handlers are attached to
     * @param Closure $begin    function(ExecutionData $frame): void
     * @param Closure $end      function(ExecutionData $frame, ReflectionValue $returnValue): void
     */
    public function __construct(CData $function, Closure $begin, Closure $end)
    {
        $this->function     = $function;
        $this->beginHandler = $begin;
        $this->endHandler   = $end;

        // zend_function.type is ZEND_INTERNAL_FUNCTION (1), ZEND_USER_FUNCTION (2) or
        // ZEND_EVAL_CODE (4); the is_int() guard narrows the dynamically typed CData read to int
        $type                 = $function->type;
        $this->isUserFunction = is_int($type) && ($type & Core::ZEND_USER_FUNCTION) !== 0;
    }

    /**
     * Attaches the begin/end handlers to the target function (idempotent)
     *
     * Refuses (typed ObserverException) rather than corrupting memory when the engine's observer
     * machinery is unavailable: outside the preload boot path, or when observers were not enabled
     * by a startup provider for this function kind.
     */
    public function install(): void
    {
        if ($this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            throw new \LogicException('Cannot install an engine hook after Core::shutdown()');
        }
        if (!Core::isPreloaded()) {
            throw ObserverException::notPreloaded();
        }
        if (!Core::isObserverEnabled($this->isUserFunction)) {
            throw ObserverException::observersDisabled();
        }

        // A user function's run_time_cache is allocated lazily on first call; allocate it now so
        // the observer handler slot exists before the function is ever entered. Internal functions
        // share a single startup-allocated cache block and must never be grown here.
        if ($this->isUserFunction) {
            $opArray = $this->function->op_array;
            assert($opArray instanceof CData);
            Core::call('zend_init_func_run_time_cache', Core::addr($opArray));
        }

        $this->attachHandlers();

        $this->installed = true;
        Core::registerHook($this);
    }

    /**
     * Detaches the begin/end handlers from the target function (idempotent)
     *
     * Only the most recently installed hook of a function may be uninstalled: removing an older
     * hook first would leave a newer trampoline referenced by the engine.
     */
    public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }
        if (Core::isShutdown()) {
            // The engine already tore down its observer state during request shutdown
            $this->installed = false;

            return;
        }
        if (!Core::isTopHook($this)) {
            throw new \LogicException(
                'Another observer hook was installed over this one on the same function; uninstall it first',
            );
        }

        $this->detachHandlers();

        $this->installed = false;
        Core::unregisterHook($this);
    }

    /**
     * Re-installs the hook with freshly minted trampolines (uninstall + install)
     */
    public function reinstall(): void
    {
        $this->uninstall();
        $this->install();
    }

    /**
     * @inheritDoc
     */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * Observer handlers have no predecessor pointer to proceed into (z-engine attaches its own
     * begin/end handlers directly), so there is never an original handler to call.
     */
    public function hasOriginalHandler(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getHookFieldKey(): string
    {
        return self::FIELD_KEY_PREFIX . '::' . Core::addressOf($this->function);
    }

    /**
     * @inheritDoc
     */
    public function refreshTrampoline(): void
    {
        if (!$this->installed) {
            return;
        }
        // Detach the stale trampolines and mint fresh ones (SAPIs that cycle FFI callback state)
        $this->detachHandlers();
        $this->attachHandlers();
    }

    /**
     * Mints begin/end trampolines and attaches them to the target function
     */
    private function attachHandlers(): void
    {
        $this->beginSlot    = Core::new('zend_observer_fcall_begin_handler[1]');
        $this->beginSlot[0] = Closure::fromCallable([$this, 'handleBegin']);
        $this->endSlot      = Core::new('zend_observer_fcall_end_handler[1]');
        $this->endSlot[0]   = Closure::fromCallable([$this, 'handleEnd']);

        Core::call('zend_observer_add_begin_handler', $this->function, $this->beginSlot[0]);
        Core::call('zend_observer_add_end_handler', $this->function, $this->endSlot[0]);
    }

    /**
     * Detaches this hook's begin/end trampolines from the target function
     *
     * remove_*_handler reports the handler that moved into the removed slot through its out
     * parameter; z-engine does not chain observer handlers, so the scratch slot is written and
     * discarded.
     */
    private function detachHandlers(): void
    {
        assert($this->beginSlot !== null && $this->endSlot !== null);
        $nextBegin     = Core::new('zend_observer_fcall_begin_handler[1]');
        $nextEnd       = Core::new('zend_observer_fcall_end_handler[1]');
        $nextBeginSlot = $nextBegin[0];
        $nextEndSlot   = $nextEnd[0];
        assert($nextBeginSlot instanceof CData && $nextEndSlot instanceof CData);

        Core::call('zend_observer_remove_begin_handler', $this->function, $this->beginSlot[0], Core::addr($nextBeginSlot));
        Core::call('zend_observer_remove_end_handler', $this->function, $this->endSlot[0], Core::addr($nextEndSlot));
    }

    /**
     * @inheritDoc
     *
     * The zend_observer add-handler API attaches begin and end handlers separately, so
     * HookInterface::handle() is not the dispatch entry point; the engine calls handleBegin() and
     * handleEnd() directly. Provided for interface completeness only.
     *
     * @return never
     */
    public function handle(...$rawArguments): never
    {
        throw new \LogicException('ObserverHook dispatches through handleBegin()/handleEnd(), not handle()');
    }

    /**
     * FFI begin callback: void (*)(zend_execute_data *execute_data)
     *
     * Runs inside the engine while the observed frame is being entered, so it must never let an
     * exception escape into C (issue #50): a throw here would cross the FFI boundary as a fatal
     * error. Exceptions are contained and downgraded to an E_USER_WARNING.
     *
     * @param mixed ...$rawArguments Raw C arguments (zend_execute_data*)
     */
    public function handleBegin(...$rawArguments): void
    {
        try {
            [$executeData] = $rawArguments;
            assert($executeData instanceof CData);
            ($this->beginHandler)(new ExecutionData($executeData));
        } catch (\Throwable $e) {
            trigger_error(
                'Observer begin callback threw ' . get_class($e) . ': ' . $e->getMessage(),
                E_USER_WARNING,
            );
        }
    }

    /**
     * FFI end callback: void (*)(zend_execute_data *execute_data, zval *return_value)
     *
     * Same containment guarantee as handleBegin(): no exception may cross into the engine.
     *
     * @param mixed ...$rawArguments Raw C arguments (zend_execute_data*, zval*)
     */
    public function handleEnd(...$rawArguments): void
    {
        try {
            [$executeData, $returnValue] = $rawArguments;
            assert($executeData instanceof CData);
            $return = ($returnValue instanceof CData) ? ReflectionValue::fromValueEntry($returnValue) : null;
            ($this->endHandler)(new ExecutionData($executeData), $return);
        } catch (\Throwable $e) {
            trigger_error(
                'Observer end callback threw ' . get_class($e) . ': ' . $e->getMessage(),
                E_USER_WARNING,
            );
        }
    }

    /**
     * Best-effort restore for hooks dropped without uninstall()
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
            'installed'      => $this->installed,
            'isUserFunction' => $this->isUserFunction,
            'beginHandler'   => $this->beginHandler,
            'endHandler'     => $this->endHandler,
        ];
    }
}
