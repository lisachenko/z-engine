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
 * Named view of the `type` field of a zend_module_dep entry
 *
 * The backing values are the MODULE_DEP_* macros from Zend/zend_modules.h; the engine writes
 * them as an `unsigned char` into the NULL-terminated `deps` array of a module entry.
 *
 * @see ModuleDependency
 */
enum DependencyType: int
{
    /** MODULE_DEP_REQUIRED: the module must be loaded and started before this one */
    case Required = 1;

    /** MODULE_DEP_CONFLICTS: this module cannot be loaded together with the named one */
    case Conflicts = 2;

    /** MODULE_DEP_OPTIONAL: no hard requirement, affects module startup ordering only */
    case Optional = 3;

    /**
     * Normalizes a dependency type given either as a case or as one of the legacy
     * ModuleDependency::MODULE_* integers
     *
     * @throws \InvalidArgumentException when the integer matches no known MODULE_DEP_* value
     */
    public static function fromValue(self|int $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return self::tryFrom($type) ?? throw new \InvalidArgumentException("Unknown module dependency type {$type}");
    }
}
