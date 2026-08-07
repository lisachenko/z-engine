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

namespace ZEngine\ClassExtension\Hook;

/**
 * Named view of the type ids the engine passes to a cast_object handler
 *
 * The backing values are the zval type ids from Zend/zend_types.h for the PHP minor this branch
 * targets, and they are guarded against the generated ground truth by EngineConstantsTest. Prefer
 * dispatching on these cases over raw ReflectionValue constants: the cast-only ids have moved
 * between PHP minors before (PHP 8.1 inserted IS_NEVER = 17, shifting _IS_BOOL and _IS_NUMBER up
 * by one), and a namesake constant with a stale value misroutes casts silently.
 */
enum CastType: int
{
    /** IS_LONG: explicit (int) casts and integer coercion */
    case Long = 4;

    /** IS_DOUBLE: explicit (float) casts and float coercion */
    case Double = 5;

    /** IS_STRING: explicit (string) casts, string interpolation and echo */
    case String = 6;

    /** IS_ARRAY: passed by extensions that invoke cast_object directly with an array target */
    case Array = 7;

    /** IS_OBJECT: passed by extensions that invoke cast_object directly with an object target */
    case Object = 8;

    /** _IS_BOOL: explicit (bool) casts and every boolean context (if, &&, !, ...) */
    case Bool = 18;

    /** _IS_NUMBER: numeric coercion where either int or float is acceptable */
    case Number = 19;
}
