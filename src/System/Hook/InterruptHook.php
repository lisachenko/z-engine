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

namespace ZEngine\System\Hook;

use FFI\CData;
use ZEngine\Hook\AbstractHook;
use ZEngine\System\ExecutionData;

/**
 * Receiving hook for the VM interrupt callback (zend_interrupt_function)
 *
 * The engine invokes this callback at the next VM interrupt check after
 * EG(vm_interrupt) is raised (see Executor::requestInterrupt()) - interrupt
 * checks sit on loop back-edges and function entries, so the callback fires at
 * a well-defined opcode boundary of whatever user code is currently running.
 * This is the engine's asynchronous "break" primitive: pcntl and timeout
 * handling are built on the same mechanism.
 *
 * The callback runs as ordinary PHP inside the interrupted frame, so a handler
 * may inspect the stack (getExecutionData()) and even block - the interrupted
 * code continues only when the handler returns.
 *
 * <span style="color:red; font-weight: bold">Warning!</span> The handler runs
 * inside an FFI callback: throwing (even indirectly) is a fatal engine error.
 * In particular ExecutionData::getFunction() throws for frames the native
 * reflection cannot resolve by name (closures) - prefer getFunctionEntry() or
 * wrap frame inspection in try/catch inside the handler.
 */
class InterruptHook extends AbstractHook
{
    protected const HOOK_FIELD = 'zend_interrupt_function';

    /**
     * Raw zend_execute_data pointer of the interrupted frame
     */
    protected CData $executeData;

    /**
     * typedef void (*zend_interrupt_function)(zend_execute_data *execute_data);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$this->executeData] = $rawArguments;

        ($this->userHandler)($this);
    }

    /**
     * Returns the interrupted stack frame
     */
    public function getExecutionData(): ExecutionData
    {
        return new ExecutionData($this->executeData);
    }

    /**
     * Proceeds with the previous interrupt callback (if one was installed)
     *
     * Unlike most engine hooks the default value of this pointer is NULL, so
     * callers should check hasOriginalHandler() first: proceeding is only
     * meaningful when another consumer (pcntl, another hook) was chained.
     */
    public function proceed(): void
    {
        ($this->getOriginalCallable())($this->executeData);
    }
}
