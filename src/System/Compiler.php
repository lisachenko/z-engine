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
use ZEngine\AbstractSyntaxTree\AstOwnership;
use ZEngine\AbstractSyntaxTree\NodeFactory;
use ZEngine\AbstractSyntaxTree\NodeInterface;
use ZEngine\Core;
use ZEngine\Generated\zend_arena;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * Class Compiler provides an access to the compiler global state (CG)
 *
 * Memory notes: parseString() returns a DETACHED tree whose arena and payload references
 * are owned by an AstOwnership handle travelling with every node built from it - keep any
 * node alive while reading the tree, and do not graft its nodes into the live compilation
 * AST (getAST()), which stays engine-owned and borrowed. See docs/long-running.md.
 */
class Compiler
{
    /**
     * The following constants may be combined in CG(compiler_options) to change the default compiler behavior
     */

    /* generate extended debug information */
    public const COMPILE_EXTENDED_STMT  = (1 << 0);
    public const COMPILE_EXTENDED_FCALL = (1 << 1);
    public const COMPILE_EXTENDED_INFO  = (self::COMPILE_EXTENDED_STMT | self::COMPILE_EXTENDED_FCALL);

    /* call op_array handler of extendions */
    public const COMPILE_HANDLE_OP_ARRAY = (1 << 2);

    /* generate INIT_FCALL_BY_NAME for internal functions instead of INIT_FCALL */
    public const COMPILE_IGNORE_INTERNAL_FUNCTIONS = (1 << 3);

    /* don't perform early binding for classes inherited form internal ones;
     * in namespaces assume that internal class that doesn't exist at compile-time
     * may apper in run-time */
    public const COMPILE_IGNORE_INTERNAL_CLASSES = (1 << 4);

    /* generate DECLARE_CLASS_DELAYED opcode to delay early binding */
    public const COMPILE_DELAYED_BINDING = (1 << 5);

    /* disable constant substitution at compile-time */
    public const COMPILE_NO_CONSTANT_SUBSTITUTION = (1 << 6);

    /* generate INIT_FCALL_BY_NAME for userland functions instead of INIT_FCALL */
    public const COMPILE_IGNORE_USER_FUNCTIONS = (1 << 9);

    /* force ACC_USE_GUARDS for all classes */
    public const COMPILE_GUARDS = (1 << 10);

    /* disable builtin special case function calls */
    public const COMPILE_NO_BUILTINS = (1 << 11);

    /* this flag is set when compiler invoked by opcache_compile_file() */
    public const COMPILE_WITHOUT_EXECUTION = (1 << 14);

    /* this flag is set when compiler invoked during preloading */
    public const COMPILE_PRELOAD = (1 << 15);

    /* disable jumptable optimization for switch statements */
    public const COMPILE_NO_JUMPTABLES = (1 << 16);

    /* this flag is set when compiler invoked during preloading in separate process */
    public const COMPILE_PRELOAD_IN_CHILD = (1 << 17);

    /* The default value for CG(compiler_options) */
    public const COMPILE_DEFAULT = self::COMPILE_HANDLE_OP_ARRAY;

    /* The default value for CG(compiler_options) during eval() */
    public const COMPILE_DEFAULT_FOR_EVAL = 0;

    /**
     * The engine views below are bound to their compiler-globals table once, in the constructor,
     * and are therefore published as `public private(set)`: everybody may read (and mutate through)
     * the wrapper, nobody may swap the wrapper itself. A replaced view would silently detach the
     * whole process from the engine table it is supposed to reflect.
     */

    /**
     * Contains a hashtable with all registered classes
     *
     * @var HashTable|ReflectionValue[]
     */
    public private(set) HashTable $classTable;

    /**
     * Contains a hashtable with all registered functions
     *
     * @var HashTable|ReflectionValue[]
     */
    public private(set) HashTable $functionTable;

    /**
     * Holds an internal pointer to the compiler_globals structure
     */
    private CData $pointer;
    /**
     * @param \FFI\CData $pointer
     */

    public function __construct(object $pointer)
    {
        $this->pointer = $pointer;

        $classTable = $pointer->class_table;
        assert($classTable instanceof CData);
        $functionTable = $pointer->function_table;
        assert($functionTable instanceof CData);

        $this->classTable    = HashTable::fromCData($classTable);
        $this->functionTable = HashTable::fromCData($functionTable);
    }

    /**
     * Returns the base address of the map-ptr area, CG(map_ptr_base), or 0 when unallocated
     *
     * The area holds the per-request slots addressed by the offset form of every
     * ZEND_MAP_PTR field (run-time caches, static members and the fast class-name cache of
     * permanent interned strings). An address is returned instead of a pointer because the
     * slots are addressed by byte offset, not as a typed array.
     *
     * @internal used by StringEntry to reach the engine's fast class-entry cache
     */
    public function getMapPointerBaseAddress(): int
    {
        $mapPointerBase = $this->pointer->map_ptr_base;
        if ($mapPointerBase === null) {
            return 0;
        }
        assert($mapPointerBase instanceof CData);

        // A void* view reads as the pointed-to memory, so the pointer value itself is
        // taken through a typed (char*) view of the very same field
        return Core::addressOf(Core::cast('char *', $mapPointerBase));
    }

    /**
     * Returns the number of slots handed out in the map-ptr area, CG(map_ptr_last)
     *
     * @internal used to validate a map-ptr slot before dereferencing it
     */
    public function getMapPointerLast(): int
    {
        $lastSlot = $this->pointer->map_ptr_last;
        assert(is_int($lastSlot));

        return $lastSlot;
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
     * Returns the class entry which is being compiled at the moment, or null outside class compilation
     *
     * Memory notes: the returned wrapper is a BORROWED view over CG(active_class_entry) (no addref,
     * no ownership) - the engine resets the pointer once the class body compilation finishes, so use
     * the value only while compilation of that class is still in progress (e.g. inside compiler hooks).
     */
    public function getActiveClassEntry(): ?ReflectionClass
    {
        $activeClassEntry = $this->pointer->active_class_entry;
        if ($activeClassEntry === null) {
            return null;
        }
        assert($activeClassEntry instanceof CData);

        return ReflectionClass::fromCData($activeClassEntry);
    }

    /**
     * Returns the op_array which is being compiled at the moment, or null outside compilation
     *
     * Memory notes: the returned `zend_op_array*` is a raw BORROWED engine pointer (no ownership) -
     * it is only valid while the engine compiles that op_array, do not store it beyond the current
     * compilation (e.g. beyond the `zend_ast_process` callback it was observed in).
     *
     * @return \FFI\CData|null
     */
    public function getActiveOpArray(): ?object
    {
        $activeOpArray = $this->pointer->active_op_array;
        assert($activeOpArray === null || $activeOpArray instanceof CData);

        return $activeOpArray;
    }

    /**
     * Returns the line number which is compiled at the moment, aka CG(zend_lineno)
     */
    public function getCurrentLineNumber(): int
    {
        $lineNumber = $this->pointer->zend_lineno;
        assert(is_int($lineNumber));

        return $lineNumber;
    }

    /**
     * Returns the hashtable with all registered auto-globals (_SERVER, GLOBALS, etc), aka CG(auto_globals)
     *
     * Memory notes: the wrapper is a BORROWED view over the engine-owned CG(auto_globals) table
     * (no addref, no ownership). Values in this table are raw `zend_auto_global*` pointers, not
     * ordinary zvals - inspect keys only, do not read them as native PHP values.
     *
     * @return HashTable|ReflectionValue[]
     */
    public function getAutoGlobals(): HashTable
    {
        $autoGlobals = $this->pointer->auto_globals;
        assert($autoGlobals instanceof CData);

        return HashTable::fromCData($autoGlobals);
    }

    /**
     * Returns extra flags that will be applied to the next compiled function, aka CG(extra_fn_flags)
     */
    public function getExtraFnFlags(): int
    {
        $extraFnFlags = $this->pointer->extra_fn_flags;
        assert(is_int($extraFnFlags));

        return $extraFnFlags;
    }

    /**
     * Configures extra flags (ZEND_ACC_xxx) that will be applied to the next compiled function
     *
     * This is an engine-documented pattern: for example, the engine itself uses it to mark
     * fake closures and generators. The engine resets the value during compilation, so set
     * it right before the compilation it should affect and restore the previous value after.
     *
     * @param int $flags New set of extra function flags
     *
     * @return int Previous value of CG(extra_fn_flags)
     */
    public function setExtraFnFlags(int $flags): int
    {
        $previousFlags = $this->getExtraFnFlags();

        $this->pointer->extra_fn_flags = $flags;

        return $previousFlags;
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
        // The scanner takes ownership semantics on this zval: it may release the string
        // inside and replace it with a padded copy, so the container must hold its own
        // reference - release() then drops whichever string ends up inside
        $sourceValue = new StringEntry($source);
        $sourceEntry = ReflectionValue::newEntry(ReflectionValue::IS_STRING, StructArray::at($sourceValue->getRawValue()))
            ->acquireReference();
        $rawSourceVal = $sourceEntry->getRawValue();

        // Since PHP 8.1 the filename is passed as a zend_string* (kept alive
        // in a local for the whole parse)
        $fileNameValue = new StringEntry($fileName !== '' ? $fileName : 'z-engine parsed code');

        $originalLexState        = Core::new('zend_lex_state');
        $originalCompilationMode = $this->isInCompilation();
        $this->setCompilationMode(true);

        Core::call('zend_save_lexical_state', Core::addr($originalLexState));

        // Returns void since PHP 8.0, scanning problems surface via zendparse instead
        Core::call('zend_prepare_string_for_scanning', $rawSourceVal, $fileNameValue->getRawValue());

        // zend_prepare_string_for_scanning extends the source into a fresh, padded rc=1
        // zend_string and stores it back in the zval - even when the original was an
        // interned literal. Claim that fresh reference so release() frees it in every case;
        // acquireReference() above only marked ownership for a refcounted (non-interned)
        // source, so an interned source would otherwise leak the padded scanner copy.
        $sourceEntry->claimReference();

        [$arena, $arenaBuffer] = $this->createArena(1024 * 32);

        $this->pointer->ast       = null;
        $this->pointer->ast_arena = $arena;

        $ast = null;
        try {
            $result = Core::call('zendparse');
            // restore_lexical_state changes CG(ast) and CG(ast_arena), grab the tree before it
            $ast = $this->pointer->ast;
            if ($result !== Core::SUCCESS) {
                (new AstOwnership($ast, $arenaBuffer))->release();
                $ast = null;
            }
        } catch (\Throwable $error) {
            // A ParseError raised by the engine mid-parse: destroy the partial tree and rethrow
            (new AstOwnership($this->pointer->ast, $arenaBuffer))->release();
            throw $error;
        } finally {
            if ($ast === null) {
                $this->pointer->ast       = null;
                $this->pointer->ast_arena = null;
            }
            Core::call('zend_restore_lexical_state', Core::addr($originalLexState));
            $this->setCompilationMode($originalCompilationMode);

            // The scanner made its own copy of the source, the temporary zval container is ours to free
            $sourceEntry->release();
        }

        if ($ast === null) {
            throw new \RuntimeException('Unable to parse the given source code');
        }

        // The ownership handle travels with every node built from this tree and releases
        // the payload references and the arena buffer once the last wrapper is collected
        return NodeFactory::fromCData($ast, new AstOwnership($ast, $arenaBuffer));
    }

    /**
     * Creates an arena for misc needs
     *
     * @param int $size Size of arena to create
     * @see zend_arena.h:zend_arena_create
     *
     * @return array{CData, CData} The initialized zend_arena pointer and the underlying raw buffer
     */
    private function createArena(int $size): array
    {
        $rawBuffer = Core::new("char[$size]", false);
        $arena     = Core::cast('zend_arena *', $rawBuffer);

        $arena->ptr  = $rawBuffer + Core::getAlignedSize(Core::sizeOfType(zend_arena::class));
        $arena->end  = $rawBuffer + $size;
        $arena->prev = null;

        return [$arena, $rawBuffer];
    }
}
