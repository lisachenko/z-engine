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
 * A read/patch facade over the relocated zend_persistent_script of a cache
 * binary: hands out the same engine-struct wrappers the rest of z-engine uses
 * (ReflectionFunction, ReflectionClass, HashTable, ReflectionValue), so a
 * mutation made through them lands in the image and is written back by
 * {@see BinaryCacheFile::save()}.
 *
 * No public method returns a CData (AGENTS.md): callers see framework wrapper
 * objects only. Constructed exclusively by BinaryCacheFile::script().
 *
 * @internal instances come from BinaryCacheFile::script()
 */
final class PersistentScriptView
{
    public function __construct(private readonly CData $script) {}

    /**
     * The cached script's source path (zend_persistent_script.script.filename)
     */
    public function scriptName(): string
    {
        return StringEntry::fromCData($this->script->script->filename)->getStringValue();
    }

    /**
     * The main (file-level) op_array as a ReflectionFunction
     */
    public function mainOpArray(): ReflectionFunction
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
     * Every user function compiled into the script
     *
     * @return list<ReflectionFunction>
     */
    public function functions(): array
    {
        $functions = [];
        foreach ($this->functionTable() as $value) {
            $functions[] = ReflectionFunction::fromCData($value->getRawFunction());
        }

        return $functions;
    }

    /**
     * Every class compiled into the script
     *
     * @return list<ReflectionClass>
     */
    public function classes(): array
    {
        $classes = [];
        foreach ($this->classTable() as $value) {
            $classes[] = ReflectionClass::fromCData($value->getRawClass());
        }

        return $classes;
    }
}
