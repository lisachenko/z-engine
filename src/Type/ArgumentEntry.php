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
 * Class ArgumentEntry is a read-only view over one argument info entry of a function
 *
 * It carries the decoded content of a zend_arg_info (user functions) or
 * zend_internal_arg_info (internal functions) structure:
 *
 * struct _zend_arg_info {
 *   zend_string *name;        // const char *name for zend_internal_arg_info
 *   zend_type    type;
 *   zend_string *default_value;
 * };
 *
 * The zend_type.type_mask field packs the allowed-type bits together with several
 * argument-passing flags; the bit layout below mirrors zend_types.h/zend_compile.h
 * for PHP 8.5 (there is no exported engine constant for these internal macros).
 */
class ArgumentEntry
{
    /**
     * Index of the pseudo-entry that holds the return-type information
     */
    public const RETURN_ENTRY_INDEX = -1;

    /**
     * Mask of the pure MAY_BE_* type bits, where MAY_BE_<TYPE> is (1 << IS_<TYPE>)
     *
     * @see zend_types.h:_ZEND_TYPE_MAY_BE_MASK
     */
    public const TYPE_MAY_BE_MASK = (1 << 18) - 1;

    /**
     * Nullability bit inside the pure type mask (same value as MAY_BE_NULL)
     *
     * @see zend_types.h:_ZEND_TYPE_NULLABLE_BIT
     */
    public const TYPE_NULLABLE_BIT = 0x2;

    /**
     * Shift of the two-bit argument send mode inside the type mask
     *
     * @see zend_compile.h:_ZEND_SEND_MODE_SHIFT (== _ZEND_TYPE_EXTRA_FLAGS_SHIFT)
     */
    public const SEND_MODE_SHIFT = 25;

    /**
     * Argument send modes as returned by getSendMode()
     *
     * @see zend_compile.h:ZEND_SEND_BY_VAL/ZEND_SEND_BY_REF/ZEND_SEND_PREFER_REF
     */
    public const SEND_BY_VAL     = 0;
    public const SEND_BY_REF     = 1;
    public const SEND_PREFER_REF = 2;

    /**
     * Variadic-argument bit inside the type mask
     *
     * @see zend_compile.h:_ZEND_IS_VARIADIC_BIT
     */
    public const IS_VARIADIC_BIT = 1 << 27;

    public function __construct(
        private readonly int $index,
        private readonly ?string $name,
        private readonly int $typeMask,
    ) {}

    /**
     * Returns the position of this entry: 0..N-1 for declared parameters, N for the
     * variadic parameter and RETURN_ENTRY_INDEX (-1) for the return-type entry
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Checks if this entry describes the return type instead of a parameter
     */
    public function isReturnEntry(): bool
    {
        return $this->index === self::RETURN_ENTRY_INDEX;
    }

    /**
     * Returns the parameter name (always null for the return-type entry)
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Returns the raw zend_type.type_mask value (type bits plus passing flags)
     */
    public function getTypeMask(): int
    {
        return $this->typeMask;
    }

    /**
     * Returns only the MAY_BE_* type bits of the mask (0 for an untyped entry)
     */
    public function getPureTypeMask(): int
    {
        return $this->typeMask & self::TYPE_MAY_BE_MASK;
    }

    /**
     * Checks if the declared type allows values of the given IS_* type
     *
     * @param int $type One of the ReflectionValue::IS_* type constants
     */
    public function mayBeOfType(int $type): bool
    {
        return (bool) (($this->getPureTypeMask() >> $type) & 1);
    }

    /**
     * Checks if the declared type accepts null
     */
    public function allowsNull(): bool
    {
        return (bool) ($this->typeMask & self::TYPE_NULLABLE_BIT);
    }

    /**
     * Returns the argument send mode (one of the SEND_* constants)
     */
    public function getSendMode(): int
    {
        return ($this->typeMask >> self::SEND_MODE_SHIFT) & 3;
    }

    /**
     * Checks if the argument is passed by reference (including prefer-ref internal args)
     */
    public function isByReference(): bool
    {
        return $this->getSendMode() !== self::SEND_BY_VAL;
    }

    /**
     * Checks if this entry describes a variadic parameter
     */
    public function isVariadic(): bool
    {
        return (bool) ($this->typeMask & self::IS_VARIADIC_BIT);
    }

    /**
     * Returns a user-friendly representation of this entry
     */
    public function __debugInfo(): array
    {
        return [
            'index'       => $this->index,
            'name'        => $this->name,
            'typeMask'    => $this->typeMask,
            'byReference' => $this->isByReference(),
            'variadic'    => $this->isVariadic(),
        ];
    }
}
