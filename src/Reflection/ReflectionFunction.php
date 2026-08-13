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

namespace ZEngine\Reflection;

use Closure;
use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ReflectionFunction as NativeReflectionFunction;
use ZEngine\Core;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\StringEntry;

class ReflectionFunction extends NativeReflectionFunction implements FunctionLikeInterface
{
    use FunctionLikeTrait;

    public function __construct(string $functionName)
    {
        parent::__construct($functionName);

        $normalizedName     = strtolower($functionName);
        $functionEntryValue = Core::$executor->functionTable->find($normalizedName);
        if ($functionEntryValue === null) {
            throw new \ReflectionException("Function {$functionName} should be in the engine.");
        }
        $this->pointer = $functionEntryValue->getRawFunction();
    }

    /**
     * Creates a reflection from the zend_function structure
     *
     * @param CData|zend_function|zend_internal_function $functionEntry Pointer to the structure
     *
     * @return ReflectionFunction
     */
    public static function fromCData(object $functionEntry): ReflectionFunction
    {
        /** @var ReflectionFunction $reflectionFunction */
        $reflectionFunction = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_function|zend_internal_function $entry Narrowed to the stub views at the owning boundary */
        $entry = $functionEntry;
        if ($entry->type === Core::ZEND_INTERNAL_FUNCTION) {
            /** @var zend_internal_function $internalEntry */
            $internalEntry   = $entry;
            $functionNamePtr = $internalEntry->function_name;
        } else {
            /** @var zend_function $userEntry */
            $userEntry       = $entry;
            $functionNamePtr = $userEntry->common->function_name;
        }
        if ($functionNamePtr !== null) {
            $functionName = StringEntry::fromCData($functionNamePtr);
            try {
                Core::callParentConstructor(
                    $reflectionFunction,
                    static::class,
                    $functionName->getStringValue(),
                );
            } catch (\ReflectionException $e) {
                // Closure-backed functions are not registered in the function table, so the
                // native reflection state stays uninitialized. The pointer-level API still works.
            }
        }
        $reflectionFunction->pointer = $entry;

        return $reflectionFunction;
    }

    /**
     * Generates a brand-new global function from a closure and registers it in the engine
     *
     * The generated function becomes an ordinary op_array-backed entry in the engine function
     * table: after this call function_exists($name) is true and calling it dispatches through
     * the normal Zend VM with NO per-call FFI trampoline (unlike a Closure installed into an
     * engine handler field). This is the free-function analogue of ReflectionClass::addMethod().
     *
     * The closure body backing the function is immortalized (its object refcount is bumped)
     * because the published zend_function lives inside the closure object and must outlive the
     * table entry - the same immortal-by-design allocation ReflectionClass::addMethod() makes
     * (see docs/memory-model.md). The entry is unpublished from the table at Core::shutdown()
     * before ext/ffi teardown, so the engine never walks a dangling entry at request end.
     *
     * @param string  $functionName Name to register the function under
     * @param Closure $body         Closure whose compiled op_array becomes the function body
     * @internal
     */
    public static function addFunction(string $functionName, Closure $body): ReflectionFunction
    {
        if (Core::$executor->functionTable->find(strtolower($functionName)) !== null) {
            throw new \ReflectionException("Function {$functionName} already exists in the engine");
        }

        $closureEntry = new ClosureEntry($body);
        // Keep the closure body alive for the rest of the request: the published zend_function
        // is embedded in this closure object (immortal-by-design, see docs/memory-model.md)
        $closureEntry->getClosureObjectEntry()->incrementReferenceCount();

        // Rename the embedded entry and strip its closure identity through the low-level API
        // so it looks like a plain global function (no scope, no ZEND_ACC_CLOSURE flag)
        self::fromClosureEntry($closureEntry, $functionName);

        // Publish into the engine function table and re-wrap the pointer the table now owns
        $storedFunction = Core::$executor->functionTable->addFunctionEntry(
            $functionName,
            $closureEntry->getRawFunction(),
        );

        // Record the entry so Core::shutdown() can unpublish it while writing is still safe
        Core::registerGeneratedFunction($functionName);

        return self::fromCData($storedFunction);
    }

    /**
     * Rebinds the zend_function embedded in a closure as a free (global) function
     *
     * All function surgery goes through the wrapper API: the entry is renamed, its class scope
     * is cleared and its ZEND_ACC_CLOSURE flag stripped. The caller stays responsible for the
     * closure lifetime (the embedded zend_function lives inside the closure object) and for
     * publishing the function in the engine function table.
     *
     * Note: the native reflection state of the returned wrapper is not initialized (the
     * function is not registered yet), so only the low-level API is usable on it. Re-wrap the
     * function after publishing to get a fully functional reflection, like addFunction() does.
     *
     * @internal
     */
    public static function fromClosureEntry(ClosureEntry $closureEntry, string $functionName): ReflectionFunction
    {
        /** @var ReflectionFunction $reflectionFunction */
        $reflectionFunction          = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionFunction->pointer = Core::cast(zend_function::class, Core::addr($closureEntry->getRawFunction()));

        $reflectionFunction->setFunctionName($functionName);
        // A free function has no class scope; clear whatever scope the closure captured
        $reflectionFunction->getCommonPointer()->scope = null;
        $reflectionFunction->setClosureFlag(false);

        return $reflectionFunction;
    }

    /**
     * Returns the class scope this function entry is bound to as a framework wrapper,
     * or null for plain functions, closures without a bound scope and main-scope
     * pseudo entries
     *
     * Overrides the native accessor so every entry kind is served from the pointer
     * this wrapper already owns: internal entries carry the scope in their own
     * structure, user entries in the shared common prefix. All work around the C
     * structure stays isolated here - callers receive the ReflectionClass wrapper,
     * never a raw scope pointer.
     */
    public function getClosureScopeClass(): ?ReflectionClass
    {
        /** @var zend_function|zend_internal_function $entry Narrowed to the stub views at the owning boundary */
        $entry = $this->pointer;
        if ($entry->type === Core::ZEND_INTERNAL_FUNCTION) {
            /** @var zend_internal_function $internalEntry */
            $internalEntry = $entry;
            $scope         = $internalEntry->scope;
        } else {
            /** @var zend_function $userEntry */
            $userEntry = $entry;
            $scope     = $userEntry->common->scope;
        }
        if ($scope === null) {
            return null;
        }

        return ReflectionClass::fromCData($scope);
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->getName(),
        ];
    }

    /**
     * Returns the hash key for function or method
     */
    protected function getHash(): string
    {
        return $this->name;
    }
}
