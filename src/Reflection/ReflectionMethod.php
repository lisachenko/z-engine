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

use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ReflectionMethod as NativeReflectionMethod;
use ZEngine\Core;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_op;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

class ReflectionMethod extends NativeReflectionMethod implements FunctionLikeInterface
{
    use AccessFlagsTrait;
    use FunctionLikeTrait;

    /**
     * Keeps the anonymous publication-board instance (and thus its class entry) alive
     * for the whole process
     */
    private static ?object $publicationBoard = null;

    public function __construct(string $className, string $methodName)
    {
        parent::__construct($className, $methodName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry  = $classEntryValue->getRawClass();
        $methodTable = HashTable::fromCData(Core::addr($classEntry->function_table));

        $methodEntryValue = $methodTable->find(strtolower($methodName));
        if ($methodEntryValue === null) {
            throw new \ReflectionException("Method {$methodName} was not found in the class.");
        }
        $this->pointer = $methodEntryValue->getRawFunction();
    }

    /**
     * Creates a reflection from the zend_function/zend_internal_function structure
     *
     * Engine-managed __call trampolines (ZEND_ACC_CALL_VIA_TRAMPOLINE) are not registered
     * in any method table, so the native reflection state cannot be initialized for them:
     * the returned wrapper is low-level only (like fromClosureEntry() results) and is meant
     * to round-trip through the hook APIs, not to be introspected natively.
     *
     * @param CData|zend_function|zend_internal_function $functionEntry Pointer to the structure
     *
     * @return ReflectionMethod
     */
    public static function fromCData(object $functionEntry): ReflectionMethod
    {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_function|zend_internal_function $entry Narrowed to the stub views at the owning boundary */
        $entry        = $functionEntry;
        $isTrampoline = false;
        if ($entry->type !== Core::ZEND_INTERNAL_FUNCTION) {
            /** @var zend_function $userEntry */
            $userEntry       = $entry;
            $commonPointer   = $userEntry->common;
            $functionNamePtr = $commonPointer->function_name;
            $scope           = $commonPointer->scope;
            $isTrampoline    = ($commonPointer->fn_flags & Core::ZEND_ACC_CALL_VIA_TRAMPOLINE) !== 0;
        } else {
            /** @var zend_internal_function $internalEntry */
            $internalEntry   = $entry;
            $functionNamePtr = $internalEntry->function_name;
            $scope           = $internalEntry->scope;
        }

        if (!$isTrampoline) {
            // Engine invariant: a non-trampoline method always carries its name and scope
            assert($scope !== null && $functionNamePtr !== null);
            $scopeNamePtr = $scope->name;
            assert($scopeNamePtr !== null);
            Core::callParentConstructor(
                $reflectionMethod,
                static::class,
                StringEntry::fromCData($scopeNamePtr)->getStringValue(),
                StringEntry::fromCData($functionNamePtr)->getStringValue(),
            );
        }
        $reflectionMethod->pointer = $entry;

        return $reflectionMethod;
    }

    /**
     * Creates a reflection for a property hook function (PHP 8.4+)
     *
     * Property hook bodies are real zend_function entries, but the engine does not publish
     * them in the class function table - they are only reachable via zend_property_info.hooks.
     * The native ReflectionMethod constructor resolves methods through a function table, so
     * the hook is published under its mangled name ("$prop::get"/"$prop::set") just for the
     * duration of the native construction and then unpublished again. The publication target
     * is a process-local board class, never the declaring class itself - an opcache-immutable
     * class keeps its function table in shared memory, where a transient insert corrupts the
     * table for every process. The transient entry is removed with the table destructor
     * disabled, so the hook function itself is untouched.
     *
     * @param CData $functionEntry Pointer to the hook zend_function structure
     */
    public static function fromHookCData(object $functionEntry): ReflectionMethod
    {
        if ($functionEntry->type === Core::ZEND_INTERNAL_FUNCTION) {
            $functionEntry = Core::cast('zend_internal_function *', $functionEntry);
            $commonPointer = $functionEntry;
        } else {
            $commonPointer = $functionEntry->common;
        }
        assert($commonPointer instanceof CData);
        $functionNamePtr = $commonPointer->function_name;
        assert($functionNamePtr instanceof CData);
        $lowerName = strtolower(StringEntry::fromCData($functionNamePtr)->getStringValue());

        $scope = $commonPointer->scope;
        assert($scope instanceof CData);
        $methodTable = HashTable::fromCData(Core::addr($scope->function_table));
        if ($methodTable->find($lowerName) !== null) {
            // Already published (eg by a future engine version) - use the regular path
            return static::fromCData($functionEntry);
        }

        // The declaring class's own method table is NOT a valid publication target: with
        // opcache the class may be immutable, its function table living in shared memory
        // with an exactly-sized bucket array - inserting forces a resize that reallocates
        // the shared arData with the request allocator, corrupting the table for every
        // process that maps it. The transient entry goes into a process-local publication
        // board instead: the native constructor resolves the function through the board
        // but adopts the hook's own scope, so the reflection still reports the real
        // declaring class, and no shared engine structure is ever written.
        $boardName   = get_class(self::publicationBoard());
        $methodTable = (new ReflectionClass($boardName))->getMethodTable();

        // The temporary container is released right after the engine copied it into a bucket
        $rawFunction = Core::cast('zend_function *', $functionEntry)[0];
        assert($rawFunction instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $methodTable->add($lowerName, $valueEntry);
        $valueEntry->release();
        try {
            /** @var ReflectionMethod $reflectionMethod */
            $reflectionMethod = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
            Core::callParentConstructor(
                $reflectionMethod,
                static::class,
                $boardName,
                StringEntry::fromCData($functionNamePtr)->getStringValue(),
            );
            /** @var zend_function $functionEntry Narrowed to the stub view at the owning boundary */
            $reflectionMethod->pointer = $functionEntry;

            return $reflectionMethod;
        } finally {
            // Unpublish the transient entry without destroying the hook function: the table
            // destructor (zend_function_dtor) is disabled around the delete, so the bucket
            // removal releases nothing - the hook stays owned by zend_property_info.hooks
            $methodTable->deleteWithoutDestructor($lowerName);
        }
    }

    /**
     * Process-local class whose method table hosts transient hook publications
     *
     * An anonymous class is created at runtime and is therefore never persisted by
     * opcache: unlike a file-declared class it can not become IMMUTABLE with its
     * function table in shared memory, which is what makes it a safe mutation target.
     * The instance is cached so the class entry stays alive for the whole process.
     */
    private static function publicationBoard(): object
    {
        self::$publicationBoard ??= new class {};

        return self::$publicationBoard;
    }

    /**
     * Binds the zend_function embedded into a closure to the given class as a method
     *
     * All function surgery goes through the wrapper API: the entry is renamed, attached to
     * the class scope and stripped of its ZEND_ACC_CLOSURE flag. The caller stays responsible
     * for the closure lifetime (the embedded zend_function lives inside the closure object)
     * and for publishing the function in the class method table.
     *
     * Note: the native reflection state of the returned wrapper is not initialized (the
     * method is not registered in the class yet), so only the low-level API is usable on it.
     * Re-wrap the function after publishing to get a fully functional reflection, like
     * ReflectionClass::addMethod() does.
     *
     * @internal
     */
    /**
     * Creates a low-level reflection over a raw zend_function structure without
     * initializing the native reflection state
     *
     * Unlike fromCData() this never resolves the method through its class, so it works
     * for methods that are not published under their declaring class's live name (eg
     * hot-swap donor entries residing only as structures in memory, including methods
     * the donor adds that do not exist on the live class yet). Only the pointer-level
     * API (equals()/getCommonPointer()/getOpArrayPointer()/getDeclaringClass()/
     * isUserDefined()/isRemovable()) is usable, native introspection is not.
     *
     * @internal used by the hot-swap machinery (ClassDelta)
     */
    /**
     * @param CData|zend_function|zend_internal_function $functionEntry
     */
    public static function fromRawEntry(object $functionEntry): ReflectionMethod
    {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_function|zend_internal_function $functionEntry Narrowed to the stub views at the owning boundary */
        $reflectionMethod->pointer = $functionEntry;

        return $reflectionMethod;
    }

    public static function fromClosureEntry(
        ClosureEntry $closureEntry,
        string $className,
        string $methodName,
    ): ReflectionMethod {
        /** @var ReflectionMethod $reflectionMethod */
        $reflectionMethod          = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionMethod->pointer = Core::cast(zend_function::class, Core::addr($closureEntry->getRawFunction()));

        $reflectionMethod->setFunctionName($methodName);
        $reflectionMethod->setDeclaringClass($className);
        $reflectionMethod->setClosureFlag(false);

        return $reflectionMethod;
    }

    /**
     * Declares function as final/non-final
     */
    public function setFinal(bool $isFinal = true): void
    {
        $this->setAccessFlag(Core::ZEND_ACC_FINAL, $isFinal);
    }

    /**
     * Declares function as abstract/non-abstract
     */
    public function setAbstract(bool $isAbstract = true): void
    {
        $this->setAccessFlag(Core::ZEND_ACC_ABSTRACT, $isAbstract);
    }

    /**
     * Declares method as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        $this->setAccessFlag(Core::ZEND_ACC_STATIC, $isStatic);
    }

    /**
     * A method keeps its access flags in the fn_flags field of the common function structure
     *
     * @see AccessFlagsTrait for setPublic()/setProtected()/setPrivate()
     */
    protected function replaceAccessFlags(int $clearMask, int $setMask): void
    {
        $this->replaceFunctionFlags($clearMask, $setMask);
    }

    /**
     * Gets the declaring class
     *
     * @throws \InvalidArgumentException If scope is not available
     */
    #[\Override]
    public function getDeclaringClass(): ReflectionClass
    {
        $scope = $this->getCommonPointer()->scope;
        if ($scope === null) {
            throw new \InvalidArgumentException('Not in a class scope');
        }

        return ReflectionClass::fromCData($scope);
    }

    /**
     * Changes the declaring class name for this method
     *
     * @param string $className New class name for this method
     * @internal
     */
    public function setDeclaringClass(string $className): void
    {
        $lcName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($lcName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} was not found");
        }
        $this->getCommonPointer()->scope = $classEntryValue->getRawClass();
    }

    /**
     * Returns the method prototype or null if no prototype for this method
     */
    #[\ReturnTypeWillChange]
    #[\Override]
    public function getPrototype(): ?ReflectionMethod
    {
        $prototype = $this->getCommonPointer()->prototype;
        if ($prototype === null) {
            return null;
        }

        return static::fromCData($prototype);
    }

    /**
     * Structurally compares the compiled body of this method with another one
     *
     * This is NOT full identity - it is the conservative check the hot-swap delta needs
     * to decide whether a method body changed: the same pointer is trivially equal, and
     * otherwise the op_arrays must agree on their body metrics (opcode/var/literal
     * counts, temporaries, argument counts and flags), their opcode bytes and every
     * literal value (ReflectionValue::equals()). Anything not provably identical counts
     * as different, so a missed change never keeps stale code running.
     */
    public function equals(ReflectionMethod $other): bool
    {
        if ($this->getAddress() === $other->getAddress()) {
            return true;
        }
        $thisOpArray  = $this->getOpArrayPointer();
        $otherOpArray = $other->getOpArrayPointer();

        foreach (['last', 'last_var', 'last_literal', 'T', 'num_args', 'required_num_args', 'fn_flags'] as $field) {
            if ($thisOpArray->{$field} !== $otherOpArray->{$field}) {
                return false;
            }
        }
        $opcodesSize = $thisOpArray->last * Core::sizeOfType(zend_op::class);
        if ($opcodesSize > 0) {
            $thisOpcodes  = $thisOpArray->opcodes;
            $otherOpcodes = $otherOpArray->opcodes;
            // A non-zero opcode count guarantees both opcode tables are present
            assert($thisOpcodes !== null && $otherOpcodes !== null);
            if (\FFI::memcmp(Core::cast('zend_op *', $thisOpcodes), Core::cast('zend_op *', $otherOpcodes), $opcodesSize) !== 0) {
                return false;
            }
        }
        $totalLiterals = $thisOpArray->last_literal;
        assert(is_int($totalLiterals) && $totalLiterals >= 0);
        if ($totalLiterals > 0) {
            // A non-zero literal count guarantees both literal tables are present
            $thisLiterals  = $thisOpArray->literals;
            $otherLiterals = $otherOpArray->literals;
            assert($thisLiterals instanceof CData && $otherLiterals instanceof CData);
            $thisView  = new StructArray($thisLiterals, $totalLiterals);
            $otherView = new StructArray($otherLiterals, $totalLiterals);
            for ($index = 0; $index < $totalLiterals; $index++) {
                $thisLiteral  = ReflectionValue::fromValueEntry($thisView[$index]);
                $otherLiteral = ReflectionValue::fromValueEntry($otherView[$index]);
                if (!$thisLiteral->equals($otherLiteral)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Checks if this method may be removed from its declaring class at runtime
     *
     * Only user-defined methods that the class entry does not reference through a magic
     * shortcut field (constructor/destructor/magic methods) may be removed - dropping
     * such a slot would require field surgery the hot-swap delta does not perform.
     *
     * @internal used by the hot-swap machinery (ClassDelta)
     */
    public function isRemovable(): bool
    {
        if (!$this->isUserDefined()) {
            return false;
        }

        return $this->getDeclaringClass()->getMagicSlotFor($this) === null;
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'  => $this->getName(),
            'class' => $this->getDeclaringClass()->getName(),
        ];
    }

    /**
     * Returns the hash key for function or method
     */
    protected function getHash(): string
    {
        return $this->class . '::' . $this->name;
    }
}
