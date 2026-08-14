<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\EngineExtension;

/**
 * Declares a single dependency of a userland module on another engine module
 *
 * Dependencies returned from ModuleInterface::getModuleDependencies() are written into the
 * module entry's `deps` field as a persistent NULL-terminated `zend_module_dep[]` during
 * AbstractModule::register(). The engine consults them when the module is registered
 * (conflicts) and started (required modules must already be started); they are also
 * visible through the native ReflectionExtension::getDependencies().
 *
 * struct _zend_module_dep {
 *   const char *name;      // module name
 *   const char *rel;       // version relationship: NULL (exists), lt|le|eq|ge|gt (to given version)
 *   const char *version;   // version
 *   unsigned char type;    // dependency type
 * };
 */
final class ModuleDependency
{
    /**
     * @deprecated Use the DependencyType enum (DependencyType::Required/Conflicts/Optional) instead;
     *             these int aliases are kept for consumers that pass or compare raw MODULE_DEP_* values
     */
    public const MODULE_REQUIRED  = 1;
    public const MODULE_CONFLICTS = 2;
    public const MODULE_OPTIONAL  = 3;

    /**
     * Dependency type as the engine stores it in zend_module_dep.type
     */
    private readonly DependencyType $dependencyType;

    /**
     * Version relationship, or null when the module just has to exist
     */
    private readonly ?VersionRelation $relation;

    /**
     * @param string                      $name           Name of the module this dependency points to (eg 'standard')
     * @param DependencyType|int          $dependencyType Dependency type, or a legacy MODULE_* constant
     * @param VersionRelation|string|null $relation       Version relationship: null (module just has to exist),
     *                                                    a VersionRelation case or one of the raw lt|le|eq|ge|gt strings
     * @param string|null                 $version        Version to compare against; required when $relation is given
     */
    public function __construct(
        private readonly string $name,
        DependencyType|int $dependencyType = DependencyType::Required,
        VersionRelation|string|null $relation = null,
        private readonly ?string $version = null,
    ) {
        $this->dependencyType = DependencyType::fromValue($dependencyType);
        $this->relation       = VersionRelation::fromValue($relation);
        if (($this->relation === null) !== ($version === null)) {
            throw new \InvalidArgumentException('Version relation and version must be provided together');
        }
    }

    /**
     * Declares that the given module must be loaded and started before this one
     */
    public static function required(
        string $name,
        VersionRelation|string|null $relation = null,
        ?string $version = null,
    ): self {
        return new self($name, DependencyType::Required, $relation, $version);
    }

    /**
     * Declares that this module cannot be loaded together with the given module
     */
    public static function conflicts(string $name): self
    {
        return new self($name, DependencyType::Conflicts);
    }

    /**
     * Declares an optional relationship (affects module startup ordering only)
     */
    public static function optional(
        string $name,
        VersionRelation|string|null $relation = null,
        ?string $version = null,
    ): self {
        return new self($name, DependencyType::Optional, $relation, $version);
    }

    /**
     * Returns the name of the module this dependency points to
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the dependency type
     */
    public function dependencyType(): DependencyType
    {
        return $this->dependencyType;
    }

    /**
     * Returns the version relationship (null when the module just has to exist)
     */
    public function versionRelation(): ?VersionRelation
    {
        return $this->relation;
    }

    /**
     * Returns the dependency type (one of the MODULE_* constants)
     *
     * @deprecated Use dependencyType() instead, which returns the DependencyType enum
     */
    public function getDependencyType(): int
    {
        return $this->dependencyType->value;
    }

    /**
     * Returns the version relationship (null when the module just has to exist)
     *
     * @deprecated Use versionRelation() instead, which returns the VersionRelation enum
     */
    public function getRelation(): ?string
    {
        return $this->relation?->value;
    }

    /**
     * Returns the version to compare against (null when no relation is declared)
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }
}
