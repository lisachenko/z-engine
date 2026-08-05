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

namespace ZEngine\Reflection;

/**
 * The three kinds of declaration slot that carry an engine-enforced zend_type
 *
 * A property declaration stores its type in zend_property_info, a parameter and a return
 * type store theirs in the method's zend_arg_info block. Nothing else in a class entry
 * carries a type the engine checks.
 */
enum TypeSlotKind
{
    case Property;
    case Parameter;
    case ReturnType;
}
