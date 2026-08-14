<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\System;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;

class Executor
{
    /**
     * The engine views below are bound to their executor-globals table once, in the constructor,
     * and are therefore published as `public private(set)`: everybody may read (and mutate through)
     * the wrapper, nobody may swap the wrapper itself. A replaced view would silently detach the
     * whole process from the engine table it is supposed to reflect.
     */

    /**
     * Contains a hashtable with all registered classes
     *
     * @var HashTable|ReflectionValue[string]
     */
    public private(set) HashTable $classTable;

    /**
     * Contains a hashtable with all registered functions
     *
     * @var HashTable|ReflectionValue[]
     */
    public private(set) HashTable $functionTable;

    /**
     * Contains a hashtable with all registered constants (EG(zend_constants))
     *
     * Bucket values are IS_PTR zvals pointing to zend_constant structures, keyed by the
     * case-sensitive constant name (including persistent engine/extension constants).
     */
    public private(set) HashTable $constantTable;

    /**
     * Represents the global object storage
     *
     * @var ObjectStore|ObjectEntry[]
     */
    public private(set) ObjectStore $objectStore;

    /**
     * Holds an internal pointer to the executor_globals structure
     */
    private CData $pointer;
    /**
     * @param \FFI\CData $pointer
     */

    public function __construct(object $pointer)
    {
        $this->pointer = $pointer;

        $classTable = $pointer->class_table;
        \assert($classTable instanceof CData);
        $functionTable = $pointer->function_table;
        \assert($functionTable instanceof CData);

        $this->classTable    = HashTable::fromCData($classTable);
        $this->functionTable = HashTable::fromCData($functionTable);
        $zendConstants       = $pointer->zend_constants;
        \assert($zendConstants instanceof CData);
        $this->constantTable = HashTable::fromCData($zendConstants);
        $this->objectStore   = new ObjectStore($pointer->objects_store);
    }

    /**
     * Returns an execution state with scope, variables, etc.
     */
    public function getExecutionState(): ExecutionData
    {
        // current_execute_data refers to the getExecutionState itself, so we move to the previous item
        $frame = $this->pointer->current_execute_data->prev_execute_data;
        assert($frame instanceof CData);
        $executionState = new ExecutionData($frame);

        return $executionState;
    }

    /**
     * Set a new fake scope and returns previous value (to restore it later)
     *
     * @return CData|null
     * @param \FFI\CData|zend_class_entry|null $newScope
     */
    public function setFakeScope(?object $newScope): ?object
    {
        $oldScope                  = $this->pointer->fake_scope;
        $this->pointer->fake_scope = $newScope;

        return $oldScope;
    }

    /**
     * Runs the given callback under a temporary fake scope, restoring the previous one afterwards
     *
     * EG(fake_scope) drives the visibility checks of the engine's default object handlers, so it
     * is request-global state that must be handed back no matter how the callback leaves: a
     * throwing __get(), a typed-property error or an uninitialized readonly access would
     * otherwise leave the whole rest of the request running under a foreign scope. Restoration
     * therefore happens in a finally block, and this helper is the only supported way to install
     * a temporary scope - never pair setFakeScope() calls by hand.
     *
     * @param \FFI\CData|zend_class_entry|null $scope Class entry to impersonate, or null to drop the fake scope
     * @param \Closure                         $body  Callback to invoke while the fake scope is installed
     *
     * @return mixed Whatever the callback returned
     */
    public function withFakeScope(?object $scope, \Closure $body): mixed
    {
        $previousScope = $this->setFakeScope($scope);

        try {
            return $body();
        } finally {
            $this->setFakeScope($previousScope);
        }
    }

    /**
     * Checks if the engine carries an exception in flight (EG(exception) != NULL)
     *
     * Memory contract: pure pointer comparison, no refcount changes.
     */
    public function hasException(): bool
    {
        return $this->pointer->exception !== null;
    }

    /**
     * Returns the exception the engine currently carries in EG(exception), if any
     *
     * Memory contract (see docs/long-running.md): EG(exception) stays engine-owned. The
     * zend_object pointer is wrapped with a BORROWED ObjectEntry behind a null guard (no
     * addref on the engine slot), and materializing the returned Throwable takes a regular
     * reference for the returned PHP variable only. The slot itself is left untouched, so
     * a propagating exception keeps propagating exactly as before (refcount-neutral).
     *
     * The engine also parks non-Throwable sentinel objects in this slot (unwind-exit and
     * graceful-exit markers); those are reported as null.
     */
    public function getCurrentException(): ?\Throwable
    {
        $exception = $this->pointer->exception;
        if ($exception === null) {
            return null;
        }
        \assert($exception instanceof CData);
        $entry     = ObjectEntry::fromCData($exception);
        $throwable = $entry->getNativeValue();

        return $throwable instanceof \Throwable ? $throwable : null;
    }

    /**
     * Suppresses the exception the engine currently carries in EG(exception), if any
     *
     * Goes through zend_clear_exception(): the exception object (and a parked
     * EG(prev_exception), if present) is properly released and the VM opline is restored
     * from EG(opline_before_exception). A raw `EG(exception) = NULL` write would leak the
     * object and desync opline_before_exception, so it is deliberately not offered.
     *
     * No-op when no exception is in flight. Only callable from a context where PHP code
     * legitimately runs while EG(exception) is set (an engine-invoked callback that sets
     * the exception itself); the body performs no internal engine calls before the clear,
     * so the pending exception cannot be rethrown halfway through.
     */
    public function suppressCurrentException(): void
    {
        if ($this->pointer->exception === null) {
            return;
        }
        Core::call('zend_clear_exception');
    }

    /**
     * Returns the current error_reporting level (EG(error_reporting))
     */
    public function getErrorReporting(): int
    {
        $level = $this->pointer->error_reporting;
        \assert(\is_int($level));

        return $level;
    }

    /**
     * Sets a new error_reporting level and returns the previous one, like error_reporting()
     *
     * Note: this writes the runtime EG(error_reporting) field directly, bypassing the INI
     * subsystem - ini_get('error_reporting') will not observe the change, exactly as with
     * an engine-level modification.
     */
    public function setErrorReporting(int $level): int
    {
        $previous = $this->getErrorReporting();

        $this->pointer->error_reporting = $level;

        return $previous;
    }

    /**
     * Returns the user error handler installed via set_error_handler(), or null if none is set
     *
     * Memory contract: EG(user_error_handler) is an engine-owned embedded zval; it is read
     * through a BORROWED ReflectionValue view and materialized into a PHP callable that
     * holds its own reference. The engine slot is left untouched (refcount-neutral).
     */
    public function getUserErrorHandler(): ?callable
    {
        $handlerValue = $this->pointer->user_error_handler;
        \assert($handlerValue instanceof CData);

        return $this->materializeHandler($handlerValue);
    }

    /**
     * Returns the user exception handler installed via set_exception_handler(), or null if none is set
     *
     * Memory contract: same BORROWED, refcount-neutral read as getUserErrorHandler().
     */
    public function getUserExceptionHandler(): ?callable
    {
        $handlerValue = $this->pointer->user_exception_handler;
        \assert($handlerValue instanceof CData);

        return $this->materializeHandler($handlerValue);
    }

    /**
     * Returns the process exit status (EG(exit_status)), the code exit() was called with
     */
    public function getExitStatus(): int
    {
        $exitStatus = $this->pointer->exit_status;
        \assert(\is_int($exitStatus));

        return $exitStatus;
    }

    /**
     * Returns the float-to-string conversion precision (EG(precision), the "precision" ini)
     */
    public function getPrecision(): int
    {
        $precision = $this->pointer->precision;
        \assert(\is_int($precision));

        return $precision;
    }

    /**
     * Returns the script timeout in seconds (EG(timeout_seconds), the "max_execution_time" ini)
     */
    public function getTimeoutSeconds(): int
    {
        $timeoutSeconds = $this->pointer->timeout_seconds;
        \assert(\is_int($timeoutSeconds));

        return $timeoutSeconds;
    }

    /**
     * Checks if the engine has hit the execution timeout (EG(timed_out))
     *
     * The underlying field is a zend_atomic_bool written by the timeout signal handler;
     * this accessor is strictly read-only.
     */
    public function isTimedOut(): bool
    {
        $timedOut = $this->pointer->timed_out;
        \assert($timedOut instanceof CData);
        // C11 builds surface the atomic _Bool storage as an integer scalar;
        // MSVC's fallback stores the flag in a char, which FFI reads back as
        // a one-byte binary string
        $value = $timedOut->value;
        \assert(\is_int($value) || \is_bool($value) || \is_string($value));

        return \is_string($value) ? $value !== "\0" : (bool) $value;
    }

    /**
     * Raises the VM interrupt flag (EG(vm_interrupt)) so the engine calls the
     * interrupt callback at the next interrupt check
     *
     * Interrupt checks sit on loop back-edges and function entries, so the callback
     * installed via Core::setInterruptHandler() fires at the next such boundary of
     * whatever code is currently executing. This is how the engine's own async
     * consumers (pcntl, the request timeout) get scheduled; without an installed
     * callback the flag is consumed by the timeout check alone.
     *
     * The field is a zend_atomic_bool written here as a plain byte store - the same
     * relaxed store the engine's non-C11 fallback performs; FFI offers no atomic RMW,
     * and the single-writer/flag semantics of vm_interrupt do not need one.
     */
    public function requestInterrupt(): void
    {
        $interrupt = $this->pointer->vm_interrupt;
        \assert($interrupt instanceof CData);
        $interrupt->value = 1;
    }

    /**
     * Returns the global symbol table (EG(symbol_table)) - the true global variable scope
     *
     * Memory contract (see docs/long-running.md): EG(symbol_table) is a zend_array value
     * embedded into executor globals, so its address is taken to build the wrapper. The
     * returned HashTable is a BORROWED view over engine-owned storage: no ownership is
     * transferred, nothing on the PHP side may destroy it, and it stays valid for the
     * whole request lifetime.
     *
     * @return HashTable&iterable<int|string, ReflectionValue>
     */
    public function getGlobalSymbolTable(): HashTable
    {
        $symbolTable = $this->pointer->symbol_table;
        \assert($symbolTable instanceof CData);

        return HashTable::fromCData(Core::addr($symbolTable));
    }

    /**
     * Returns the table of included/required files (EG(included_files)), keyed by realpath
     *
     * Memory contract (see docs/long-running.md): EG(included_files) is a HashTable value
     * embedded into executor globals, so its address is taken to build the wrapper. The
     * returned HashTable is a BORROWED view over engine-owned storage: no ownership is
     * transferred and nothing on the PHP side may destroy it.
     *
     * @return HashTable&iterable<int|string, ReflectionValue>
     */
    public function getIncludedFiles(): HashTable
    {
        $includedFiles = $this->pointer->included_files;
        \assert($includedFiles instanceof CData);

        return HashTable::fromCData(Core::addr($includedFiles));
    }

    /**
     * Requests the full-table cleanup mode for the current request shutdown
     *
     * Mirrors what dl() does when it loads a temporary extension mid-request: with
     * EG(full_tables_cleanup) set the engine walks the complete module registry at request
     * shutdown instead of the handler lists precomputed at process startup, so modules
     * registered at runtime are properly deactivated and temporary ones destroyed at
     * request end.
     */
    public function enableFullTablesCleanup(): void
    {
        $this->pointer->full_tables_cleanup = 1;
    }

    /**
     * Materializes a user handler slot (an engine-owned embedded zval) into a PHP callable
     *
     * Memory contract: the slot is read through a BORROWED ReflectionValue view; an unset
     * handler (IS_UNDEF) yields null, otherwise the returned callable holds its own regular
     * reference while the engine slot stays untouched (refcount-neutral).
     *
     * @param \FFI\CData $handlerValue
     */
    private function materializeHandler(object $handlerValue): ?callable
    {
        $reflectionValue = ReflectionValue::fromValueEntry($handlerValue);
        if ($reflectionValue->getType() === ReflectionValue::IS_UNDEF) {
            return null;
        }
        $reflectionValue->getNativeValue($handler);

        return \is_callable($handler) ? $handler : null;
    }
}
