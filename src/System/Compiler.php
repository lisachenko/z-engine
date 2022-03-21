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

namespace ZEngine\System;

use FFI\CData;
use ZEngine\AbstractSyntaxTree\NodeFactory;
use ZEngine\AbstractSyntaxTree\NodeInterface;
use ZEngine\Constants\Defines;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

class Compiler
{
    /**
     * The following constants may be combined in CG(compiler_options) to change the default compiler behavior
     */

    /* generate extended debug information */
    public const COMPILE_EXTENDED_STMT  = Defines::ZEND_COMPILE_EXTENDED_STMT;
    public const COMPILE_EXTENDED_FCALL = Defines::ZEND_COMPILE_EXTENDED_FCALL;
    public const COMPILE_EXTENDED_INFO  = Defines::ZEND_COMPILE_EXTENDED_INFO;

    /* call op_array handler of extendions */
    public const COMPILE_HANDLE_OP_ARRAY = Defines::ZEND_COMPILE_HANDLE_OP_ARRAY;

    /* generate INIT_FCALL_BY_NAME for internal functions instead of INIT_FCALL */
    public const COMPILE_IGNORE_INTERNAL_FUNCTIONS = Defines::ZEND_COMPILE_IGNORE_INTERNAL_FUNCTIONS;

    /* don't perform early binding for classes inherited form internal ones;
     * in namespaces assume that internal class that doesn't exist at compile-time
     * may apper in run-time */
    public const COMPILE_IGNORE_INTERNAL_CLASSES = Defines::ZEND_COMPILE_IGNORE_INTERNAL_CLASSES;

    /* generate DECLARE_CLASS_DELAYED opcode to delay early binding */
    public const COMPILE_DELAYED_BINDING = Defines::ZEND_COMPILE_DELAYED_BINDING;

    /* disable constant substitution at compile-time */
    public const COMPILE_NO_CONSTANT_SUBSTITUTION = Defines::ZEND_COMPILE_NO_CONSTANT_SUBSTITUTION;

    /* disable usage of builtin instruction for strlen() */
//    public const COMPILE_NO_BUILTIN_STRLEN = Defines::ZEND_COMPILE_NO_BUILTIN_STRLEN;

    /* disable substitution of persistent constants at compile-time */
    public const COMPILE_NO_PERSISTENT_CONSTANT_SUBSTITUTION = Defines::ZEND_COMPILE_NO_PERSISTENT_CONSTANT_SUBSTITUTION;

    /* generate INIT_FCALL_BY_NAME for userland functions instead of INIT_FCALL */
    public const COMPILE_IGNORE_USER_FUNCTIONS = Defines::ZEND_COMPILE_IGNORE_USER_FUNCTIONS;

    /* force ACC_USE_GUARDS for all classes */
    public const COMPILE_GUARDS = Defines::ZEND_COMPILE_GUARDS;

    /* disable builtin special case function calls */
    public const COMPILE_NO_BUILTINS = Defines::ZEND_COMPILE_NO_BUILTINS;

    /* result of compilation may be stored in file cache */
    public const COMPILE_WITH_FILE_CACHE = Defines::ZEND_COMPILE_WITH_FILE_CACHE;

    /* ignore functions and classes declared in other files */
    public const COMPILE_IGNORE_OTHER_FILES = Defines::ZEND_COMPILE_IGNORE_OTHER_FILES;

    /* this flag is set when compiler invoked by opcache_compile_file() */
    public const COMPILE_WITHOUT_EXECUTION = Defines::ZEND_COMPILE_WITHOUT_EXECUTION;

    /* this flag is set when compiler invoked during preloading */
    public const COMPILE_PRELOAD = Defines::ZEND_COMPILE_PRELOAD;

    /* disable jumptable optimization for switch statements */
    public const COMPILE_NO_JUMPTABLES = Defines::ZEND_COMPILE_NO_JUMPTABLES;

    /* this flag is set when compiler invoked during preloading in separate process */
    public const COMPILE_PRELOAD_IN_CHILD = Defines::ZEND_COMPILE_PRELOAD_IN_CHILD;

    /* The default value for CG(compiler_options) */
    public const COMPILE_DEFAULT = Defines::ZEND_COMPILE_DEFAULT;

    /* The default value for CG(compiler_options) during eval() */
    public const COMPILE_DEFAULT_FOR_EVAL = Defines::ZEND_COMPILE_DEFAULT_FOR_EVAL;

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
//        $this->filenamesTable = new HashTable($pointer->filenames_table);
    }

    /**
     * Checks if engine is compilation mode or not
     */
    public function isInCompilation(): bool
    {
        return (bool) $this->pointer->in_compilation;
    }

    /**
     * Enables or disables compilation mode
     */
    public function setCompilationMode(bool $enabled): void
    {
        $this->pointer->in_compilation = (int) $enabled;
    }

    /**
     * Returns the Abstract Syntax Tree for given source file
     */
    public function getAST(): NodeInterface
    {
        if ($this->pointer->ast === null) {
            throw new \LogicException('Not in compilation process');
        }

        return NodeFactory::fromCData($this->pointer->ast);
    }

    /**
     * Returns the file name which is compiled at the moment
     */
    public function getFileName(): string
    {
        if ($this->pointer->compiled_filename === null) {
            throw new \LogicException('Not in compilation process');
        }

        return StringEntry::fromCData($this->pointer->compiled_filename)->getStringValue();
    }

    /**
     * Returns current compiler options
     */
    public function getOptions(): int
    {
        return $this->pointer->compiler_options;
    }

    /**
     * Configures compiler options
     *
     * @param int $newOptions See COMPILER_xxx constants in this class
     */
    public function setOptions(int $newOptions): void
    {
        $this->pointer->compiler_options = $newOptions;
    }

    /**
     * Performs parsing of PHP source code into the AST
     *
     * @param string $source   Source code to parse
     * @param string $fileName Optional filename that will be used in the engine
     *
     * @return NodeInterface
     */
    public function parseString(string $source, string $fileName = ''): NodeInterface
    {
        $sourceValue  = new StringEntry($source);
        $sourceRaw    = $sourceValue->getRawValue();
        $rawSourceVal = ReflectionValue::newEntry(ReflectionValue::IS_STRING, $sourceRaw)->getRawValue();

        $originalLexState        = Core::new('zend_lex_state');
        $originalCompilationMode = $this->isInCompilation();
        $this->setCompilationMode(true);

        Core::call('zend_save_lexical_state', Core::addr($originalLexState));

        $result = Core::call('zend_prepare_string_for_scanning', $rawSourceVal, $fileName);

        if ($result === Core::SUCCESS) {
            $this->pointer->ast       = null;
            $this->pointer->ast_arena = $this->createArena(1024 * 32);
            $result = Core::call('zendparse');
            if ($result !== Core::SUCCESS) {
                Core::call('zend_ast_destroy', $this->pointer->ast);
                $this->pointer->ast = null;
                Core::free($this->pointer->ast_arena);
                $this->pointer->ast_arena = null;
            }
        }

        // restore_lexical_state changes CG(ast) and CG(ast_arena)
        $ast  = $this->pointer->ast;
        $node = NodeFactory::fromCData($ast);

        Core::call('zend_restore_lexical_state', Core::addr($originalLexState));
        $this->setCompilationMode($originalCompilationMode);

        return $node;
    }

    /**
     * Creates an arena for misc needs
     *
     * @param int $size Size of arena to create
     * @see zend_arena.h:zend_arena_create
     */
    private function createArena(int $size): CData
    {
        $rawBuffer = Core::new("char[$size]", false);
        $arena     = Core::cast('zend_arena *', $rawBuffer);

        $arena->ptr  = $rawBuffer + Core::getAlignedSize(Core::sizeof(Core::type('zend_arena')));
        $arena->end  = $rawBuffer + $size;
        $arena->prev = null;

        return $arena;
    }
}
