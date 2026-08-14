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

namespace ZEngine\EngineExtension;

/**
 * The version relationships the engine understands in the `rel` field of a zend_module_dep entry
 *
 * The backing values are the exact strings zend_modules.h documents and php_check_dep_version()
 * compares against; a dependency with no relation stores a NULL `rel` instead of one of these
 * (modelled as a null VersionRelation rather than a case of its own).
 *
 * @see ModuleDependency
 */
enum VersionRelation: string
{
    /** The dependency version must be lower than the given one */
    case LessThan = 'lt';

    /** The dependency version must be lower than or equal to the given one */
    case LessOrEqual = 'le';

    /** The dependency version must be exactly the given one */
    case Equal = 'eq';

    /** The dependency version must be greater than or equal to the given one */
    case GreaterOrEqual = 'ge';

    /** The dependency version must be greater than the given one */
    case GreaterThan = 'gt';

    /**
     * Normalizes a relation given either as a case or as one of the raw engine strings,
     * passing a missing relation (null) through unchanged
     *
     * @throws \InvalidArgumentException when the string matches no relation the engine understands
     */
    public static function fromValue(self|string|null $relation): ?self
    {
        if ($relation === null || $relation instanceof self) {
            return $relation;
        }

        return self::tryFrom($relation) ?? throw new \InvalidArgumentException(
            "Unknown version relation '{$relation}', expected one of "
            . implode('|', array_column(self::cases(), 'value')),
        );
    }
}
