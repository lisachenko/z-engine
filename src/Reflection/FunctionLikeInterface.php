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

namespace ZEngine\Reflection;

use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_function_common;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_op_array;

/**
 * Common low-level surface of a reflected function/method entry
 *
 * Implemented by ReflectionFunction and ReflectionMethod through FunctionLikeTrait, so
 * the body-swap machinery (FunctionBodySwap) can accept either kind of entry without
 * dealing in raw zend_function pointers.
 *
 * @internal
 */
interface FunctionLikeInterface
{
    /**
     * Shaped view over the entry's common struct (see FunctionLikeTrait::getCommonPointer())
     *
     * @return zend_function_common|zend_internal_function
     */
    public function getCommonPointer(): object;

    /**
     * Shaped view over the entry's op_array (see FunctionLikeTrait::getOpArrayPointer())
     *
     * @return zend_op_array
     */
    public function getOpArrayPointer(): object;

    public function getAddress(): int;

    /**
     * @return zend_function|zend_internal_function
     */
    public function getEntryPointer(): object;

    public function isUserDefined(): bool;

    /**
     * Compiled-variable names by CV slot (see FunctionLikeTrait::getVariableNames())
     *
     * @return array<int, string>
     */
    public function getVariableNames(): array;
}
