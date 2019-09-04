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

namespace ZEngine;

use FFI\CData;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;

class Compiler
{
    /**
     * Contains a hashtable with all registered classes
     *
     * @var HashTable|ReflectionValue[]
     */
    public HashTable $classTable;

    /**
     * Contains a hashtable with all registered functions
     *
     * @var HashTable|ReflectionValue[]
     */
    public HashTable $functionTable;

    /**
     * Contains a hashtable with all loaded files
     *
     * @var HashTable
     */
    private HashTable $filenamesTable;

    /**
     * Holds an internal pointer to the compiler_globals structure
     */
    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer        = $pointer;
        $this->classTable     = new HashTable($pointer->class_table);
        $this->functionTable  = new HashTable($pointer->function_table);
        $this->filenamesTable = new HashTable($pointer->filenames_table);
    }
}
