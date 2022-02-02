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

use Closure;
use FFI;
use FFI\CData;
use FFI\CType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZEngine\Constants\Defines;
use ZEngine\System\Compiler;
use ZEngine\System\Executor;
use ZEngine\System\Hook\AstProcessHook;
use ZEngine\Type\HashTable;

/**
 * Class Core
 */
class Core
{

    /* Class, property and method flags                               class|meth.|prop.|const*/
    /*                                                               |     |     |     |     */
    /* Common flags                                                  |     |     |     |     */
    /* ============                                                  |     |     |     |     */
    /*                                                               |     |     |     |     */
    /* Visibility flags (public < protected < private);              |     |     |     |     */
    public const ZEND_ACC_PUBLIC = Defines::ZEND_ACC_PUBLIC; /*    |     |  X  |  X  |  X  */
    public const ZEND_ACC_PROTECTED = Defines::ZEND_ACC_PROTECTED; /*    |     |  X  |  X  |  X  */
    public const ZEND_ACC_PRIVATE = Defines::ZEND_ACC_PRIVATE; /*    |     |  X  |  X  |  X  */
    /*                                                               |     |     |     |     */
    /* Property or method overrides private one                      |     |     |     |     */
    public const ZEND_ACC_CHANGED = Defines::ZEND_ACC_CHANGED; /*    |     |  X  |  X  |     */
    /*                                                               |     |     |     |     */
    /* Static method or property                                     |     |     |     |     */
    public const ZEND_ACC_STATIC = Defines::ZEND_ACC_STATIC; /*    |     |  X  |  X  |     */
    /*                                                               |     |     |     |     */
    /* Final class or method                                         |     |     |     |     */
    public const ZEND_ACC_FINAL = Defines::ZEND_ACC_FINAL; /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Abstract method                                               |     |     |     |     */
    public const ZEND_ACC_ABSTRACT = Defines::ZEND_ACC_ABSTRACT; /*    |  X  |  X  |     |     */
    public const ZEND_ACC_EXPLICIT_ABSTRACT_CLASS = Defines::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Immutable op_array and class_entries                          |     |     |     |     */
    /* (implemented only for lazy loading of op_arrays);             |     |     |     |     */
    public const ZEND_ACC_IMMUTABLE = Defines::ZEND_ACC_IMMUTABLE; /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function has typed arguments / class has typed props          |     |     |     |     */
    public const ZEND_ACC_HAS_TYPE_HINTS = Defines::ZEND_ACC_HAS_TYPE_HINTS; /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Top-level class or function declaration                       |     |     |     |     */
    public const ZEND_ACC_TOP_LEVEL = Defines::ZEND_ACC_TOP_LEVEL; /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array or class is preloaded                                |     |     |     |     */
    public const ZEND_ACC_PRELOADED = Defines::ZEND_ACC_PRELOADED; /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Class Flags (unused: 16...);                                  |     |     |     |     */
    /* ===========                                                   |     |     |     |     */
    /*                                                               |     |     |     |     */
    /* Special class types                                           |     |     |     |     */
    public const ZEND_ACC_INTERFACE = Defines::ZEND_ACC_INTERFACE; /*    |  X  |     |     |     */
    public const ZEND_ACC_TRAIT = Defines::ZEND_ACC_TRAIT; /*    |  X  |     |     |     */
    public const ZEND_ACC_ANON_CLASS = Defines::ZEND_ACC_ANON_CLASS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class linked with parent, interfaces and traits               |     |     |     |     */
    public const ZEND_ACC_LINKED = Defines::ZEND_ACC_LINKED; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class is abstract, since it is set by any                     |     |     |     |     */
    /* abstract method                                               |     |     |     |     */
    public const ZEND_ACC_IMPLICIT_ABSTRACT_CLASS = Defines::ZEND_ACC_IMPLICIT_ABSTRACT_CLASS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class has magic methods __get/__set/__unset/                  |     |     |     |     */
    /* __isset that use guards                                       |     |     |     |     */
    public const ZEND_ACC_USE_GUARDS = Defines::ZEND_ACC_USE_GUARDS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class constants updated                                       |     |     |     |     */
    public const ZEND_ACC_CONSTANTS_UPDATED = Defines::ZEND_ACC_CONSTANTS_UPDATED; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class extends another class                                   |     |     |     |     */
//    public const ZEND_ACC_INHERITED = Defines::ZEND_ACC_INHERITED; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class implements interface(s);                                |     |     |     |     */
//    public const ZEND_ACC_IMPLEMENT_INTERFACES = Defines::ZEND_ACC_IMPLEMENT_INTERFACES; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class uses trait(s);                                          |     |     |     |     */
//    public const ZEND_ACC_IMPLEMENT_TRAITS = Defines::ZEND_ACC_IMPLEMENT_TRAITS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* User class has methods with static variables                  |     |     |     |     */
    public const ZEND_HAS_STATIC_IN_METHODS = Defines::ZEND_HAS_STATIC_IN_METHODS; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Whether all property types are resolved to CEs                |     |     |     |     */
//    public const ZEND_ACC_PROPERTY_TYPES_RESOLVED = Defines::ZEND_ACC_PROPERTY_TYPES_RESOLVED; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Children must reuse parent get_iterator();                    |     |     |     |     */
    public const ZEND_ACC_REUSE_GET_ITERATOR = Defines::ZEND_ACC_REUSE_GET_ITERATOR; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Parent class is resolved (CE);.                               |     |     |     |     */
    public const ZEND_ACC_RESOLVED_PARENT = Defines::ZEND_ACC_RESOLVED_PARENT; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Interfaces are resolved (CEs);.                               |     |     |     |     */
    public const ZEND_ACC_RESOLVED_INTERFACES = Defines::ZEND_ACC_RESOLVED_INTERFACES; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class has unresolved variance obligations.                    |     |     |     |     */
    public const ZEND_ACC_UNRESOLVED_VARIANCE = Defines::ZEND_ACC_UNRESOLVED_VARIANCE; /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Function Flags (unused: 28...30);                             |     |     |     |     */
    /* ==============                                                |     |     |     |     */
    /*                                                               |     |     |     |     */
    /* deprecation flag                                              |     |     |     |     */
    public const ZEND_ACC_DEPRECATED = Defines::ZEND_ACC_DEPRECATED; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function returning by reference                               |     |     |     |     */
    public const ZEND_ACC_RETURN_REFERENCE = Defines::ZEND_ACC_RETURN_REFERENCE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function has a return type                                    |     |     |     |     */
    public const ZEND_ACC_HAS_RETURN_TYPE = Defines::ZEND_ACC_HAS_RETURN_TYPE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function with variable number of arguments                    |     |     |     |     */
    public const ZEND_ACC_VARIADIC = Defines::ZEND_ACC_VARIADIC; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array has finally blocks (user only);                      |     |     |     |     */
    public const ZEND_ACC_HAS_FINALLY_BLOCK = Defines::ZEND_ACC_HAS_FINALLY_BLOCK; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* "main" op_array with                                          |     |     |     |     */
    /* ZEND_DECLARE_CLASS_DELAYED opcodes                            |     |     |     |     */
    public const ZEND_ACC_EARLY_BINDING = Defines::ZEND_ACC_EARLY_BINDING; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* call through user function trampoline. e.g.                   |     |     |     |     */
    /* __call, __callstatic                                          |     |     |     |     */
    public const ZEND_ACC_CALL_VIA_TRAMPOLINE = Defines::ZEND_ACC_CALL_VIA_TRAMPOLINE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* disable inline caching                                        |     |     |     |     */
    public const ZEND_ACC_NEVER_CACHE = Defines::ZEND_ACC_NEVER_CACHE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Closure related                                               |     |     |     |     */
    public const ZEND_ACC_CLOSURE = Defines::ZEND_ACC_CLOSURE; /*    |     |  X  |     |     */
    public const ZEND_ACC_FAKE_CLOSURE = Defines::ZEND_ACC_FAKE_CLOSURE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* run_time_cache allocated on heap (user only);                 |     |     |     |     */
    public const ZEND_ACC_HEAP_RT_CACHE = Defines::ZEND_ACC_HEAP_RT_CACHE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* method flag used by Closure::__invoke();                      |     |     |     |     */
    public const ZEND_ACC_USER_ARG_INFO = Defines::ZEND_ACC_USER_ARG_INFO; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    public const ZEND_ACC_GENERATOR = Defines::ZEND_ACC_GENERATOR; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    public const ZEND_ACC_DONE_PASS_TWO = Defines::ZEND_ACC_DONE_PASS_TWO; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* internal function is allocated at arena (int only);           |     |     |     |     */
    public const ZEND_ACC_ARENA_ALLOCATED = Defines::ZEND_ACC_ARENA_ALLOCATED; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array is a clone of trait method                           |     |     |     |     */
    public const ZEND_ACC_TRAIT_CLONE = Defines::ZEND_ACC_TRAIT_CLONE; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* functions is a constructor                                    |     |     |     |     */
    public const ZEND_ACC_CTOR = Defines::ZEND_ACC_CTOR; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* function is a destructor                                      |     |     |     |     */
//    public const ZEND_ACC_DTOR = Defines::ZEND_ACC_DTOR; /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array uses strict mode types                               |     |     |     |     */
    public const ZEND_ACC_STRICT_TYPES = Defines::ZEND_ACC_STRICT_TYPES; /*    |     |  X  |     |     */

    public const ZEND_ACC_PPP_MASK = Defines::ZEND_ACC_PPP_MASK;

    /**
     * Type of zend_function.type
     */
    public const ZEND_INTERNAL_FUNCTION = Defines::ZEND_INTERNAL_FUNCTION;
    public const ZEND_USER_FUNCTION = Defines::ZEND_USER_FUNCTION;
    public const ZEND_EVAL_CODE = Defines::ZEND_EVAL_CODE;

    public const ZEND_INTERNAL_CLASS = Defines::ZEND_INTERNAL_CLASS;
    public const ZEND_USER_CLASS     = Defines::ZEND_USER_CLASS;

    /**
     * User opcode handler return values
     */
    public const ZEND_USER_OPCODE_CONTINUE    = Defines::ZEND_USER_OPCODE_CONTINUE; /* execute next opcode */
    public const ZEND_USER_OPCODE_RETURN      = Defines::ZEND_USER_OPCODE_RETURN; /* exit from executor (return from function) */
    public const ZEND_USER_OPCODE_DISPATCH    = Defines::ZEND_USER_OPCODE_DISPATCH; /* call original opcode handler */
    public const ZEND_USER_OPCODE_ENTER       = Defines::ZEND_USER_OPCODE_ENTER; /* enter into new op_array without recursion */
    public const ZEND_USER_OPCODE_LEAVE       = Defines::ZEND_USER_OPCODE_LEAVE; /* return to calling op_array within the same executor */
    public const ZEND_USER_OPCODE_DISPATCH_TO = Defines::ZEND_USER_OPCODE_DISPATCH_TO; /* call original handler of returned opcode */
    // TODO figure out the tyepdef enum
    public const SUCCESS = 0;
    public const FAILURE = -1;

    /**
     * This should be equal to ZEND_MM_ALIGNMENT
     */
    public const MM_ALIGNMENT = Defines::ZEND_MM_ALIGNMENT;

    /**
     * Provides an access to the executor global state
     */
    public static Executor $executor;

    /**
     * Provides an access to the compiler global state
     */
    public static Compiler $compiler;

    /**
     * Contains the list of loaded modules (extensions)
     */
    public static HashTable $modules;

    /**
     * Stores an internal instance of low-level FFI binding
     */
    private static FFI $engine;

    /**
     * Performs Z-engine core initialization
     */
    public static function init()
    {
        $isWindowsPlatform = stripos(PHP_OS, 'WIN') === 0;

        try {
            $engine = FFI::scope('ZEngine');
        } catch (FFI\Exception $e) {
            if (ini_get('ffi.enable') === 'preload' && PHP_SAPI !== 'cli') {
                throw new \RuntimeException('Preload mode requires that you call Core::preload before');
            }
            // If not, then load definitions by hand
            $definition = require_once __DIR__ . "/../include/engine.php";
            $arguments  = [$definition];

            // For Windows platform we should load symbols from the shared php7.dll library
            if ($isWindowsPlatform) {
                $arguments[] = 'php' . PHP_MAJOR_VERSION . '.dll';
            }

            $engine = FFI::cdef(...$arguments);
        }
        self::$engine = $engine;

        if(ZEND_THREAD_SAFE) {
            // #define CG(v) ZEND_TSRMG_FAST(compiler_globals_offset, zend_compiler_globals *, v)
            // #define ZEND_TSRMG_FAST TSRMG_FAST
            // #define TSRMG_FAST(offset, type, element)	(TSRMG_FAST_BULK(offset, type)->element)
            // #define TSRMG_FAST_BULK(offset, type)	((type) (((char*) tsrm_get_ls_cache())+(offset)))
            // (zend_compiler_globals *) ((char*) tsrm_get_ls_cache()+compiler_globals_offset)
            $executorGlobals = FFI::cast($engine->type("zend_executor_globals*"), FFI::cast("char*", $engine->tsrm_get_ls_cache()) + $engine->executor_globals_offset);
            $compilerGlobals = FFI::cast($engine->type("zend_compiler_globals*"), FFI::cast("char*", $engine->tsrm_get_ls_cache()) + $engine->compiler_globals_offset);
            self::$executor = new Executor($executorGlobals);
            self::$compiler = new Compiler($compilerGlobals);
        } else {
            // # define CG(v) (compiler_globals.v)
            self::$executor = new Executor($engine->executor_globals);
            self::$compiler = new Compiler($engine->compiler_globals);
        }
        self::$modules  = new HashTable(Core::addr($engine->module_registry));

        self::preloadFrameworkClasses();
    }

    /**
     * Preloads definition and Core for ffi.preload mode, should be called during preload stage for better performance
     */
    public static function preload()
    {
        $definition = require_once __DIR__ . "/../include/engine.php";
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'php_ffi');
            file_put_contents($tempFile, $definition);
            FFI::load($tempFile);
        } finally {
            unlink($tempFile);
        }

        // Performs initialization of properties, otherwise we will get an error about uninitialized properties
        Core::init();
    }

    /**
     * Internally cast a memory at given pointer to another type
     */
    public static function cast(string $type, CData $pointer): CData
    {
        return self::$engine->cast($type, $pointer);
    }

    /**
     * Returns the size of given type
     */
    public static function sizeof($cType): int
    {
        return FFI::sizeof($cType);
    }

    /**
     * Returns the size of given type
     */
    public static function addr(CData $variable): CData
    {
        return FFI::addr($variable);
    }

    /**
     * Copies $size bytes from memory area $source to memory area $target.
     * $source may be any native data structure (FFI\CData) or PHP string.
     *
     * @param CData $target
     * @param mixed $source
     * @param int $size
     */
    public static function memcpy(CData $target, $source, int $size): void
    {
        FFI::memcpy($target, $source, $size);
    }

    /**
     * Creates a new instance of specific type
     *
     * @param string $type Name of the type
     */
    public static function new(string $type, bool $owned = true, bool $persistent = false): CData
    {
        return self::$engine->new($type, $owned, $persistent);
    }

    /**
     * Returns the size of given type
     */
    public static function free(CData $variable): void
    {
        FFI::free($variable);
    }

    /**
     * Returns a CType definition for engine by type name
     *
     * @param string $type Name of the type
     */
    public static function type(string $type): CType
    {
        return self::$engine->type($type);
    }

    /**
     * Perform execution of imported functions
     *
     * @param string $function Name of the function to call
     * @param array  $arguments Function args
     *
     * @return mixed
     */
    public static function call(string $function, ...$arguments)
    {
        return self::$engine->$function(...$arguments);
    }

    /**
     * Returns an aligned size
     *
     * @see ZEND_MM_ALIGNED_SIZE(size) macro implementation
     */
    public static function getAlignedSize(int $size): int
    {
        $mask = ~ (self::MM_ALIGNMENT -1);
        $size = (($size + self::MM_ALIGNMENT -1) & $mask);

        return $size;
    }

    /**
     * Returns standard object handlers
     */
    public static function getStandardObjectHandlers(): CData
    {
        return self::$engine->std_object_handlers;
    }

    /**
     * Installs a hook for the `zend_ast_process` engine global callback
     *
     * @param Closure $handler function(NodeInterface $node): void callback
     */
    public static function setASTProcessHandler(Closure $handler): void
    {
        $hook = new AstProcessHook($handler, self::$engine);
        $hook->install();
    }

    /**
     * This method preloads all framework classes to bypass all possible hooks
     */
    private static function preloadFrameworkClasses(): void
    {
        $dir = new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::KEY_AS_PATHNAME);

        /** @var \SplFileInfo[] $iterator */
        $iterator = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            include_once $fileInfo->getPathname();
        }
    }
}
