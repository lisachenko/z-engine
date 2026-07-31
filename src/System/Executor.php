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
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;

class Executor
{
    /**
     * Contains a hashtable with all registered classes
     *
     * @var HashTable|ReflectionValue[string]
     */
    public HashTable $classTable;

    /**
     * Contains a hashtable with all registered functions
     *
     * @var HashTable|ReflectionValue[]
     */
    public HashTable $functionTable;

    /**
     * Represents the global object storage
     *
     * @var ObjectStore|ObjectEntry[]
     */
    public ObjectStore $objectStore;

    /**
     * Holds an internal pointer to the executor_globals structure
     */
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer       = $pointer;
        $this->classTable    = new HashTable($pointer->class_table);
        $this->functionTable = new HashTable($pointer->function_table);
        $this->objectStore   = new ObjectStore($pointer->objects_store);
    }

    /**
     * Returns an execution state with scope, variables, etc.
     */
    public function getExecutionState(): ExecutionData
    {
        // current_execute_data refers to the getExecutionState itself, so we move to the previous item
        $executionState = new ExecutionData($this->pointer->current_execute_data->prev_execute_data);

        return $executionState;
    }

    /**
     * Set a new fake scope and returns previous value (to restore it later)
     *
     * @return CData|null
     */
    public function setFakeScope(?CData $newScope): ?CData
    {
        $oldScope                  = $this->pointer->fake_scope;
        $this->pointer->fake_scope = $newScope;

        return $oldScope;
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
        // FFI surfaces the atomic _Bool storage as an integer scalar
        $value = $timedOut->value;
        \assert(\is_int($value) || \is_bool($value));

        return (bool) $value;
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
     * @return HashTable&iterable<int|string|null, ReflectionValue>
     */
    public function getGlobalSymbolTable(): HashTable
    {
        $symbolTable = $this->pointer->symbol_table;
        \assert($symbolTable instanceof CData);

        return new HashTable(Core::addr($symbolTable));
    }

    /**
     * Returns the table of included/required files (EG(included_files)), keyed by realpath
     *
     * Memory contract (see docs/long-running.md): EG(included_files) is a HashTable value
     * embedded into executor globals, so its address is taken to build the wrapper. The
     * returned HashTable is a BORROWED view over engine-owned storage: no ownership is
     * transferred and nothing on the PHP side may destroy it.
     *
     * @return HashTable&iterable<int|string|null, ReflectionValue>
     */
    public function getIncludedFiles(): HashTable
    {
        $includedFiles = $this->pointer->included_files;
        \assert($includedFiles instanceof CData);

        return new HashTable(Core::addr($includedFiles));
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
     */
    private function materializeHandler(CData $handlerValue): ?callable
    {
        $reflectionValue = ReflectionValue::fromValueEntry($handlerValue);
        if ($reflectionValue->getType() === ReflectionValue::IS_UNDEF) {
            return null;
        }
        $reflectionValue->getNativeValue($handler);

        return \is_callable($handler) ? $handler : null;
    }
}
