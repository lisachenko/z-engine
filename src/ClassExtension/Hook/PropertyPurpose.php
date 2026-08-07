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
 * Named view of the zend_prop_purpose values the engine passes to a get_properties_for handler
 *
 * The backing values mirror the zend_prop_purpose enumeration in Zend/zend_object_handlers.h for
 * the PHP minor this branch targets. They are not yet exported by the header generator manifest,
 * so unlike CastType they carry no generated-ground-truth guard — the enumeration has been stable
 * since PHP 7.4 introduced it, but verify against zend_object_handlers.h when bumping the branch
 * to a new minor.
 */
enum PropertyPurpose: int
{
    /** ZEND_PROP_PURPOSE_DEBUG: var_dump() and friends; supersedes the get_debug_info handler */
    case Debug = 0;

    /** ZEND_PROP_PURPOSE_ARRAY_CAST: explicit (array) casts */
    case ArrayCast = 1;

    /** ZEND_PROP_PURPOSE_SERIALIZE: serialize() using the "O" scheme */
    case Serialize = 2;

    /** ZEND_PROP_PURPOSE_VAR_EXPORT: var_export(); the data is passed to __set_state() */
    case VarExport = 3;

    /** ZEND_PROP_PURPOSE_JSON: json_encode() */
    case Json = 4;

    /** ZEND_PROP_PURPOSE_GET_OBJECT_VARS: get_object_vars() */
    case GetObjectVars = 5;
}
