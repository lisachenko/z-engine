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

namespace ZEngine\Type;

/**
 * Named view of the argument send mode packed into zend_type.type_mask
 *
 * The engine stores the mode in the two bits above _ZEND_SEND_MODE_SHIFT, so the decoded value is
 * always one of exactly three states - a closed set that an int can only approximate. The backing
 * values are the ZEND_SEND_* macro values from Zend/zend_compile.h for the PHP minor this branch
 * targets; they are internal macros the header generator does not export, which is why the decode
 * lives here rather than behind Core::engineConstant().
 *
 * @see ArgumentEntry::sendMode()
 */
enum SendMode: int
{
    /** ZEND_SEND_BY_VAL: the argument is passed by value (the default) */
    case ByValue = 0;

    /** ZEND_SEND_BY_REF: the argument is declared by reference and always passed as one */
    case ByReference = 1;

    /** ZEND_SEND_PREFER_REF: internal-function argument taken by reference when the caller has one */
    case PreferReference = 2;

    /**
     * Checks if this mode passes the argument by reference (including the prefer-ref internal form)
     */
    public function isByReference(): bool
    {
        return $this !== self::ByValue;
    }
}
