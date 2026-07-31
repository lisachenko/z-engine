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

namespace ZEngine\Type;

/**
 * Class TryCatchElement is a read-only view over one exception region of an op_array
 *
 * struct _zend_try_catch_element {
 *   uint32_t try_op;
 *   uint32_t catch_op;     // 0 when the region has no catch blocks
 *   uint32_t finally_op;   // 0 when the region has no finally block
 *   uint32_t finally_end;
 * };
 *
 * All values are opcode indexes into the op_array. Opcode analysis and rewriting must
 * treat these regions as barriers: moving an instruction across a region boundary
 * changes which handlers guard it.
 */
class TryCatchElement
{
    public function __construct(
        private readonly int $tryOp,
        private readonly int $catchOp,
        private readonly int $finallyOp,
        private readonly int $finallyEnd,
    ) {}

    /**
     * Returns the opcode index where the protected region starts
     */
    public function getTryOp(): int
    {
        return $this->tryOp;
    }

    /**
     * Returns the opcode index of the first catch block (0 when there is none)
     */
    public function getCatchOp(): int
    {
        return $this->catchOp;
    }

    /**
     * Returns the opcode index of the finally block (0 when there is none)
     */
    public function getFinallyOp(): int
    {
        return $this->finallyOp;
    }

    /**
     * Returns the opcode index right after the finally block (0 when there is none)
     */
    public function getFinallyEnd(): int
    {
        return $this->finallyEnd;
    }

    /**
     * Checks if the region has at least one catch block
     */
    public function hasCatch(): bool
    {
        return $this->catchOp !== 0;
    }

    /**
     * Checks if the region has a finally block
     */
    public function hasFinally(): bool
    {
        return $this->finallyOp !== 0;
    }

    /**
     * Returns a user-friendly representation of this element
     */
    public function __debugInfo(): array
    {
        return [
            'tryOp'      => $this->tryOp,
            'catchOp'    => $this->catchOp,
            'finallyOp'  => $this->finallyOp,
            'finallyEnd' => $this->finallyEnd,
        ];
    }
}
