<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\OpCache;

use FFI;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

/**
 * A Reflection-style handle over the relocated zend_persistent_script of a
 * cache binary: it mirrors the native `ReflectionExtension` shape
 * (`getFileName()`, `getFunctions()`, `getClasses()`) and hands out the same
 * engine-struct wrappers the rest of z-engine uses (ReflectionFunction,
 * ReflectionClass, HashTable, ReflectionValue), so a mutation made through them
 * lands in the image and is written back by {@see BinaryCacheFile::save()}.
 *
 * No public method returns a CData (AGENTS.md): callers see framework wrapper
 * objects only. Constructed exclusively by BinaryCacheFile::getReflection().
 *
 * @internal instances come from BinaryCacheFile::getReflection()
 */
final class ReflectionOpcacheFile
{
    /**
     * @param \FFI\CData $script
     */
    public function __construct(private readonly object $script) {}

    /**
     * The cached script's source path (parity with ReflectionClass::getFileName())
     */
    public function getFileName(): string
    {
        return StringEntry::fromCData($this->script->script->filename)->getStringValue();
    }

    /**
     * The main (file-level) op_array as a ReflectionFunction
     */
    public function getScriptFunction(): ReflectionFunction
    {
        $function = Core::cast('zend_function *', FFI::addr($this->script->script->main_op_array));

        return ReflectionFunction::fromCData($function);
    }

    /**
     * Borrowed view over the compiled function table
     */
    public function functionTable(): HashTable
    {
        return HashTable::fromCData(FFI::addr($this->script->script->function_table));
    }

    /**
     * Borrowed view over the compiled class table
     */
    public function classTable(): HashTable
    {
        return HashTable::fromCData(FFI::addr($this->script->script->class_table));
    }

    /**
     * Every user function compiled into the script, keyed by lowercase name
     * (parity with ReflectionExtension::getFunctions())
     *
     * @return array<string, ReflectionFunction>
     */
    public function getFunctions(): array
    {
        $functions = [];
        // The function-table bucket key is already the canonical lowercased
        // function name (parity with ReflectionExtension::getFunctions())
        foreach ($this->functionTable() as $name => $value) {
            $functions[(string) $name] = ReflectionFunction::fromCData($value->getRawFunction());
        }

        return $functions;
    }

    /**
     * Every class compiled into the script, keyed by lowercase name
     * (parity with ReflectionExtension::getClasses())
     *
     * @return array<string, ReflectionClass>
     */
    public function getClasses(): array
    {
        $classes = [];
        // Early-bound classes are stored under an opcache runtime-definition
        // key (a NUL-prefixed rtd key), not their plain name, so key the map
        // by the entry's own lowercased name (parity with ReflectionExtension)
        foreach ($this->classTable() as $value) {
            $class                                           = ReflectionClass::fromCData($value->getRawClass());
            $classes[strtolower((string) $class->getName())] = $class;
        }

        return $classes;
    }
}
