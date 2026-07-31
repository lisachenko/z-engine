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
    public const MODULE_REQUIRED  = 1;
    public const MODULE_CONFLICTS = 2;
    public const MODULE_OPTIONAL  = 3;

    /**
     * Version relationships understood by the engine (zend_modules.h)
     */
    private const KNOWN_RELATIONS = ['lt', 'le', 'eq', 'ge', 'gt'];

    /**
     * @param string      $name           Name of the module this dependency points to (eg 'standard')
     * @param int         $dependencyType One of the MODULE_REQUIRED/MODULE_CONFLICTS/MODULE_OPTIONAL constants
     * @param string|null $relation       Version relationship: null (module just has to exist) or one of lt|le|eq|ge|gt
     * @param string|null $version        Version to compare against; required when $relation is given
     */
    public function __construct(
        private readonly string $name,
        private readonly int $dependencyType = self::MODULE_REQUIRED,
        private readonly ?string $relation = null,
        private readonly ?string $version = null,
    ) {
        $knownTypes = [self::MODULE_REQUIRED, self::MODULE_CONFLICTS, self::MODULE_OPTIONAL];
        if (!in_array($dependencyType, $knownTypes, true)) {
            throw new \InvalidArgumentException("Unknown module dependency type {$dependencyType}");
        }
        if ($relation !== null && !in_array($relation, self::KNOWN_RELATIONS, true)) {
            $known = implode('|', self::KNOWN_RELATIONS);
            throw new \InvalidArgumentException("Unknown version relation '{$relation}', expected one of {$known}");
        }
        if (($relation === null) !== ($version === null)) {
            throw new \InvalidArgumentException('Version relation and version must be provided together');
        }
    }

    /**
     * Declares that the given module must be loaded and started before this one
     */
    public static function required(string $name, ?string $relation = null, ?string $version = null): self
    {
        return new self($name, self::MODULE_REQUIRED, $relation, $version);
    }

    /**
     * Declares that this module cannot be loaded together with the given module
     */
    public static function conflicts(string $name): self
    {
        return new self($name, self::MODULE_CONFLICTS);
    }

    /**
     * Declares an optional relationship (affects module startup ordering only)
     */
    public static function optional(string $name, ?string $relation = null, ?string $version = null): self
    {
        return new self($name, self::MODULE_OPTIONAL, $relation, $version);
    }

    /**
     * Returns the name of the module this dependency points to
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the dependency type (one of the MODULE_* constants)
     */
    public function getDependencyType(): int
    {
        return $this->dependencyType;
    }

    /**
     * Returns the version relationship (null when the module just has to exist)
     */
    public function getRelation(): ?string
    {
        return $this->relation;
    }

    /**
     * Returns the version to compare against (null when no relation is declared)
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }
}
