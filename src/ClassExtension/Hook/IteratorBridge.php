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

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use Iterator;
use Throwable;
use ZEngine\Core;
use ZEngine\Hook\HookInterface;
use ZEngine\Reflection\ReflectionValue;

/**
 * Bridge between engine-level zend_object_iterator instances and userland Iterators
 *
 * GetIteratorHook::handle() calls create() once per started iteration; the engine then
 * drives the returned zend_object_iterator through the shared funcs vtable, and every
 * vtable callback forwards to the wrapped userland Iterator.
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - ONE zend_object_iterator_funcs vtable exists per process: a persistent (malloc)
 *    block filled with libffi trampolines, immortal by design - live iterators keep
 *    pointing at it and it is malloc-backed, so no request allocator ever reclaims it.
 *    The trampolines inside are owned by ext/ffi (freed by its RSHUTDOWN, re-mintable
 *    via refreshTrampoline() for SAPIs that cycle FFI callback state).
 *  - Each zend_object_iterator is allocated with the REQUEST allocator (FFI emalloc,
 *    non-owned) and handed over to the engine with refcount 1: zend_iterator_init()
 *    registers it in the objects store, the engine releases it (OBJ_RELEASE) when the
 *    loop ends or breaks, and the objects store calls our dtor callback and then
 *    efree()s the memory itself. z-engine never frees iterator memory.
 *  - iterator.data is the engine-side slot for the CURRENT value: get_current_data()
 *    writes the userland current() value into it (releasing the previous one, engine
 *    assignment semantics) and returns its address; the dtor callback releases the
 *    last held reference. The wrapped userland Iterator itself is kept alive by a
 *    PHP-side registry entry keyed by the iterator address, added in create() and
 *    dropped in the dtor callback.
 *  - invalidate_current/get_gc vtable slots stay NULL: the engine checks them for
 *    NULL before calling (get_gc reports no owned GC children - the userland Iterator
 *    is owned by the PHP-side registry, which the PHP GC scans normally).
 *
 * The bridge registers itself in the Core hook registry (like OpCodeHook, it implements
 * HookInterface directly with a synthetic slot key). Core::shutdown() therefore
 * neutralizes it while trampolines are still alive: any iterator that survived until
 * user shutdown (eg inside a suspended generator held by a global) has its current-value
 * reference released and its std.handlers swapped to std_object_handlers, so the
 * objects-store teardown later in the request frees it WITHOUT calling into freed
 * libffi trampolines - preserving the "no trampoline pointer survives Core::shutdown()"
 * invariant.
 *
 * Exception rule (issue #50): every callback here is entered by the engine through an
 * FFI trampoline; ext/ffi aborts the process if EG(exception) is set when a callback
 * returns, so a Throwable from the wrapped Iterator can neither escape nor be rethrown
 * into the engine. The bridge catches it, reports an E_USER_WARNING and terminates the
 * iteration cleanly (valid() reports the end from then on).
 *
 * @internal used by GetIteratorHook
 */
final class IteratorBridge implements HookInterface
{
    private static ?IteratorBridge $instance = null;

    /**
     * Process-lifetime zend_object_iterator_funcs vtable (persistent, immortal by design)
     */
    private static ?CData $vtable = null;

    /**
     * Live bridged iterators keyed by zend_object_iterator address
     *
     * @var array<int, BridgedIterator>
     */
    private static array $activeIterators = [];

    private bool $installed = false;

    private function __construct() {}

    /**
     * Allocates a new engine iterator driving the given userland Iterator
     *
     * The returned zend_object_iterator* carries refcount 1 which is handed over to the
     * engine caller (FE_RESET semantics); the engine releases it through the objects store.
     *
     * @return \FFI\CData
     */
    public static function create(Iterator $userIterator): object
    {
        self::instance()->install();

        // Request-allocator memory (FFI emalloc), non-owned: after zend_iterator_init the
        // engine objects store owns it and efree()s it when the last reference dies
        $iterator = Core::new('zend_object_iterator', false);
        $pointer  = Core::addr($iterator);

        Core::call('zend_iterator_init', $pointer);
        assert(self::$vtable !== null);
        $iterator->funcs = Core::addr(self::$vtable);
        // FFI zero-fills new blocks, so iterator.data already reads as IS_UNDEF

        self::$activeIterators[Core::addressOf($pointer)] = new BridgedIterator($userIterator, $pointer);

        return $pointer;
    }

    /**
     * Returns the number of live bridged iterators (test/diagnostic aid)
     */
    public static function activeIteratorCount(): int
    {
        return count(self::$activeIterators);
    }

    public static function instance(): IteratorBridge
    {
        return self::$instance ??= new IteratorBridge();
    }

    /**
     * The bridge occupies no single engine slot, its callbacks live in the shared vtable
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): never
    {
        throw new \LogicException('IteratorBridge callbacks are dispatched through the funcs vtable');
    }

    /**
     * Mints the persistent vtable and registers the bridge in the Core hook registry (idempotent)
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
        if (self::$vtable === null) {
            self::$vtable = Core::new('zend_object_iterator_funcs', false, true);
        }
        $this->refreshTrampoline();
        $this->installed = true;
        Core::registerHook($this);
    }

    /**
     * Neutralizes every live bridged iterator and unregisters the bridge (idempotent)
     *
     * Invoked by Core::shutdown() while libffi trampolines are still valid: surviving
     * iterators drop their current-value reference and are downgraded to plain engine
     * objects (std_object_handlers), so the objects-store teardown later in the request
     * shutdown never calls into the (by then freed) trampolines. The persistent vtable
     * block itself stays allocated - it is immortal by design.
     */
    #[\Override]
    public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }
        if (!Core::isShutdown()) {
            foreach (self::$activeIterators as $state) {
                $iterator = $state->pointer;
                self::releaseCurrentData($iterator);
                $std = $iterator->std;
                assert($std instanceof CData);
                $std->handlers = Core::addr(Core::getStandardObjectHandlers());
            }
        }
        self::$activeIterators = [];
        $this->installed       = false;
        Core::unregisterHook($this);
    }

    #[\Override]
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    #[\Override]
    public function hasOriginalHandler(): bool
    {
        return false;
    }

    #[\Override]
    public function getHookFieldKey(): string
    {
        return 'iterator-bridge::zend_object_iterator_funcs';
    }

    /**
     * (Re-)mints the vtable trampolines in place
     *
     * Used at install time and by Core::reinstallHooks() for SAPIs that cycle ext/ffi
     * callback state between handled requests.
     */
    #[\Override]
    public function refreshTrampoline(): void
    {
        if (self::$vtable === null) {
            return;
        }
        $vtable                   = self::$vtable;
        $vtable->dtor             = static fn(CData $iterator) => self::iteratorDtor($iterator);
        $vtable->valid            = static fn(CData $iterator): int => self::iteratorValid($iterator);
        $vtable->get_current_data = static fn(CData $iterator): ?CData => self::iteratorCurrentData($iterator);
        $vtable->get_current_key  = static fn(CData $iterator, CData $key) => self::iteratorCurrentKey($iterator, $key);
        $vtable->move_forward     = static fn(CData $iterator) => self::iteratorMoveForward($iterator);
        $vtable->rewind           = static fn(CData $iterator) => self::iteratorRewind($iterator);
        // invalidate_current and get_gc intentionally stay NULL (optional slots, engine
        // NULL-checks them; a NULL get_gc reports no owned children)
    }

    /**
     * void (*dtor)(zend_object_iterator *iter): last engine reference died
     *
     * Called by the objects store free handler right before it efree()s the iterator
     * memory: releases the cached current-value reference and drops the registry entry
     * (which releases the wrapped userland Iterator through normal PHP refcounting).
     *
     * @param \FFI\CData $iterator
     */
    private static function iteratorDtor(object $iterator): void
    {
        $address = Core::addressOf($iterator);
        if (!isset(self::$activeIterators[$address])) {
            return;
        }
        unset(self::$activeIterators[$address]);
        self::releaseCurrentData($iterator);
    }

    /**
     * Releases the reference cached in iter->data and stamps the slot IS_UNDEF
     *
     * The defensive IS_UNDEF stamp makes a hypothetical second release pass a no-op.
     *
     * @param \FFI\CData $iterator
     */
    private static function releaseCurrentData(object $iterator): void
    {
        $data = $iterator->data;
        assert($data instanceof CData);
        Core::call('zval_ptr_dtor', Core::addr($data));
        $dataTypeUnion = $data->u1;
        assert($dataTypeUnion instanceof CData);
        $dataTypeUnion->type_info = ReflectionValue::IS_UNDEF;
    }

    /**
     * zend_result (*valid)(zend_object_iterator *iter)
     *
     * @param \FFI\CData $iterator
     */
    private static function iteratorValid(object $iterator): int
    {
        $state = self::$activeIterators[Core::addressOf($iterator)] ?? null;
        if ($state === null || $state->broken) {
            return Core::FAILURE;
        }
        try {
            return $state->iterator->valid() ? Core::SUCCESS : Core::FAILURE;
        } catch (Throwable $error) {
            self::breakIteration($state, 'valid', $error);

            return Core::FAILURE;
        }
    }

    /**
     * zval *(*get_current_data)(zend_object_iterator *iter)
     *
     * Writes the userland current() value into the engine-owned iter->data slot (previous
     * value released with full assignment semantics) and returns the slot address. A NULL
     * return (broken iteration) makes the engine exit the loop cleanly.
     *
     * @param \FFI\CData $iterator
     * @return \FFI\CData|null
     */
    private static function iteratorCurrentData(object $iterator): ?object
    {
        $state = self::$activeIterators[Core::addressOf($iterator)] ?? null;
        if ($state === null || $state->broken) {
            return null;
        }
        try {
            $value = $state->iterator->current();
        } catch (Throwable $error) {
            self::breakIteration($state, 'current', $error);

            return null;
        }
        $data = $iterator->data;
        assert($data instanceof CData);
        ReflectionValue::fromValueEntry(Core::addr($data))->setNativeValue($value);

        return Core::addr($data);
    }

    /**
     * void (*get_current_key)(zend_object_iterator *iter, zval *key)
     *
     * The key slot is an uninitialized engine output zval: exactly one owned reference is
     * written into it (the engine releases it), never a released/previous value.
     *
     * @param \FFI\CData $iterator
     * @param \FFI\CData $key
     */
    private static function iteratorCurrentKey(object $iterator, object $key): void
    {
        $state    = self::$activeIterators[Core::addressOf($iterator)] ?? null;
        $keyValue = null;
        if ($state !== null && !$state->broken) {
            try {
                $keyValue = $state->iterator->key();
            } catch (Throwable $error) {
                self::breakIteration($state, 'key', $error);
                $keyValue = null;
            }
        }
        ReflectionValue::fromValueEntry($key)->initializeNativeValue($keyValue);
    }

    /**
     * void (*move_forward)(zend_object_iterator *iter)
     *
     * @param \FFI\CData $iterator
     */
    private static function iteratorMoveForward(object $iterator): void
    {
        $state = self::$activeIterators[Core::addressOf($iterator)] ?? null;
        if ($state === null || $state->broken) {
            return;
        }
        try {
            $state->iterator->next();
        } catch (Throwable $error) {
            self::breakIteration($state, 'next', $error);
        }
    }

    /**
     * void (*rewind)(zend_object_iterator *iter)
     *
     * @param \FFI\CData $iterator
     */
    private static function iteratorRewind(object $iterator): void
    {
        $state = self::$activeIterators[Core::addressOf($iterator)] ?? null;
        if ($state === null || $state->broken) {
            return;
        }
        try {
            $state->iterator->rewind();
        } catch (Throwable $error) {
            self::breakIteration($state, 'rewind', $error);
        }
    }

    /**
     * Records a Throwable that cannot cross the FFI boundary (issue #50)
     *
     * The iteration is marked broken - valid() reports the end from now on, so the engine
     * terminates the loop cleanly - and the swallowed Throwable is surfaced as a warning.
     * The caller already holds the state record, so the flag is written straight through it.
     */
    private static function breakIteration(BridgedIterator $state, string $method, Throwable $error): void
    {
        $state->broken = true;

        $iteratorClass = get_class($state->iterator);
        trigger_error(
            "Engine iteration terminated: {$iteratorClass}::{$method}() threw " . get_class($error)
            . ": {$error->getMessage()} (exceptions cannot cross the FFI boundary, see issue #50)",
            E_USER_WARNING,
        );
    }
}
