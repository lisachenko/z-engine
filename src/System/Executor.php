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
        $oldScope = $this->pointer->fake_scope;
        $this->pointer->fake_scope = $newScope;

        return $oldScope;
    }
}
