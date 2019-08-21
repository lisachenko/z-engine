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

namespace ZEngine;

use FFI;
use FFI\CData;

class ExecutionDataEntry
{
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * Returns the currently executed opline
     */
    public function getOpline(): OpCodeLine
    {
        return new OpCodeLine($this->pointer->opline);
    }

    /**
     * Returns the "return value"
     */
    public function getReturnValue(): ValueEntry
    {
        return new ValueEntry($this->pointer->return_value);
    }

    /**
     * Returns the current function entry
     */
    public function getFunction(): FunctionEntry
    {
        if ($this->pointer->func === null) {
            throw new \InvalidArgumentException('Function entry is not available in the current context');
        }

        return new FunctionEntry($this->pointer->func);
    }

    /**
     * Returns the current object scope
     */
    public function getThis(): ValueEntry
    {
        return new ValueEntry(FFI::addr($this->pointer->This));
    }

    /**
     * Checks if there is a previous execution entry (aka stack)
     */
    public function hasPrevious(): bool
    {
        return $this->pointer->prev_execute_data !== null;
    }

    /**
     * Returns the previous execution data entry (aka stack)
     */
    public function getPrevious(): ExecutionDataEntry
    {
        if ($this->pointer->prev_execute_data === null) {
            throw new \LogicException('There is no previous execution data. Top of the stack?');
        }
        return new ExecutionDataEntry($this->pointer->prev_execute_data);
    }

    public function getSymbolTable(): HashTable
    {
        return new HashTable($this->pointer->symbol_table);
    }
}
