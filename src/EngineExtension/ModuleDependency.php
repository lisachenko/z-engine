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


use FFI\CData;

/**
 * Class ModuleDependency
 *
 * struct _zend_module_dep {
 *   const char *name;      // module name
 *   const char *rel;       // version relationship: NULL (exists), lt|le|eq|ge|gt (to given version)
 *   const char *version;   // version
 *   unsigned char type;    // dependency type
 * };
 */
class ModuleDependency
{
    public const MODULE_REQUIRED  = 1;
    public const MODULE_CONFLICTS = 2;
    public const MODULE_OPTIONAL  = 3;

    /**
     * Holds a _zend_module_dep structure
     */
    private CData $entry;

    public function __construct(
        string $name,
        int $relationType,
        string $version,
        int $dependencyType = self::MODULE_REQUIRED
    ) {

        $this->name           = $name;
        $this->relationType   = $relationType;
        $this->version        = $version;
        $this->dependencyType = $dependencyType;
    }
}