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
use ZEngine\Reflection\FunctionLikeTrait;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\OpLine;

/*
 * Stack Frame Layout (the whole stack frame is allocated at once)
 * ==================
 *
 *                             +========================================+
 * EG(current_execute_data) -> | zend_execute_data                      |
 *                             +----------------------------------------+
 *     EX_VAR_NUM(0) --------> | VAR[0] = ARG[1]                        |
 *                             | ...                                    |
 *                             | VAR[op_array->num_args-1] = ARG[N]     |
 *                             | ...                                    |
 *                             | VAR[op_array->last_var-1]              |
 *                             | VAR[op_array->last_var] = TMP[0]       |
 *                             | ...                                    |
 *                             | VAR[op_array->last_var+op_array->T-1]  |
 *                             | ARG[N+1] (extra_args)                  |
 *                             | ...                                    |
 *                             +----------------------------------------+
 */

/* zend_copy_extra_args is used when the actually passed number of arguments
 * (EX_NUM_ARGS) is greater than what the function defined (op_array->num_args).
 *
 * The extra arguments will be copied into the call frame after all the compiled variables.
 *
 * If there are extra arguments copied, a flag "ZEND_CALL_FREE_EXTRA_ARGS" will be set
 * on the zend_execute_data, and when the executor leaves the function, the
 * args will be freed in zend_leave_helper.
 */

/**
 * ExecutionData provides information about current stack frame
 *
 * typedef struct _zend_execute_data {
 *   const zend_op       *opline;           // executed opline
 *   zend_execute_data   *call;             // current call
 *   zval                *return_value;
 *   zend_function       *func;             // executed function
 *   zval                 This;             // this + call_info + num_args
 *   zend_execute_data   *prev_execute_data;
 *   zend_array          *symbol_table;
 *   void               **run_time_cache;   // cache op_array->run_time_cache
 * };
 */
class ExecutionData
{
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    /**
     * Returns the currently executed opline
     */
    public function getOpline(): OpLine
    {
        return new OpLine($this->pointer->opline, $this);
    }

    /**
     * Moves current opline pointer to the next one
     *
     * Use it only within opcode handlers!
     */
    public function nextOpline(): void
    {
        $this->pointer->opline++;
    }

    /**
     * Returns the "return value"
     */
    public function getReturnValue(): ReflectionValue
    {
        return ReflectionValue::fromValueEntry($this->pointer->return_value);
    }

    /**
     * Returns the current function or method
     *
     * @return FunctionLikeTrait
     */
    public function getFunction(): \ReflectionFunctionAbstract
    {
        if ($this->pointer->func === null) {
            throw new \InvalidArgumentException('Function entry is not available in the current context');
        }

        $functionEntry = $this->pointer->func;
        assert($functionEntry instanceof CData);
        if ($this->pointer->func->common->scope === null) {
            $reflection = ReflectionFunction::fromCData($functionEntry);
        } else {
            $reflection = ReflectionMethod::fromCData($functionEntry);
        }

        return $reflection;
    }

    /**
     * Returns the pointer-level reflection of the zend_function this frame executes
     * (null for frames without a function entry, eg top-level pseudo frames)
     *
     * Unlike getFunction() this never resolves methods through their class and works
     * for every frame kind (closures, trampolines, internal functions): the wrapper
     * is a BORROWED pointer-level view, taking no ownership of the frame function.
     * Callers needing the entry identity compare ReflectionFunction::getAddress().
     *
     * @internal used by FunctionBodySwap to detect entries with live frames
     */
    public function getFunctionEntry(): ?ReflectionFunction
    {
        /** @var CData|null $rawFunction */
        $rawFunction = $this->pointer->func;
        if ($rawFunction === null) {
            return null;
        }

        return ReflectionFunction::fromCData($rawFunction);
    }

    /**
     * Returns the bound $this object of this frame, or null when the frame has none
     * (plain function, static call, main scope)
     *
     * The This zval doubles as the frame's call-info word - its u1.type_info
     * carries the ZEND_CALL_* frame flags packed next to the type, so it is never
     * a bare IS_OBJECT and cannot be type-compared to detect a bound $this.
     * This accessor checks the ZEND_CALL_HAS_THIS frame flag (see hasThis())
     * before exposing the value, so a non-null result is always the real object.
     */
    public function getThis(): ?ReflectionValue
    {
        if (!$this->hasThis()) {
            return null;
        }

        return ReflectionValue::fromValueEntry(Core::addr($this->pointer->This));
    }

    /**
     * Checks whether this frame has a bound $this object
     *
     * The presence of an object scope is flagged by ZEND_CALL_HAS_THIS in the
     * frame's call_info, which lives in the type_info of the This zval (see the
     * getThis() warning). Plain function frames, static calls and the main scope
     * carry no bound object.
     */
    public function hasThis(): bool
    {
        // ZEND_CALL_INFO(call): the frame flags live in the type_info of This
        $callInfo = $this->getThisZvalShape()->u1->type_info;

        return ($callInfo & Core::engineConstant('ZEND_CALL_HAS_THIS')) !== 0;
    }

    /**
     * Returns the number of function/method arguments
     */
    public function getNumberOfArguments(): int
    {
        return $this->pointer->This->u2->num_args;
    }

    /**
     * Returns the argument by it's index
     *
     * Argument index is starting from 0.
     *
     * @see zend_compile.h:ZEND_CALL_ARG(call, n) macro
     */
    public function getArgument(int $argumentIndex): ReflectionValue
    {
        if ($argumentIndex >= $this->pointer->This->u2->num_args) {
            throw new \OutOfBoundsException('Argument index is greater than available arguments');
        }
        // In PHP it is ZEND_CALL_VAR_NUM(call, ((int)(n)) - 1) but we start numeration from 0 in Z-Engine, so no "-1"
        $valuePointer = $this->getCallVariableByNumber($argumentIndex);
        $valueEntry   = ReflectionValue::fromValueEntry($valuePointer);

        return $valueEntry;
    }

    /**
     * Returns execution arguments as array of values
     *
     * @return ReflectionValue[]
     */
    public function getArguments(): array
    {
        $arguments      = [];
        $totalArguments = $this->pointer->This->u2->num_args;
        for ($index = 0; $index < $totalArguments; $index++) {
            $arguments[] = $this->getArgument($index);
        }

        return $arguments;
    }

    /**
     * Returns the live frame variables (CV slots) of this frame, indexed by variable name
     *
     * Names come from the function's compiled-variable table (op_array->vars) and
     * values are read directly from the frame's CV slots, so no symbol table has to
     * be materialized. Declared-but-unset variables (IS_UNDEF slots: not yet assigned
     * on this code path, or unset()) are skipped; use getLocalVariable() to observe
     * them individually. Frames without a user function (internal calls, top-level
     * pseudo frames) have no CV slots and yield an empty list.
     *
     * Values are BORROWED views into the live frame: they are valid only while the
     * frame is on the VM stack (eg during an opcode handler or from a parent frame).
     *
     * @return ReflectionValue[] Frame variables indexed by variable name (no '$' sigil)
     */
    public function getLocalVariables(): array
    {
        $variables = [];
        foreach ($this->getLocalVariableNames() as $slotNumber => $variableName) {
            $valueEntry = ReflectionValue::fromValueEntry($this->getCallVariableByNumber($slotNumber));
            if ($valueEntry->getType() === ReflectionValue::IS_UNDEF) {
                continue;
            }
            $variables[$variableName] = $valueEntry;
        }

        return $variables;
    }

    /**
     * Returns one live frame variable (CV slot) of this frame by its name
     *
     * Unlike getLocalVariables() this also returns declared-but-unset variables:
     * check ReflectionValue::getType() against ReflectionValue::IS_UNDEF to
     * distinguish "declared on the frame but not assigned" from a real value.
     * The value is a BORROWED view into the live frame (see getLocalVariables()).
     *
     * @param string $variableName Variable name without the '$' sigil
     */
    public function getLocalVariable(string $variableName): ReflectionValue
    {
        $slotNumber = array_search($variableName, $this->getLocalVariableNames(), true);
        if ($slotNumber === false) {
            throw new \OutOfBoundsException(
                "Frame has no compiled variable \${$variableName}: " .
                'only declared variables of a user function occupy CV slots',
            );
        }

        return ReflectionValue::fromValueEntry($this->getCallVariableByNumber($slotNumber));
    }

    /**
     * Returns the compiled-variable names of this frame indexed by CV slot number
     *
     * Empty for frames that execute no user function (they have no CV slots).
     *
     * @return array<int, string>
     */
    private function getLocalVariableNames(): array
    {
        $functionEntry = $this->getFunctionEntry();
        if ($functionEntry === null) {
            return [];
        }

        return $functionEntry->getVariableNames();
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
    public function getPrevious(): ExecutionData
    {
        if ($this->pointer->prev_execute_data === null) {
            throw new \LogicException('There is no previous execution data. Top of the stack?');
        }
        return new ExecutionData($this->pointer->prev_execute_data);
    }

    /**
     * Returns the symbol table of this frame, or null when the frame carries none
     *
     * The engine doesn't use symbol tables for ordinary frames: locals live in
     * optimized CV slots (see getLocalVariables()). A symbol table is materialized
     * only for tricky cases (variable variables, extract(), compact(), eval'd code)
     * and its presence is flagged by ZEND_CALL_HAS_SYMBOL_TABLE in the frame's
     * call_info. Without that flag the symbol_table field is stale garbage - reading
     * it was the historical segfault of this method, so the flag is checked first
     * and null is returned for frames without a materialized table.
     */
    public function getSymbolTable(): ?HashTable
    {
        // ZEND_CALL_INFO(call): the frame flags live in the type_info of This
        $callInfo = $this->getThisZvalShape()->u1->type_info;
        if (($callInfo & Core::engineConstant('ZEND_CALL_HAS_SYMBOL_TABLE')) === 0) {
            return null;
        }
        $symbolTable = $this->pointer->symbol_table;
        assert($symbolTable instanceof CData);

        return HashTable::fromCData($symbolTable);
    }

    /**
     * Returns call variable from the stack
     *
     * <span style="color:red; font-weight: bold">Only for the Z-Engine library</span>
     *
     * @param int $variableOffset Variable offset
     *
     * @return CData zval* pointer
     * @see zend_compile.h:ZEND_CALL_VAR(call, n) macro
     * @internal
     */
    public function getCallVariable(int $variableOffset): CData
    {
        // ((zval*)(((char*)(call)) + ((int)(n))))
        $pointer = Core::cast('char *', $this->pointer) + $variableOffset;
        $value   = Core::cast('zval *', $pointer);

        return $value;
    }

    /**
     * Returns call variable from the stack by number
     *
     * <span style="color:red; font-weight: bold">Only for the Z-Engine library</span>
     *
     * @param CData $call zend_execute_data
     * @param int $variableNum Variable number
     *
     * @return CData zval* pointer
     * @see zend_compile.h:ZEND_CALL_VAR_NUM(call, n) macro
     * @internal
     */
    public function getCallVariableByNumber(int $variableNum): CData
    {
        // (((zval*)(call)) + (ZEND_CALL_FRAME_SLOT + ((int)(n))))
        $pointer = Core::cast('zval *', $this->pointer);

        return $pointer + self::getCallFrameSlot() + $variableNum;
    }

    /**
     * Returns the shaped view of the frame's This zval, which carries the frame
     * flags (call_info in u1.type_info) and the argument count (u2.num_args)
     * next to the object scope
     *
     * @return ZvalShape
     */
    private function getThisZvalShape(): object
    {
        $thisZval = $this->pointer->This;

        /** @var ZvalShape $thisZval */
        return $thisZval;
    }

    /**
     * Calculates the call frame slot size
     *
     * @see ZEND_CALL_FRAME_SLOT
     */
    private static function getCallFrameSlot(): int
    {
        static $slotSize;
        if ($slotSize === null) {
            $alignedSizeOfExecuteData = Core::getAlignedSize(Core::sizeof(Core::type('zend_execute_data')));
            $alignedSizeOfZval        = Core::getAlignedSize(Core::sizeof(Core::type('zval')));

            $slotSize = intdiv(($alignedSizeOfExecuteData + $alignedSizeOfZval) - 1, $alignedSizeOfZval);
        }

        return $slotSize;
    }
}
