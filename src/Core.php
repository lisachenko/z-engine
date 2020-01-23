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

use FFI;
use FFI\CData;
use FFI\CType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZEngine\AbstractSyntaxTree\NodeFactory;
use ZEngine\Macro\DefinitionLoader;
use ZEngine\System\Compiler;
use ZEngine\System\Executor;

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
    public const ZEND_ACC_PUBLIC =                  (1 <<  0); /*    |     |  X  |  X  |  X  */
    public const ZEND_ACC_PROTECTED =               (1 <<  1); /*    |     |  X  |  X  |  X  */
    public const ZEND_ACC_PRIVATE =                 (1 <<  2); /*    |     |  X  |  X  |  X  */
    /*                                                               |     |     |     |     */
    /* Property or method overrides private one                      |     |     |     |     */
    public const ZEND_ACC_CHANGED =                 (1 <<  3); /*    |     |  X  |  X  |     */
    /*                                                               |     |     |     |     */
    /* Static method or property                                     |     |     |     |     */
    public const ZEND_ACC_STATIC =                  (1 <<  4); /*    |     |  X  |  X  |     */
    /*                                                               |     |     |     |     */
    /* Final class or method                                         |     |     |     |     */
    public const ZEND_ACC_FINAL =                   (1 <<  5); /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Abstract method                                               |     |     |     |     */
    public const ZEND_ACC_ABSTRACT =                (1 <<  6); /*    |  X  |  X  |     |     */
    public const ZEND_ACC_EXPLICIT_ABSTRACT_CLASS = (1 <<  6); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Immutable op_array and class_entries                          |     |     |     |     */
    /* (implemented only for lazy loading of op_arrays);             |     |     |     |     */
    public const ZEND_ACC_IMMUTABLE =               (1 <<  7); /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function has typed arguments / class has typed props          |     |     |     |     */
    public const ZEND_ACC_HAS_TYPE_HINTS =          (1 <<  8); /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Top-level class or function declaration                       |     |     |     |     */
    public const ZEND_ACC_TOP_LEVEL =               (1 <<  9); /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array or class is preloaded                                |     |     |     |     */
    public const ZEND_ACC_PRELOADED =               (1 << 10); /*    |  X  |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Class Flags (unused: 16...);                                  |     |     |     |     */
    /* ===========                                                   |     |     |     |     */
    /*                                                               |     |     |     |     */
    /* Special class types                                           |     |     |     |     */
    public const ZEND_ACC_INTERFACE =               (1 <<  0); /*    |  X  |     |     |     */
    public const ZEND_ACC_TRAIT =                   (1 <<  1); /*    |  X  |     |     |     */
    public const ZEND_ACC_ANON_CLASS =              (1 <<  2); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class linked with parent, interfaces and traits               |     |     |     |     */
    public const ZEND_ACC_LINKED =                  (1 <<  3); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class is abstract, since it is set by any                     |     |     |     |     */
    /* abstract method                                               |     |     |     |     */
    public const ZEND_ACC_IMPLICIT_ABSTRACT_CLASS = (1 <<  4); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class has magic methods __get/__set/__unset/                  |     |     |     |     */
    /* __isset that use guards                                       |     |     |     |     */
    public const ZEND_ACC_USE_GUARDS =              (1 << 11); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class constants updated                                       |     |     |     |     */
    public const ZEND_ACC_CONSTANTS_UPDATED =       (1 << 12); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class extends another class                                   |     |     |     |     */
    public const ZEND_ACC_INHERITED =               (1 << 13); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class implements interface(s);                                |     |     |     |     */
    public const ZEND_ACC_IMPLEMENT_INTERFACES =    (1 << 14); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class uses trait(s);                                          |     |     |     |     */
    public const ZEND_ACC_IMPLEMENT_TRAITS =        (1 << 15); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* User class has methods with static variables                  |     |     |     |     */
    public const ZEND_HAS_STATIC_IN_METHODS =       (1 << 16); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Whether all property types are resolved to CEs                |     |     |     |     */
    public const ZEND_ACC_PROPERTY_TYPES_RESOLVED = (1 << 17); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Children must reuse parent get_iterator();                    |     |     |     |     */
    public const ZEND_ACC_REUSE_GET_ITERATOR =      (1 << 18); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Parent class is resolved (CE);.                               |     |     |     |     */
    public const ZEND_ACC_RESOLVED_PARENT =         (1 << 19); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Interfaces are resolved (CEs);.                               |     |     |     |     */
    public const ZEND_ACC_RESOLVED_INTERFACES =     (1 << 20); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Class has unresolved variance obligations.                    |     |     |     |     */
    public const ZEND_ACC_UNRESOLVED_VARIANCE =     (1 << 21); /*    |  X  |     |     |     */
    /*                                                               |     |     |     |     */
    /* Function Flags (unused: 28...30);                             |     |     |     |     */
    /* ==============                                                |     |     |     |     */
    /*                                                               |     |     |     |     */
    /* deprecation flag                                              |     |     |     |     */
    public const ZEND_ACC_DEPRECATED =              (1 << 11); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function returning by reference                               |     |     |     |     */
    public const ZEND_ACC_RETURN_REFERENCE =        (1 << 12); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function has a return type                                    |     |     |     |     */
    public const ZEND_ACC_HAS_RETURN_TYPE =         (1 << 13); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Function with variable number of arguments                    |     |     |     |     */
    public const ZEND_ACC_VARIADIC =                (1 << 14); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array has finally blocks (user only);                      |     |     |     |     */
    public const ZEND_ACC_HAS_FINALLY_BLOCK =       (1 << 15); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* "main" op_array with                                          |     |     |     |     */
    /* ZEND_DECLARE_CLASS_DELAYED opcodes                            |     |     |     |     */
    public const ZEND_ACC_EARLY_BINDING =           (1 << 16); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* call through user function trampoline. e.g.                   |     |     |     |     */
    /* __call, __callstatic                                          |     |     |     |     */
    public const ZEND_ACC_CALL_VIA_TRAMPOLINE =     (1 << 18); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* disable inline caching                                        |     |     |     |     */
    public const ZEND_ACC_NEVER_CACHE =             (1 << 19); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* Closure related                                               |     |     |     |     */
    public const ZEND_ACC_CLOSURE =                 (1 << 20); /*    |     |  X  |     |     */
    public const ZEND_ACC_FAKE_CLOSURE =            (1 << 21); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* run_time_cache allocated on heap (user only);                 |     |     |     |     */
    public const ZEND_ACC_HEAP_RT_CACHE =           (1 << 22); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* method flag used by Closure::__invoke();                      |     |     |     |     */
    public const ZEND_ACC_USER_ARG_INFO =           (1 << 23); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    public const ZEND_ACC_GENERATOR =               (1 << 24); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    public const ZEND_ACC_DONE_PASS_TWO =           (1 << 25); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* internal function is allocated at arena (int only);           |     |     |     |     */
    public const ZEND_ACC_ARENA_ALLOCATED =         (1 << 26); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array is a clone of trait method                           |     |     |     |     */
    public const ZEND_ACC_TRAIT_CLONE =             (1 << 27); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* functions is a constructor                                    |     |     |     |     */
    public const ZEND_ACC_CTOR =                    (1 << 28); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* function is a destructor                                      |     |     |     |     */
    public const ZEND_ACC_DTOR =                    (1 << 29); /*    |     |  X  |     |     */
    /*                                                               |     |     |     |     */
    /* op_array uses strict mode types                               |     |     |     |     */
    public const ZEND_ACC_STRICT_TYPES =            (1 << 31); /*    |     |  X  |     |     */

    public const ZEND_ACC_PPP_MASK = self::ZEND_ACC_PUBLIC | self::ZEND_ACC_PROTECTED | self::ZEND_ACC_PRIVATE;

    /**
     * Type of zend_function.type
     */
    public const ZEND_INTERNAL_FUNCTION =   1;
    public const ZEND_USER_FUNCTION =       2;
    public const ZEND_EVAL_CODE =           4;

    /**
     * User opcode handler return values
     */
    public const ZEND_USER_OPCODE_CONTINUE    = 0; /* execute next opcode */
    public const ZEND_USER_OPCODE_RETURN      = 1; /* exit from executor (return from function) */
    public const ZEND_USER_OPCODE_DISPATCH    = 2; /* call original opcode handler */
    public const ZEND_USER_OPCODE_ENTER       = 3; /* enter into new op_array without recursion */
    public const ZEND_USER_OPCODE_LEAVE       = 4; /* return to calling op_array within the same executor */
    public const ZEND_USER_OPCODE_DISPATCH_TO = 0x100; /* call original handler of returned opcode */

    public const SUCCESS = 0;
    public const FAILURE = -1;

    /**
     * This should be equal to ZEND_MM_ALIGNMENT
     */
    public const MM_ALIGNMENT = 8;

    /**
     * Provides an access to the executor global state
     */
    public static Executor $executor;

    /**
     * Provides an access to the compiler global state
     */
    public static Compiler $compiler;

    /**
     * Stores an internal instance of low-level FFI binding
     */
    private static FFI $engine;

    /**
     * Performs Z-engine core initialization
     */
    public static function init()
    {
        $isThreadSafe      = ZEND_THREAD_SAFE;
        $isWindowsPlatform = stripos(PHP_OS, 'WIN') === 0;
        $is64BitPlatform   = PHP_INT_SIZE === 8;

        // TODO: support ts/nts x86/x64 combination
        if ($isThreadSafe || !$is64BitPlatform) {
            throw new \RuntimeException('Only x64 non thread-safe versions of PHP are supported');
        }

        try {
            $engine = FFI::scope('ZEngine');
        } catch (FFI\Exception $e) {
            if (ini_get('ffi.enable') === 'preload' && PHP_SAPI !== 'cli') {
                throw new \RuntimeException('Preload mode requires that you call Core::preload before');
            }
            // If not, then load definitions by hand
            $definition = file_get_contents(DefinitionLoader::wrap(__DIR__.'/../include/engine_x64_nts.h'));
            $arguments  = [$definition];

            // For Windows platform we should load symbols from the shared php7.dll library
            if ($isWindowsPlatform) {
                $arguments[] = 'php7.dll';
            }

            $engine = FFI::cdef(...$arguments);
        }
        self::$engine = $engine;

        assert(!$isThreadSafe, 'Following properties available only for non thread-safe version');
        self::$executor = new Executor($engine->executor_globals);
        self::$compiler = new Compiler($engine->compiler_globals);

        self::preloadFrameworkClasses();
    }

    /**
     * Preloads definition and Core for ffi.preload mode, should be called during preload stage for better performance
     */
    public static function preload()
    {
        $definition = file_get_contents(DefinitionLoader::wrap(__DIR__.'/../include/engine_x64_nts.h'));
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
     * @param callable $callback function(NodeInterface $node): void callback
     */
    public static function setASTProcessHandler(callable $callback): void
    {
        self::$engine->zend_ast_process = function (CData $ast) use ($callback): void {
            $node = NodeFactory::fromCData($ast);
            $callback($node);
        };
    }

    /**
     * This method preloads all framework classes to bypass all possible hooks
     */
    private static function preloadFrameworkClasses(): void
    {
        $hasOpcache = function_exists('opcache_compile_file');

        $dir = new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::KEY_AS_PATHNAME);

        /** @var \SplFileInfo[] $iterator */
        $iterator = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $sourceFile = $fileInfo->getPathname();
            if (!$hasOpcache) {
                include_once $sourceFile;
            } elseif (!opcache_is_script_cached($sourceFile)) {
                opcache_compile_file($sourceFile);
            }
        }
    }
}
