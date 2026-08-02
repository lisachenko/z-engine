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

namespace ZEngine\Stub;

/**
 * Template with the placeholder inside a union type list (two class-like members
 * force the engine to store a zend_type_list): substituting it must be refused
 */
class TestSpecializationUnionTemplate
{
    public TPlaceholder|TestClass $union;
}
