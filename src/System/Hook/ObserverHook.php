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
 *
 * <span style="color:red; font-weight: bold">Throwing functions need begin-only hooks.</span>
 * The engine invokes end handlers while unwinding a throwing frame, i.e. with EG(exception) set -
 * and ext/ffi refuses to run ANY callback in that state: zend_call_function() skips the PHP
 * closure and the trampoline aborts the process with "Throwing from FFI callbacks is not allowed"
 * before z-engine gets control. There is no userland fix (the abort happens in C, before any PHP
 * runs), so a function that can throw must be observed with a begin-only hook ($end = null); the
 * limitation is pinned by ObserverHookFiringTest and documented in docs/observer-hook.md.
 */
final class ObserverHook implements HookInterface
{
    /**
     * Prefix of the synthetic Core registry key: observer handlers are attached per zend_function,
     * not into a hookable struct field, and every target function forms its own chain
     */
    private const FIELD_KEY_PREFIX = 'observer-fcall';

    /**
     * Sentinel the engine stores in an initialised-but-unattached observer handler slot
     * (ZEND_OBSERVER_NOT_OBSERVED in zend_observer.c). The runtime add-handler API requires the
     * slot to hold a sentinel (or a real handler) - a NULL slot means "engine has not initialised
     * observer state for this function in this request yet" and must be primed first.
     */
    private const ZEND_OBSERVER_NOT_OBSERVED = 2;

    /**
     * Target zend_function pointer (the observed function/method)
     */
    private CData $function;

    /**
     * User begin callback: function(ExecutionData $frame): void
     */
    private Closure $beginHandler;

    /**
     * User end callback: function(ExecutionData $frame, ?ReflectionValue $returnValue): void
     *
     * Null for a begin-only hook: an end handler is an FFI trampoline the engine invokes while
     * unwinding a throwing frame, which ext/ffi aborts on (see the class doc), so functions that
     * may throw must be observed begin-only.
     */
    private ?Closure $endHandler;

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
     * @param CData        $function zend_function* the handlers are attached to (a
     *                               zend_internal_function* is accepted and normalized)
     * @param Closure      $begin    function(ExecutionData $frame): void
     * @param Closure|null $end      function(ExecutionData $frame, ?ReflectionValue $returnValue): void,
     *                               or null for a begin-only hook (REQUIRED for functions that can
     *                               throw: an end handler invoked during exception unwinding is
     *                               aborted by ext/ffi, see docs/observer-hook.md)
     */
    public function __construct(CData $function, Closure $begin, ?Closure $end = null)
    {
        // Normalize to the union type: the engine observer API takes zend_function*, while
        // reflection returns zend_internal_function* for internal entries
        $this->function     = Core::cast('zend_function *', $function);
        $this->beginHandler = $begin;
        $this->endHandler   = $end;

        // zend_function.type is ZEND_INTERNAL_FUNCTION (1), ZEND_USER_FUNCTION (2) or
        // ZEND_EVAL_CODE (4); the is_int() guard narrows the dynamically typed CData read to int
        $type                 = $this->function->type;
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
        if (Core::topHook($this->getHookFieldKey()) !== null) {
            // The engine reserves exactly 2*count handler slots per function; z-engine cannot
            // prove there is room for a second begin/end pair, and overflowing the block is
            // undefined behaviour in the engine (ZEND_UNREACHABLE) - refuse instead of stacking
            throw ObserverException::alreadyObserved();
        }

        $this->primeObserverSlots();
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
     * Makes sure the target function's observer handler slots exist and are initialised
     *
     * The engine initialises a function's observer slots lazily, on the function's first call in
     * a request (zend_observer_fcall_install), and its runtime add-handler API is only legal on
     * initialised slots. Two preparation steps replicate what the engine would do:
     *
     *  1. A user function's run_time_cache is allocated on demand (zend_init_func_run_time_cache)
     *     - the engine allocates it lazily on the first call anyway, and never before the slots
     *     are consulted. An internal function's cache lives in the single startup-sized block and
     *     must already exist; z-engine never grows it.
     *  2. A never-called function's slots are zero: they are primed with the engine's own
     *     NOT_OBSERVED sentinel, exactly like zend_observer_fcall_install does before attaching
     *     handlers. Priming marks the function as "installed", so other observer providers'
     *     lazy init callbacks are not consulted for this function for the rest of the request -
     *     an accepted trade-off documented in docs/observer-hook.md.
     *
     * The slot block layout is [begin(0) .. begin(count-1), end(0) .. end(count-1)] at the
     * reserved extension slot index of the function's run_time_cache, where count is the number
     * of observers registered at startup (Core::observerFcallObserverCount()).
     */
    private function primeObserverSlots(): void
    {
        $observerCount = Core::observerFcallObserverCount();
        if ($observerCount < 1) {
            throw ObserverException::observersDisabled();
        }

        $runTimeCache = $this->runTimeCacheAddress();
        if ($runTimeCache === 0) {
            if (!$this->isUserFunction) {
                // The startup-sized internal cache block must already cover this function
                throw ObserverException::observersDisabled();
            }
            $opArray = $this->function->op_array;
            assert($opArray instanceof CData);
            Core::call('zend_init_func_run_time_cache', Core::addr($opArray));
            $runTimeCache = $this->runTimeCacheAddress();
        }
        if ($runTimeCache === 0) {
            throw ObserverException::observersDisabled();
        }

        $slotIndex = Core::observerFcallExtensionSlot($this->isUserFunction);
        $beginSlot = Core::pointerAtAddress('uintptr_t *', $runTimeCache + $slotIndex * PHP_INT_SIZE);
        if ($beginSlot[0] === 0) {
            // Never called this request: initialise like zend_observer_fcall_install would
            $endSlot      = Core::pointerAtAddress('uintptr_t *', $runTimeCache + ($slotIndex + $observerCount) * PHP_INT_SIZE);
            $beginSlot[0] = self::ZEND_OBSERVER_NOT_OBSERVED;
            $endSlot[0]   = self::ZEND_OBSERVER_NOT_OBSERVED;
        }
    }

    /**
     * Resolves the target function's run_time_cache base address (0 when not allocated yet)
     */
    private function runTimeCacheAddress(): int
    {
        $common = $this->function->common;
        assert($common instanceof CData);
        $mapPtrField = $common->run_time_cache__ptr;
        assert($mapPtrField === null || $mapPtrField instanceof CData);

        return Core::mapPtrGet($mapPtrField);
    }

    /**
     * Mints begin/end trampolines and attaches them to the target function
     */
    private function attachHandlers(): void
    {
        $this->beginSlot    = Core::new('zend_observer_fcall_begin_handler[1]');
        $this->beginSlot[0] = Closure::fromCallable([$this, 'handleBegin']);
        Core::call('zend_observer_add_begin_handler', $this->function, $this->beginSlot[0]);

        if ($this->endHandler !== null) {
            $this->endSlot    = Core::new('zend_observer_fcall_end_handler[1]');
            $this->endSlot[0] = Closure::fromCallable([$this, 'handleEnd']);
            Core::call('zend_observer_add_end_handler', $this->function, $this->endSlot[0]);
        }
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
        assert($this->beginSlot !== null);
        // The out parameters are the arrays decayed to element pointers; reading an element
        // value instead would yield PHP null for an empty slot and lose the pointer identity
        $nextBegin = Core::new('zend_observer_fcall_begin_handler[1]');
        Core::call(
            'zend_observer_remove_begin_handler',
            $this->function,
            $this->beginSlot[0],
            Core::cast('zend_observer_fcall_begin_handler *', $nextBegin),
        );

        if ($this->endSlot !== null) {
            $nextEnd = Core::new('zend_observer_fcall_end_handler[1]');
            Core::call(
                'zend_observer_remove_end_handler',
                $this->function,
                $this->endSlot[0],
                Core::cast('zend_observer_fcall_end_handler *', $nextEnd),
            );
        }
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
        [$executeData] = $rawArguments;
        assert($executeData instanceof CData);
        $this->invokeContained('begin', fn() => ($this->beginHandler)(new ExecutionData($executeData)));
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
        [$executeData, $returnValue] = $rawArguments;
        assert($executeData instanceof CData);
        $endHandler = $this->endHandler;
        if ($endHandler === null) {
            // Begin-only hook: the engine never had an end trampoline to call
            return;
        }
        $return = ($returnValue instanceof CData) ? ReflectionValue::fromValueEntry($returnValue) : null;
        $this->invokeContained('end', static fn() => $endHandler(new ExecutionData($executeData), $return));
    }

    /**
     * Invokes a user observer callback with full exception containment
     *
     * This frame is entered by the engine through an FFI trampoline, so nothing may escape into
     * the engine (issue #50): a throw from the callback is downgraded to E_USER_WARNING, and even
     * a user error handler converting that warning into an exception is swallowed.
     */
    private function invokeContained(string $kind, Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $failure) {
            try {
                trigger_error(
                    "Observer {$kind} callback threw " . get_class($failure) . ': ' . $failure->getMessage(),
                    E_USER_WARNING,
                );
            } catch (\Throwable) {
                // A user error handler converted the warning into an exception: it must not
                // cross the FFI boundary either (issue #50)
            }
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
