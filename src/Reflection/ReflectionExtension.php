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

namespace ZEngine\Reflection;


use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ReflectionExtension as NativeReflectionExtension;
use ZEngine\Core;

/**
 * Class ReflectionExtension
 *
 * struct _zend_module_entry {
 *   unsigned short size;
 *   unsigned int zend_api;
 *   unsigned char zend_debug;
 *   unsigned char zts;
 *   const struct _zend_ini_entry *ini_entry;
 *   const struct _zend_module_dep *deps;
 *   const char *name;
 *   const struct _zend_function_entry *functions;
 *   int (*module_startup_func)(int type, int module_number);
 *   int (*module_shutdown_func)(int type, int module_number);
 *   int (*request_startup_func)(int type, int module_number);
 *   int (*request_shutdown_func)(int type, int module_number);
 *   void (*info_func)(zend_module_entry *zend_module);
 *   const char *version;
 *   size_t globals_size;
 * #ifdef ZTS
 *   ts_rsrc_id* globals_id_ptr;
 * #else
 *   void* globals_ptr;
 * #endif
 *   void (*globals_ctor)(void *global);
 *   void (*globals_dtor)(void *global);
 *   int (*post_deactivate_func)(void);
 *   int module_started;
 *   unsigned char type;
 *   void *handle;
 *   int module_number;
 *   const char *build_id;
 * };
 */
class ReflectionExtension extends NativeReflectionExtension
{
    protected CData $moduleEntry;

    /**
     * @inheritDoc
     */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $moduleEntry = Core::$modules->find($name);
        if ($moduleEntry === null) {
            throw new \ReflectionException("Module {$name} should be in the engine.");
        }
        $rawPointer        = $moduleEntry->getRawPointer();
        $this->moduleEntry = Core::cast('zend_module_entry *', $rawPointer);
    }

    /**
     * Creates an instance of extension from a low-level data structure
     *
     * @param CData $moduleEntry Pointer to the `zend_module_entry` structure
     */
    public static function fromCData(CData $moduleEntry): self
    {
        /** @var self $extension */
        $extension = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $extension->moduleEntry = $moduleEntry;

        call_user_func([$extension, 'parent::__construct'], $moduleEntry->name);

        return $extension;
    }

    /**
     * Returns the size of module itself
     *
     * Typically, this should be equal to Core::type('zend_module_entry')
     */
    public function getSize(): int
    {
        return $this->moduleEntry->size;
    }

    /**
     * Returns the size of module global structure
     */
    public function getGlobalsSize(): int
    {
        return $this->moduleEntry->globals_size;
    }

    /**
     * Returns a pointer (if any) to global memory area or null if extension doesn't use global memory structure
     */
    public function getGlobals(): ?CData
    {
        return $this->moduleEntry->globals_ptr;
    }

    /**
     * Was module started or not
     */
    public function wasModuleStarted(): bool
    {
        return (bool) $this->moduleEntry->module_started;
    }

    /**
     * Is module was compiled/designed for debug mode
     *
     * @see ZEND_DEBUG_BUILD
     */
    public function isDebug(): bool
    {
        return (bool) $this->moduleEntry->zend_debug;
    }

    /**
     * Is module compiled with thread safety or not
     *
     * @see ZEND_THREAD_SAFE
     */
    public function isThreadSafe(): bool
    {
        return (bool) $this->moduleEntry->zts;
    }

    /**
     * Returns the module ordinal number
     */
    public function getModuleNumber(): int
    {
        return $this->moduleEntry->module_number;
    }

    /**
     * Returns the api version
     */
    public function getApiVersion(): int
    {
        return $this->moduleEntry->zend_api;
    }

    /**
     * This method is used to prevent segmentation faults when dumping CData
     */
    public function __debugInfo()
    {
        if (!isset($this->moduleEntry)) {
            return [];
        }
        $result  = [];
        $methods = (new NativeReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $methodName  = $method->getName();
            $hasZeroArgs = $method->getNumberOfRequiredParameters() === 0;
            if ((strpos($methodName, 'get') === 0) && $hasZeroArgs) {
                $friendlyName          = lcfirst(substr($methodName, 3));
                $result[$friendlyName] = $this->$methodName();
            }
            if ((strpos($methodName, 'is') === 0) && $hasZeroArgs) {
                $friendlyName          = lcfirst(substr($methodName, 2));
                $result[$friendlyName] = $this->$methodName();
            }
        }

        return $result;
    }
}