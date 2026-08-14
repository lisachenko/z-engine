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
 * Class LiveRange is a read-only view over one temporary-variable live range of an op_array
 *
 * struct _zend_live_range {
 *   uint32_t var;   // low bits encode the variable kind (ZEND_LIVE_* macros)
 *   uint32_t start;
 *   uint32_t end;
 * };
 *
 * A live range tells the executor which temporary variables must be destroyed when an
 * exception unwinds through the [start, end) opcode window. The var field packs the
 * variable operand offset together with a kind tag in its low bits; the KIND_* values
 * mirror the ZEND_LIVE_* macros of zend_compile.h for PHP 8.4.
 */
class LiveRange
{
    public const int KIND_TMPVAR  = 0;
    public const int KIND_LOOP    = 1;
    public const int KIND_SILENCE = 2;
    public const int KIND_ROPE    = 3;
    public const int KIND_NEW     = 4;

    /**
     * Mask of the kind bits inside the var field
     *
     * @see zend_compile.h:ZEND_LIVE_MASK
     */
    public const int KIND_MASK = 7;

    public function __construct(
        private readonly int $var,
        private readonly int $start,
        private readonly int $end,
    ) {}

    /**
     * Returns the raw var field (variable operand offset combined with the kind bits)
     */
    public function getVar(): int
    {
        return $this->var;
    }

    /**
     * Returns the kind of the tracked variable (one of the KIND_* constants)
     */
    public function getKind(): int
    {
        return $this->var & self::KIND_MASK;
    }

    /**
     * Returns the variable operand offset without the kind bits
     */
    public function getVariableOffset(): int
    {
        return $this->var & (~self::KIND_MASK);
    }

    /**
     * Returns the opcode index where the live range starts
     */
    public function getStart(): int
    {
        return $this->start;
    }

    /**
     * Returns the opcode index where the live range ends (exclusive)
     */
    public function getEnd(): int
    {
        return $this->end;
    }

    /**
     * Returns the user-friendly name of a live-range kind
     */
    public static function kindName(int $kind): string
    {
        // KIND_MASK is the bit mask the kinds are extracted with, not a kind of its own
        return ConstantNames::of(self::class, 'KIND_', ['KIND_MASK'])[$kind] ?? 'UNKNOWN';
    }

    /**
     * Returns a user-friendly representation of this live range
     */
    public function __debugInfo(): array
    {
        return [
            'var'   => $this->getVariableOffset(),
            'kind'  => self::kindName($this->getKind()),
            'start' => $this->start,
            'end'   => $this->end,
        ];
    }
}
