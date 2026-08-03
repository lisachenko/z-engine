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
use RuntimeException;
use ZEngine\Hook\HookInterface;
use ZEngine\System\Compiler;
use ZEngine\System\Executor;
use ZEngine\System\Hook\AstProcessHook;
use ZEngine\Type\HashTable;

/**
 * Class Core
 *
 * Central access point to the engine FFI binding, plus the low-level memory management
 * primitives and the process-wide registries (see docs/long-running.md for the full model):
 *
 *  - new()/free(): FFI allocation, for z-engine's OWN containers and buffers only. Engine
 *    memory must never be freed through the FFI allocator - refcounted payloads are
 *    released through the exported engine primitives instead (zval_ptr_dtor, zval_add_ref,
 *    rc_dtor_func, reachable via call()).
 *  - trackedNew()/isTrackedBlock()/untrackAndFree()/untrack(): registry of buffers that
 *    z-engine stores INSIDE engine structures (interface lists, trait name arrays, object
 *    handler blocks), keyed by address. untrackAndFree() frees a block if and only if
 *    z-engine allocated it, which makes buffer replacement legal in every branch while
 *    engine-original (and possibly shared-memory) arrays are never touched. Allocation
 *    class matters: persistent (malloc) only for structures the engine frees with the
 *    persistent allocator (internal classes); request memory everywhere else.
 *  - addressOf(): numeric pointer identity, used for cache keys and the registries above.
 *  - cast(): array-to-pointer decay without FFI::typeof() - probing a CData's kind and then
 *    referencing it again leaks the owned FFI type structure, so arrays are detected with
 *    count() instead.
 *  - registerHook()/unregisterHook()/isTopHook(): per-field chains of installed engine
 *    hooks; the strong references keep libffi trampolines alive while the engine points at
 *    them, and chains unwind strictly in reverse installation order.
 *  - shutdown() (auto-registered via register_shutdown_function in init()): restores every
 *    hooked engine pointer while the trampolines are still valid, then stops all engine
 *    writes for the rest of the request - the invariant that makes long-running runtimes
 *    (worker loops, FPM + opcache preload) safe. reinstallHooks() re-mints trampolines for
 *    SAPIs that cycle FFI callback state between handled requests.
 */
class Core
{
    /**
     * Class, method, property and constant flags (ZEND_ACC_*) for PHP 8.4.
     *
     * Ground truth lives in the generated include/<version>/<platform>/constants.php;
     * EngineConstantsTest asserts these values match it exactly, so any drift
     * in a future engine version fails CI instead of corrupting memory.
     */

    /* Visibility flags (methods, properties, constants) */
    public const ZEND_ACC_PUBLIC    = 0x1;
    public const ZEND_ACC_PROTECTED = 0x2;
    public const ZEND_ACC_PRIVATE   = 0x4;

    /* Common flags */
    public const ZEND_ACC_CHANGED        = 0x8;
    public const ZEND_ACC_STATIC         = 0x10;
    public const ZEND_ACC_FINAL          = 0x20;
    public const ZEND_ACC_ABSTRACT       = 0x40;
    public const ZEND_ACC_IMMUTABLE      = 0x80;
    public const ZEND_ACC_HAS_TYPE_HINTS = 0x100;
    public const ZEND_ACC_TOP_LEVEL      = 0x200;
    public const ZEND_ACC_PRELOADED      = 0x400;

    /* Class flags */
    public const ZEND_ACC_INTERFACE                = 0x1;
    public const ZEND_ACC_TRAIT                    = 0x2;
    public const ZEND_ACC_ANON_CLASS               = 0x4;
    public const ZEND_ACC_LINKED                   = 0x8;
    public const ZEND_ACC_IMPLICIT_ABSTRACT_CLASS  = 0x10;
    public const ZEND_ACC_EXPLICIT_ABSTRACT_CLASS  = 0x40;
    public const ZEND_ACC_USE_GUARDS               = 0x800;
    public const ZEND_ACC_CONSTANTS_UPDATED        = 0x1000;
    public const ZEND_ACC_NO_DYNAMIC_PROPERTIES    = 0x2000;
    public const ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES = 0x8000;
    public const ZEND_ACC_READONLY_CLASS           = 0x10000;
    public const ZEND_ACC_RESOLVED_PARENT          = 0x20000;
    public const ZEND_ACC_RESOLVED_INTERFACES      = 0x40000;
    public const ZEND_ACC_UNRESOLVED_VARIANCE      = 0x80000;
    public const ZEND_ACC_NEARLY_LINKED            = 0x100000;
    public const ZEND_ACC_ENUM                     = 0x10000000;
    public const ZEND_ACC_NOT_SERIALIZABLE         = 0x20000000;
    public const ZEND_ACC_UNINSTANTIABLE           = 0x10000053;

    /* Property flags */
    public const ZEND_ACC_READONLY = 0x80;
    public const ZEND_ACC_VIRTUAL  = 0x200;

    /**
     * Property hook kinds (zend_property_hook_kind), used to index zend_property_info.hooks
     */
    public const ZEND_PROPERTY_HOOK_GET = 0;
    public const ZEND_PROPERTY_HOOK_SET = 1;

    /**
     * Number of entries in a zend_property_info.hooks array (one per hook kind)
     */
    public const ZEND_PROPERTY_HOOK_COUNT = 2;

    /* Function flags */
    public const ZEND_ACC_DEPRECATED          = 0x800;
    public const ZEND_ACC_RETURN_REFERENCE    = 0x1000;
    public const ZEND_ACC_HAS_RETURN_TYPE     = 0x2000;
    public const ZEND_ACC_VARIADIC            = 0x4000;
    public const ZEND_ACC_HAS_FINALLY_BLOCK   = 0x8000;
    public const ZEND_ACC_EARLY_BINDING       = 0x10000;
    public const ZEND_ACC_USES_THIS           = 0x20000;
    public const ZEND_ACC_CALL_VIA_TRAMPOLINE = 0x40000;
    public const ZEND_ACC_NEVER_CACHE         = 0x80000;
    public const ZEND_ACC_TRAIT_CLONE         = 0x100000;
    public const ZEND_ACC_CTOR                = 0x200000;
    public const ZEND_ACC_CLOSURE             = 0x400000;
    public const ZEND_ACC_FAKE_CLOSURE        = 0x800000;
    public const ZEND_ACC_GENERATOR           = 0x1000000;
    public const ZEND_ACC_DONE_PASS_TWO       = 0x2000000;
    public const ZEND_ACC_HEAP_RT_CACHE       = 0x4000000;
    public const ZEND_ACC_STRICT_TYPES        = 0x80000000;

    public const ZEND_ACC_PPP_MASK = self::ZEND_ACC_PUBLIC | self::ZEND_ACC_PROTECTED | self::ZEND_ACC_PRIVATE;

    /**
     * Type of zend_function.type
     */
    public const ZEND_INTERNAL_FUNCTION = 1;
    public const ZEND_USER_FUNCTION     = 2;
    public const ZEND_EVAL_CODE         = 4;

    public const ZEND_INTERNAL_CLASS = 1;
    public const ZEND_USER_CLASS     = 2;

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
     * Contains the list of loaded modules (extensions)
     */
    public static HashTable $modules;

    /**
     * PHP version range supported by this branch: [min, max).
     *
     * Engine memory structures differ between minor PHP versions, therefore a
     * branch of z-engine works with exactly one minor version. Running against
     * anything else is memory corruption, not a degraded mode - hence the hard
     * boot guard. See AGENTS.md ("Version matching is non-negotiable").
     */
    private const SUPPORTED_PHP_VERSION_ID = [80400, 80500];

    /**
     * Stores an internal instance of low-level FFI binding
     */
    private static FFI $engine;

    /**
     * Cache of the generated per-version engine constants (constants.php)
     *
     * @var array<string, int>|null
     */
    private static ?array $engineConstants = null;

    /**
     * Registry of engine-visible buffers allocated by z-engine, keyed by numeric address
     *
     * Keeping the original CData here both marks the block as "ours to free" and prevents
     * ext/ffi from considering the memory unreachable while an engine structure points to it.
     *
     * @var array<int, CData>
     */
    private static array $trackedBlocks = [];

    /**
     * Per-field chains of installed engine hooks, keyed by container address + field name
     *
     * Strong references: an installed hook (and its libffi trampoline) can never be collected
     * while the engine still points at it. Chains unwind in reverse installation order.
     *
     * @var array<string, array<int, HookInterface>>
     */
    private static array $installedHooks = [];

    /**
     * Names (lowercased) of global functions generated via ReflectionFunction::addFunction()
     *
     * These entries point at a zend_function embedded in an immortalized closure object, so the
     * engine must not run its function-table destructor over them. shutdown() unpublishes each
     * one (with the table destructor disabled) while engine writes are still safe, so the engine
     * never walks a dangling entry or double-frees the payload at request end.
     *
     * @var array<string, true>
     */
    private static array $generatedFunctions = [];

    /**
     * Whether Core::shutdown() has run: no engine pointers may be written anymore
     */
    private static bool $isShutdown = false;

    /**
     * Whether the shutdown handler was already registered for this request
     */
    private static bool $shutdownRegistered = false;

    /**
     * Performs Z-engine core initialization
     */
    public static function init(): void
    {
        self::assertSupportedEnvironment();

        try {
            $engine = FFI::scope('ZEngine');
        } catch (FFI\Exception $e) {
            if (ini_get('ffi.enable') === 'preload' && PHP_SAPI !== 'cli') {
                throw new RuntimeException('Preload mode requires that you call Core::preload before');
            }
            // If not, then load definitions by hand
            $definition = file_get_contents(self::resolveArtifact('engine.h'));
            if ($definition === false) {
                throw new RuntimeException('Unable to read the engine definition file');
            }
            $engine = FFI::cdef($definition);
        }
        self::$engine = $engine;

        if (getenv('ZENGINE_STRICT_LAYOUT_CHECK') === '1') {
            self::verifyEngineLayouts($engine);
        }

        self::$executor = new Executor($engine->executor_globals);
        self::$compiler = new Compiler($engine->compiler_globals);
        self::$modules  = HashTable::fromCData(Core::addr($engine->module_registry));

        // Deterministic teardown: user shutdown functions run before object destructors and
        // before ext/ffi RSHUTDOWN frees the callback trampolines, so every hooked engine
        // pointer is restored while writing it is still safe
        if (!self::$shutdownRegistered) {
            register_shutdown_function(static function (): void {
                self::shutdown();
            });
            self::$shutdownRegistered = true;
        }
        self::$isShutdown = false;

        self::preloadFrameworkClasses();
    }

    /**
     * Preloads definition and Core for ffi.preload mode, should be called during preload stage for better performance
     */
    public static function preload(): void
    {
        self::assertSupportedEnvironment();
        // The generated header is fully preprocessed and carries FFI_SCOPE, so
        // it can be loaded as-is
        FFI::load(self::resolveArtifact('engine.h'));

        // Performs initialization of properties, otherwise we will get an error about uninitialized properties
        Core::init();
    }

    /**
     * Refuses to boot on any PHP build this branch has no verified structure definitions for.
     */
    private static function assertSupportedEnvironment(): void
    {
        [$minVersionId, $maxVersionId] = self::SUPPORTED_PHP_VERSION_ID;
        if (PHP_VERSION_ID < $minVersionId || PHP_VERSION_ID >= $maxVersionId) {
            $supported = sprintf('%d.%d', intdiv($minVersionId, 10000), intdiv($minVersionId % 10000, 100));
            throw new RuntimeException(sprintf(
                'z-engine (branch %1$s) supports PHP %1$s only, but you are running PHP %2$s. ' .
                'Engine memory structures differ between minor versions: running a mismatched version ' .
                'would corrupt memory and crash PHP. Install the z-engine release matching your PHP minor ' .
                'version (e.g. the "%1$s" branch for PHP %1$s, "8.0" for legacy PHP 8.0).',
                $supported,
                PHP_VERSION,
            ));
        }

        $header = self::resolveArtifact('engine.h', false);
        if (!is_file($header)) {
            throw new RuntimeException(sprintf(
                'z-engine has no generated engine definitions for your platform "%s" (expected %s). ' .
                'Currently bundled platforms: %s. Platform support is tracked in the repository issues; ' .
                'definitions for a new platform can be generated with `composer gen-headers`.',
                self::platformKey(),
                $header,
                implode(', ', self::availablePlatforms()),
            ));
        }
    }

    /**
     * Platform selector for the generated per-ABI artifacts, e.g. "8.4/linux-x64-nts"
     */
    private static function platformKey(): string
    {
        $arch = php_uname('m');

        return sprintf(
            '%d.%d/%s-%s-%s',
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            strtolower(PHP_OS_FAMILY),
            match ($arch) {
                'x86_64', 'amd64'  => 'x64',
                'aarch64', 'arm64' => 'arm64',
                default            => $arch,
            },
            ZEND_THREAD_SAFE ? 'zts' : 'nts',
        );
    }

    /**
     * Resolves the path of a generated engine artifact for the current platform
     */
    private static function resolveArtifact(string $name, bool $verify = true): string
    {
        $path = __DIR__ . '/../include/' . self::platformKey() . '/' . $name;
        if ($verify && !is_file($path)) {
            throw new RuntimeException("Missing generated engine artifact {$path}");
        }

        return $path;
    }

    /**
     * List of platform keys with bundled definitions, for error reporting
     */
    private static function availablePlatforms(): array
    {
        $platforms = [];
        foreach (glob(__DIR__ . '/../include/*/*/engine.h') ?: [] as $header) {
            $directory   = dirname($header);
            $platforms[] = basename(dirname($directory)) . '/' . basename($directory);
        }

        return $platforms;
    }

    /**
     * Asserts that FFI's view of every engine struct matches the C compiler's
     * ground truth recorded by the generator (layouts.json). This is the
     * anti-segfault airbag: any silent ABI drift aborts the boot instead of
     * corrupting engine memory later.
     */
    private static function verifyEngineLayouts(FFI $engine): void
    {
        $layoutsFile = self::resolveArtifact('layouts.json');
        $layouts     = json_decode((string) file_get_contents($layoutsFile), true, 16, JSON_THROW_ON_ERROR);
        assert(is_array($layouts) && is_array($layouts['structs']));

        $mismatches = [];
        foreach ($layouts['structs'] as $struct => $layout) {
            $type = $engine->type($struct);
            if ($type->getSize() !== $layout['size']) {
                $mismatches[] = sprintf('sizeof(%s): FFI=%d C=%d', $struct, $type->getSize(), $layout['size']);
            }
            foreach ($layout['fields'] as $field => $expectedOffset) {
                $actualOffset = $type->getStructFieldOffset($field);
                if ($actualOffset !== $expectedOffset) {
                    $mismatches[] = sprintf('offsetof(%s, %s): FFI=%d C=%d', $struct, $field, $actualOffset, $expectedOffset);
                }
            }
        }
        if ($mismatches !== []) {
            throw new RuntimeException(
                "Engine structure layouts do not match the generated ground truth - aborting before memory corruption:\n"
                . implode("\n", $mismatches),
            );
        }
    }

    /**
     * Internally cast a memory at given pointer to another type
     */
    public static function cast(string $type, CData $pointer): CData
    {
        // Since PHP 8.3 FFI::cast() reinterprets the *contents* of an array
        // instead of decaying it to a pointer to its data. Restore the decay
        // semantics explicitly, otherwise every buffer cast becomes a wild
        // pointer made of the buffer's leading bytes.
        //
        // The decay deliberately avoids FFI::typeof(): probing the kind of a CData and then
        // taking another reference to it leaks the owned FFI type structure (~116 bytes per
        // call), which made every buffer cast a slow leak in long-running processes.
        try {
            // Only C arrays are countable; pointers and structs throw FFI\Exception,
            // scalar CData (eg uintptr_t) throws TypeError - both mean "not an array"
            \count($pointer);
            $pointer = FFI::addr($pointer[0]);
        } catch (FFI\Exception | \TypeError) {
            // Not an array: cast directly
        }

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
     * Returns the numeric address of a pointer for use as a stable identity key
     *
     * @param CData $pointer Pointer CData (eg zend_class_entry *)
     */
    public static function addressOf(CData $pointer): int
    {
        return (int) self::cast('uintptr_t', $pointer)->cdata;
    }

    /**
     * Materializes a typed pointer from a numeric address (the inverse of addressOf())
     *
     * The address is written through an integer view of a fresh pointer slot and the
     * typed pointer value is read back. This is the explicit form of C pointer
     * arithmetic (base address plus a signed byte offset), which FFI otherwise only
     * offers through CData operator overloads.
     *
     * @param string $type    Pointer type of the result (eg "zend_arg_info *")
     * @param int    $address Numeric address the pointer should point at
     */
    public static function pointerAtAddress(string $type, int $address): CData
    {
        $slot           = self::new("{$type}[1]");
        $addressView    = self::cast('uintptr_t *', $slot);
        $addressView[0] = $address;
        $pointer        = $slot[0];
        assert($pointer instanceof CData);

        return $pointer;
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
     * Allocates a buffer that will be stored inside an engine structure and records it in the
     * z-engine block registry
     *
     * The registry makes replacement of such buffers safe in every branch: untrackAndFree()
     * frees a block if and only if z-engine allocated it, so engine-original arrays (including
     * shared-memory data of immutable classes) are never freed through the FFI allocator.
     *
     * @param string $type Name of the type (eg "zend_class_entry *[4]")
     */
    public static function trackedNew(string $type, bool $persistent = false): CData
    {
        $memory                                                    = self::new($type, false, $persistent);
        self::$trackedBlocks[self::addressOf(self::addr($memory))] = $memory;

        return $memory;
    }

    /**
     * Checks if the given pointer refers to a block allocated by z-engine via trackedNew()
     */
    public static function isTrackedBlock(CData $pointer): bool
    {
        return isset(self::$trackedBlocks[self::addressOf($pointer)]);
    }

    /**
     * Frees the pointed block if and only if z-engine allocated it (no-op otherwise)
     *
     * Engine-original buffers are deliberately left alone: freeing memory that z-engine did
     * not allocate is exactly the wrong-allocator corruption this registry exists to prevent.
     */
    public static function untrackAndFree(CData $pointer): void
    {
        $address = self::addressOf($pointer);
        if (!isset(self::$trackedBlocks[$address])) {
            return;
        }
        // Free through the original CData so ext/ffi picks the right (persistent) allocator
        FFI::free(self::$trackedBlocks[$address]);
        unset(self::$trackedBlocks[$address]);
    }

    /**
     * Removes a block from the registry without freeing it (ownership handed to the engine)
     */
    public static function untrack(CData $pointer): void
    {
        unset(self::$trackedBlocks[self::addressOf($pointer)]);
    }

    /**
     * Releases a RAW malloc-backed pointer through libc free()
     *
     * DANGER - this is a plain free(3) with no ownership bookkeeping whatsoever. Passing a
     * pointer that was not obtained from malloc(), or freeing the same block twice, corrupts
     * the process heap. Only ever pass blocks that are provably malloc-backed:
     *
     *  - z-engine persistent FFI allocations: Core::new()/trackedNew() with $persistent=true
     *    (ext/ffi allocates those with pemalloc(size, 1), i.e. malloc);
     *  - engine structures the engine itself allocated persistently, e.g. the arData block
     *    an engine hash API call grew for a GC_PERSISTENT HashTable.
     *
     * Never pass request-lifetime memory (the Zend MM owns it), interned zend_strings (the
     * engine's interned-string table references them), or anything reachable from a live
     * engine structure.
     *
     * This is the CROSS-REQUEST companion to untrackAndFree(): that one frees through the
     * owning CData recorded in the tracked-block registry, which is a PHP static and therefore
     * dies with the request that allocated the block. Persistent data structures are dismantled
     * by a LATER request, when the registry no longer knows the block - hence the raw form.
     * The two stay orthogonal: this method does not touch the tracked-block registry, so a
     * caller that frees a block allocated in the same request must Core::untrack() it too.
     *
     * @param CData $pointer Pointer to the malloc-backed block to release
     */
    public static function persistentFree(CData $pointer): void
    {
        self::call('free', self::cast('void *', $pointer));
    }

    /**
     * Records a freshly installed hook at the top of its field chain
     *
     * @internal called by AbstractHook::install() and OpCodeHook::install()
     */
    public static function registerHook(HookInterface $hook): void
    {
        self::$installedHooks[$hook->getHookFieldKey()][] = $hook;
    }

    /**
     * Removes an uninstalled hook from the top of its field chain
     *
     * @internal called by AbstractHook::uninstall() and OpCodeHook::uninstall()
     */
    public static function unregisterHook(HookInterface $hook): void
    {
        $fieldKey = $hook->getHookFieldKey();
        if (self::isTopHook($hook)) {
            array_pop(self::$installedHooks[$fieldKey]);
            if (self::$installedHooks[$fieldKey] === []) {
                unset(self::$installedHooks[$fieldKey]);
            }
        }
    }

    /**
     * Checks if the given hook is the most recently installed one on its engine field
     */
    public static function isTopHook(HookInterface $hook): bool
    {
        $chain = self::$installedHooks[$hook->getHookFieldKey()] ?? [];

        return $chain !== [] && end($chain) === $hook;
    }

    /**
     * Returns the most recently installed hook on the given engine slot (null if none)
     *
     * @internal used by OpCode::restoreHandler() to resolve the active user opcode hook
     */
    public static function topHook(string $fieldKey): ?HookInterface
    {
        $chain = self::$installedHooks[$fieldKey] ?? [];

        return $chain === [] ? null : end($chain);
    }

    /**
     * Records a global function generated via ReflectionFunction::addFunction() so shutdown()
     * can unpublish it from the engine function table before ext/ffi teardown
     *
     * @internal called by ReflectionFunction::addFunction()
     */
    public static function registerGeneratedFunction(string $functionName): void
    {
        self::$generatedFunctions[strtolower($functionName)] = true;
    }

    /**
     * Checks if Core::shutdown() has already run for this request
     */
    public static function isShutdown(): bool
    {
        return self::$isShutdown;
    }

    /**
     * Restores every hooked engine pointer and forgets all z-engine registries (idempotent)
     *
     * Registered automatically from Core::init() via register_shutdown_function: user shutdown
     * functions run before object destructors and before ext/ffi frees callback trampolines,
     * which makes this the last safe moment to write into engine structures. Invariant after
     * this call: no libffi trampoline pointer survives in any structure that outlives the
     * request, and z-engine performs no further engine writes.
     */
    public static function shutdown(): void
    {
        if (self::$isShutdown) {
            return;
        }

        // Unwind every chain in reverse installation order so each hook restores its predecessor
        foreach (array_reverse(self::$installedHooks) as $chain) {
            for ($index = count($chain) - 1; $index >= 0; $index--) {
                $chain[$index]->uninstall();
            }
        }
        self::$installedHooks = [];

        // Unpublish generated global functions while writing the engine is still safe. Their
        // buckets point at a zend_function embedded in an immortalized closure object, so the
        // table destructor (zend_function_dtor) must NOT run over them - delete each entry with
        // the destructor disabled, exactly like ReflectionMethod::fromHookCData() does.
        if (self::$generatedFunctions !== [] && isset(self::$executor)) {
            $functionTable = self::$executor->functionTable;
            $rawTable      = $functionTable->getRawValue();
            $previousDtor  = $rawTable->pDestructor;

            $rawTable->pDestructor = null;
            foreach (array_keys(self::$generatedFunctions) as $functionName) {
                if ($functionTable->find($functionName) !== null) {
                    $functionTable->delete($functionName);
                }
            }
            $rawTable->pDestructor = $previousDtor;
        }
        self::$generatedFunctions = [];

        // Tracked buffers referenced from engine structures stay allocated: the engine frees
        // request-lifetime ones when their owning structures die, persistent ones are process
        // lifetime by design. Dropping the registry only releases the bookkeeping.
        self::$trackedBlocks = [];

        self::$isShutdown = true;
    }

    /**
     * Rewrites the trampolines of all installed hooks (escape hatch for SAPIs that cycle FFI
     * callback state between handled requests, eg FrankenPHP worker mode)
     *
     * Note: for fields with several stacked hooks only the top trampoline is reachable by the
     * engine; intermediate proceed() chains keep pointing at the trampolines captured at
     * install time, so cycle-safe setups should prefer one hook per engine field.
     */
    public static function reinstallHooks(): void
    {
        foreach (self::$installedHooks as $chain) {
            foreach ($chain as $hook) {
                $hook->refreshTrampoline();
            }
        }
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
     * Invokes the native (grand)parent constructor on an object that was
     * created without a constructor call.
     *
     * Replaces the ['obj', 'parent::__construct'] callable form, which is
     * deprecated since PHP 8.4. The parent is resolved relative to $scope (the
     * z-engine reflection class), so the native Reflection* constructor is
     * always the one invoked.
     *
     * @param object $object Instance to initialize
     * @param string $scope  z-engine class whose parent constructor to call (pass static::class)
     * @param mixed  ...$arguments Constructor arguments
     */
    public static function callParentConstructor(object $object, string $scope, ...$arguments): void
    {
        $parentClass = get_parent_class($scope);
        if ($parentClass === false) {
            throw new \LogicException("Class {$scope} has no parent constructor to call");
        }
        (new \ReflectionMethod($parentClass, '__construct'))->invokeArgs($object, $arguments);
    }

    /**
     * Returns an aligned size
     *
     * @see ZEND_MM_ALIGNED_SIZE(size) macro implementation
     */
    public static function getAlignedSize(int $size): int
    {
        $mask = ~ (self::MM_ALIGNMENT - 1);
        $size = (($size + self::MM_ALIGNMENT - 1) & $mask);

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
     * Returns the value of an engine C constant (macro, enum member or opcode)
     * extracted by the generator for the currently running PHP version.
     *
     * @param string $name Constant name as spelled in the engine sources, e.g. 'ZEND_MODULE_API_NO'
     */
    public static function engineConstant(string $name): int
    {
        self::$engineConstants ??= require self::resolveArtifact('constants.php');
        if (!array_key_exists($name, self::$engineConstants)) {
            throw new \InvalidArgumentException(
                "Unknown engine constant {$name}: it is not exported by tools/generator/symbols.php",
            );
        }

        return self::$engineConstants[$name];
    }

    /**
     * Installs a hook for the `zend_ast_process` engine global callback
     *
     * @param Closure $handler function(NodeInterface $node): void callback
     */
    public static function setASTProcessHandler(Closure $handler): AstProcessHook
    {
        $hook = new AstProcessHook($handler, self::$engine);
        $hook->install();

        return $hook;
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
