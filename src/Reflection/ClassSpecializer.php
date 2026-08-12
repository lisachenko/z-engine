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
use ZEngine\Core;
use ZEngine\System\OpCode;
use ZEngine\Type\HashTable;
use ZEngine\Type\OpLine;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * Runtime class-entry specialization: deep-clones a linked userland zend_class_entry
 * under a new name, applies a type-substitution pass over the copy and registers the
 * result in CG(class_table) as a first-class, instantiable class.
 *
 * Copy model (see docs/class-specialization.md for the full contract):
 *
 *  - The zend_class_entry and every structure the engine tears down per class
 *    (default property/static tables, own zend_property_info / zend_class_constant
 *    entries, the interface array) are materialized into request memory with engine
 *    assignment semantics (owned references on every stored name/value), so the
 *    standard destroy_zend_class() path dismantles the copy like any other user class.
 *  - Methods are duplicated at the zend_op_array level (trait-clone style): each copy
 *    gets its own zend_op_array struct with scope pointing at the new class entry and
 *    a reset run_time_cache / static_variables_ptr, while the compiled body (opcodes,
 *    literals, vars) stays SHARED with the source through the op_array refcount.
 *  - Structures the engine never frees for userland classes (the class entry itself,
 *    property-info blocks, class-constant blocks, properties_info_table, duplicated
 *    arg_info blocks) are allocated as plain request memory that mimics the compiler
 *    arena: the request allocator reclaims them at request end.
 *  - Type substitution rewrites zend_type in copied zend_property_info and duplicated
 *    arg_info entries; substituting inside union/intersection lists and every other
 *    unsupported case fails with ClassSpecializationException BEFORE any engine state
 *    is touched.
 */
class ClassSpecializer
{
    /**
     * zend_class_entry function pointers that must follow the copied method table
     */
    private const FUNCTION_POINTER_FIELDS = [
        'constructor',
        'destructor',
        'clone',
        '__get',
        '__set',
        '__unset',
        '__isset',
        '__call',
        '__callstatic',
        '__tostring',
        '__debugInfo',
        '__serialize',
        '__unserialize',
    ];

    /**
     * ce_flags that describe the storage location of the source class (shared memory,
     * opcache file cache, preload region) and must never travel onto a runtime copy
     * that lives in plain request memory
     */
    /**
     * The three operand slots of a zend_op, paired with the field naming their node type
     *
     * @var array<string, string>
     */
    private const CONSTANT_OPERAND_FIELDS = [
        'op1_type'    => 'op1',
        'op2_type'    => 'op2',
        'result_type' => 'result',
    ];

    private const STORAGE_CE_FLAGS = ['ZEND_ACC_IMMUTABLE', 'ZEND_ACC_CACHED', 'ZEND_ACC_FILE_CACHED', 'ZEND_ACC_PRELOADED'];

    /**
     * Deep-clones the given userland class under a new runtime name
     *
     * @param string                 $sourceClassName Existing (linked) userland class to clone
     * @param string                 $newClassName    Fully-qualified name of the specialized copy
     * @param TypeSubstitutionMap|null $substitutions Placeholder-to-type substitutions applied to the copy
     *
     * @return ReflectionClass Reflection of the freshly registered specialized class
     */
    public function specialize(
        string $sourceClassName,
        string $newClassName,
        ?TypeSubstitutionMap $substitutions = null,
        ?SlotSubstitutionMap $slotSubstitutions = null,
    ): ReflectionClass {
        $substitutions     ??= new TypeSubstitutionMap([]);
        $slotSubstitutions ??= new SlotSubstitutionMap([]);

        // Give the autoloader a chance to bring the source declaration into the engine
        // (the raw class-table lookup below never triggers autoloading by itself)
        class_exists($sourceClassName);

        $sourceEntry = $this->findClassEntry($sourceClassName);
        if ($sourceEntry === null) {
            throw new ClassSpecializationException("Class {$sourceClassName} was not found in the engine");
        }
        $this->assertSupportedSource($sourceEntry, $sourceClassName, $newClassName);
        $this->assertSubstitutionsApplicable($sourceEntry, $sourceClassName, $substitutions);
        $this->assertSlotSubstitutionsApplicable($sourceClassName, $slotSubstitutions);

        $newEntry = $this->copyClassEntry($sourceEntry, $newClassName, $substitutions, $slotSubstitutions);

        // Publish the finished copy: the engine copies the temporary IS_PTR container
        // into its own bucket, the class entry itself is referenced, not copied
        $entryView = $newEntry[0];
        assert($entryView instanceof CData);
        $classValue = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $entryView);
        Core::$executor->classTable->add(strtolower($newClassName), $classValue);
        $classValue->release();

        return ReflectionClass::fromCData($newEntry);
    }

    /**
     * Removes a runtime class from the engine class table, destroying its class entry NOW
     *
     * The counterpart of specialize(): deleting the class-table bucket runs the engine's own
     * destroy_zend_class() over the entry immediately - tables, own property infos and
     * constants, owned names - instead of at request shutdown, while everything the class
     * shares with its source (method bodies through the op_array refcount) stays alive.
     * Destroying a specialization while its template is still in use is exactly the moment
     * the memory-ownership contract of the copy model is testable; see
     * docs/class-specialization.md.
     *
     * Only classes the engine tears down through the request allocator are evictable. An
     * internal class and an opcache-shared (ZEND_ACC_IMMUTABLE) or preloaded entry live in
     * memory this process must never dismantle, so they are refused - eviction is for
     * runtime-registered copies, which are always plain userland classes.
     *
     * @param string $className Name of the registered class to destroy
     *
     * @return bool false when no class of that name is registered, true after eviction
     *
     * @throws ClassSpecializationException When the registered class is not evictable
     */
    public function evict(string $className): bool
    {
        $lowerName  = strtolower($className);
        $classEntry = $this->findClassEntry($className);
        if ($classEntry === null) {
            return false;
        }

        $registeredClass = ReflectionClass::fromCData($classEntry);
        if (!$registeredClass->isUserDefined()) {
            throw new ClassSpecializationException(
                "Cannot evict internal class {$className}: only userland classes are supported",
            );
        }
        if ($registeredClass->isImmutable() || $registeredClass->isPreloaded()) {
            throw new ClassSpecializationException(
                "Cannot evict {$className}: its class entry lives in shared memory, which this "
                . 'process must never dismantle',
            );
        }

        Core::$executor->classTable->delete($lowerName);

        return true;
    }

    /**
     * Copies an opcache-shared (ZEND_ACC_IMMUTABLE) class entry out of shared memory into a
     * writable per-process copy published under the SAME name
     *
     * Shared memory is visible to every worker process of the pool, so the class entry it
     * holds can never be written in place. The copy-out gives this process its own writable
     * class entry - built by the very same copy machinery specialize() uses, so the copy is
     * an ordinary userland class the engine dismantles at request end - and repoints the
     * per-process class-table bucket at it. The shared-memory original is left completely
     * untouched (never written, never freed) and simply stops being published in this
     * process; opcache republishes it into a fresh class table on the next request.
     *
     * Calling this for a class that is already writable is a no-op that returns the
     * published entry, so every mutation entry point can call it unconditionally.
     *
     * @param string $className Name of the loaded class to copy out
     *
     * @throws ClassSpecializationException When the class is not loaded or its shape is not
     *                                      supported by the copy machinery
     *
     * @internal used by the mutation APIs of ReflectionClass/ReflectionMethod and by HotSwap
     */
    public function copyOutOfSharedMemory(string $className): ReflectionClass
    {
        // Give the autoloader a chance to bring the declaration into the engine
        class_exists($className);

        $classValue = Core::$executor->classTable->find(strtolower($className));
        if ($classValue === null) {
            throw new ClassSpecializationException("Class {$className} was not found in the engine");
        }
        $publishedEntry = $classValue->getRawClass();
        $publishedFlags = $publishedEntry->ce_flags;
        assert(is_int($publishedFlags));

        // Either never shared, or already copied out by an earlier mutation
        if (($publishedFlags & Core::ZEND_ACC_IMMUTABLE) === 0) {
            return ReflectionClass::fromCData($publishedEntry);
        }
        if (($publishedFlags & Core::engineConstant('ZEND_ACC_PRELOADED')) !== 0) {
            throw new ClassSpecializationException(
                "Cannot copy the preloaded class {$className} out of shared memory: its class-table "
                . 'bucket is reused by every request of the worker process, while the copy lives in '
                . 'request memory and dies with the request',
            );
        }
        $this->assertCopyableSource($publishedEntry, $className);

        // The copy keeps the original name STRING (same table key, same case, same
        // pointer): it is a permanent interned string carrying the engine's fast
        // class-name cache slot, and releasing an interned string is a no-op, so the copy
        // can share it without any accounting
        $publishedName = $publishedEntry->name;
        assert($publishedName instanceof CData);
        $nameEntry = StringEntry::fromCData($publishedName);
        $newEntry  = $this->copyClassEntry(
            $publishedEntry,
            $nameEntry->getStringValue(),
            new TypeSubstitutionMap([]),
            new SlotSubstitutionMap([]),
            $publishedName,
        );

        // Repoint the per-process class-table bucket at the writable copy...
        $classValue->setPointer($newEntry);
        // ...and the engine's fast class-name cache with it: every class lookup consults
        // that memoized slot BEFORE the class table, so call sites compiled into
        // opcache-cached scripts would otherwise keep resolving the shared-memory entry
        if ($nameEntry->hasClassEntryCache()) {
            $nameEntry->setCachedClassEntry($newEntry);
        }

        return ReflectionClass::fromCData($newEntry);
    }

    /**
     * Looks up a zend_class_entry pointer by class name (null when not registered)
     *
     * @return \FFI\CData|null
     */
    private function findClassEntry(string $className): ?object
    {
        $classValue = Core::$executor->classTable->find(strtolower($className));

        return $classValue?->getRawClass();
    }

    /**
     * Support matrix: every unsupported source fails here, before any allocation
     *
     * @param \FFI\CData $sourceEntry
     */
    private function assertSupportedSource(object $sourceEntry, string $sourceClassName, string $newClassName): void
    {
        if ($newClassName === '') {
            throw new ClassSpecializationException('Specialized class name can not be empty');
        }
        if ($this->findClassEntry($newClassName) !== null) {
            throw new ClassSpecializationException("Class {$newClassName} already exists in the engine");
        }

        $this->assertCopyableSource($sourceEntry, $sourceClassName);
    }

    /**
     * Support matrix of the class-entry copy itself: every shape the copy machinery has no
     * defined semantics for fails here, before any allocation
     *
     * Shared by specialize() (copy under a new name) and by copyOutOfSharedMemory() (copy
     * under the original name), which is why it knows nothing about the target name.
     *
     * @param \FFI\CData $sourceEntry
     */
    private function assertCopyableSource(object $sourceEntry, string $sourceClassName): void
    {
        $sourceKind = $sourceEntry->type;
        assert(is_string($sourceKind));
        if (ord($sourceKind) !== Core::ZEND_USER_CLASS) {
            throw new ClassSpecializationException(
                "Cannot copy internal class {$sourceClassName}: only userland classes are supported",
            );
        }
        $classFlags = $sourceEntry->ce_flags;
        assert(is_int($classFlags));
        $unsupportedKind = match (true) {
            ($classFlags & Core::ZEND_ACC_INTERFACE) !== 0 => 'an interface',
            ($classFlags & Core::ZEND_ACC_TRAIT)     !== 0 => 'a trait',
            ($classFlags & Core::ZEND_ACC_ENUM)      !== 0 => 'an enum',
            default                                        => null,
        };
        if ($unsupportedKind !== null) {
            throw new ClassSpecializationException(
                "Cannot copy {$sourceClassName}: it is {$unsupportedKind}, only plain classes are supported",
            );
        }
        if (($classFlags & Core::ZEND_ACC_LINKED) === 0) {
            throw new ClassSpecializationException(
                "Cannot copy {$sourceClassName}: the class is not linked yet",
            );
        }
        $hookedProperties = $sourceEntry->num_hooked_props;
        assert(is_int($hookedProperties));
        if ($hookedProperties > 0) {
            throw new ClassSpecializationException(
                "Cannot copy {$sourceClassName}: classes with property hooks are not supported",
            );
        }
        // An internal ancestor grafts internal function copies and a custom create_object
        // handler into the class - materializing those safely is out of scope
        for ($parent = $sourceEntry->parent; $parent !== null; $parent = $parent->parent) {
            assert($parent instanceof CData);
            $parentKind = $parent->type;
            assert(is_string($parentKind));
            if (ord($parentKind) !== Core::ZEND_USER_CLASS) {
                $rawParentName = $parent->name;
                assert($rawParentName instanceof CData);
                $parentName = StringEntry::fromCData($rawParentName)->getStringValue();
                throw new ClassSpecializationException(
                    "Cannot copy {$sourceClassName}: ancestor {$parentName} is an internal class",
                );
            }
        }
        foreach ($this->methodTable($sourceEntry) as $methodValue) {
            $rawFunction  = $methodValue->getRawFunction();
            $functionKind = $rawFunction->type;
            assert(is_int($functionKind));
            if ($functionKind !== Core::ZEND_USER_FUNCTION) {
                throw new ClassSpecializationException(
                    "Cannot copy {$sourceClassName}: the method table contains internal functions",
                );
            }
        }
    }

    /**
     * Refuses substitutions that would either mutate shared (inherited) declarations or
     * require rewriting inside union/intersection type lists
     *
     * @param \FFI\CData $sourceEntry
     */
    private function assertSubstitutionsApplicable(
        object $sourceEntry,
        string $sourceClassName,
        TypeSubstitutionMap $substitutions,
    ): void {
        if ($substitutions->isEmpty()) {
            return;
        }
        $sourceAddress = Core::addressOf($sourceEntry);

        foreach ($this->propertiesTable($sourceEntry) as $propertyName => $propertyValue) {
            $rawInfo        = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $declaringClass = $rawInfo->ce;
            $propertyType   = $rawInfo->type;
            assert(is_string($propertyName) && $declaringClass instanceof CData && $propertyType instanceof CData);
            $isOwn = Core::addressOf($declaringClass) === $sourceAddress;
            $this->assertTypeSubstitutable(
                $propertyType,
                $substitutions,
                $isOwn,
                "property {$sourceClassName}::\${$propertyName}",
            );
        }
        foreach ($this->methodTable($sourceEntry) as $methodName => $methodValue) {
            $rawFunction = Core::cast('zend_op_array *', $methodValue->getRawFunction());
            $scope       = $rawFunction->scope;
            assert(is_string($methodName) && $scope instanceof CData);
            $isOwn   = Core::addressOf($scope) === $sourceAddress;
            $context = "method {$sourceClassName}::{$methodName}()";
            foreach ($this->argInfoEntries($rawFunction) as $argInfo) {
                $argumentType = $argInfo->type;
                assert($argumentType instanceof CData);
                $this->assertTypeSubstitutable($argumentType, $substitutions, $isOwn, $context);
            }
        }
    }

    /**
     * Refuses slot substitutions the engine could not carry out safely
     *
     * Runs on the *source* class through native reflection rather than through raw CData: the
     * questions being asked here - does this member exist, is it declared by this class, is its
     * type a single type, does its default still fit - are exactly the ones native reflection
     * answers, and keeping them out of pointer arithmetic keeps the validation trustworthy.
     */
    private function assertSlotSubstitutionsApplicable(
        string $sourceClassName,
        SlotSubstitutionMap $slotSubstitutions,
    ): void {
        if ($slotSubstitutions->isEmpty()) {
            return;
        }
        // Guaranteed by the class-table lookup and the source checks that already ran
        assert(class_exists($sourceClassName));
        $reflection = new \ReflectionClass($sourceClassName);
        $defaults   = $reflection->getDefaultProperties();

        foreach ($slotSubstitutions->toList() as [$slot, $replacement]) {
            $context = $slot->describe($sourceClassName);

            if ($slot->kind === TypeSlotKind::Property) {
                if (!$reflection->hasProperty($slot->memberName)) {
                    throw new ClassSpecializationException("Cannot substitute {$context}: no such property");
                }
                $property = $reflection->getProperty($slot->memberName);
                $this->assertDeclaredHere($property->getDeclaringClass()->getName(), $sourceClassName, $context);
                $this->assertSlotTypeWritable($property->getType(), $context);

                // The engine verifies default values against declared types at compile time and
                // never again, so a default that no longer fits would silently produce an object
                // whose property violates its own declaration
                if (array_key_exists($slot->memberName, $defaults)
                    && !self::defaultSatisfies($replacement, $defaults[$slot->memberName])) {
                    throw new ClassSpecializationException(
                        "Cannot substitute {$context} with \"{$replacement}\": its declared default value "
                        . 'would no longer satisfy the type',
                    );
                }

                continue;
            }

            if (!$reflection->hasMethod($slot->memberName)) {
                throw new ClassSpecializationException("Cannot substitute {$context}: no such method");
            }
            $method = $reflection->getMethod($slot->memberName);
            $this->assertDeclaredHere($method->getDeclaringClass()->getName(), $sourceClassName, $context);

            if ($slot->kind === TypeSlotKind::ReturnType) {
                $returnType = $method->getReturnType();
                if ($returnType === null) {
                    // Without ZEND_ACC_HAS_RETURN_TYPE there is no arg_info entry at index -1,
                    // and adding one would change the block layout
                    throw new ClassSpecializationException(
                        "Cannot substitute {$context}: the method declares no return type",
                    );
                }
                $this->assertSlotTypeWritable($returnType, $context);
                $this->assertReturnTypeIsEnforceable(
                    Core::cast('zend_op_array *', (new ReflectionMethod($sourceClassName, $slot->memberName))->getEntryPointer()),
                    $context,
                );

                continue;
            }

            $parameters = $method->getParameters();
            $index      = $slot->parameterIndex ?? -1;
            if (!isset($parameters[$index])) {
                throw new ClassSpecializationException(
                    "Cannot substitute {$context}: the method declares only " . count($parameters) . ' parameter(s)',
                );
            }
            // A builtin parameter needs its cached ZEND_RECV mask patched as well, which
            // duplicateMethod() handles by un-sharing the opcode array; nothing to reject here.
            $this->assertSlotTypeWritable($parameters[$index]->getType(), $context);
        }
    }

    /**
     * Refuses to rewrite a signature slot whose check the engine decided at compile time
     *
     * Whether a parameter or return type is verified at run time is not read back from
     * arg_info on every call: for a builtin type the compiler picks a specialized ZEND_RECV /
     * ZEND_VERIFY_RETURN_TYPE handler that has the decision baked in, and those opcodes are
     * SHARED with the template by design. Rewriting such a slot changes what reflection
     * reports while changing nothing about what the engine enforces, so it is rejected rather
     * than performed: a specialization that silently stops checking is worse than one that
     * refuses to exist. Class-like slots go through the generic path and are rewritten.
     *
     * Properties are not affected - a property write always consults zend_property_info - so
     * a `mixed` property can be re-typed freely.
     */
    /**
     * Refuses a return-type substitution the engine would never check
     *
     * A return value is verified by a ZEND_VERIFY_RETURN_TYPE opline that reads arg_info[-1] at
     * run time, so rewriting the type is enough - *when the opline exists*. The compiler omits
     * it in two cases: a `mixed` return type (nothing to check) and a return whose expression it
     * already proved satisfies the declared type (`return 'x';` in a `string` method). In both
     * the substitution would be visible to reflection and never enforced, so it is rejected
     * rather than performed.
     *
     * @param CData $sourceOpArray zend_op_array * of the method declaring the return type
     */
    private function assertReturnTypeIsEnforceable(object $sourceOpArray, string $context): void
    {
        if (self::everyReturnIsVerified($sourceOpArray)) {
            return;
        }

        throw new ClassSpecializationException(
            "Cannot substitute {$context}: the compiler left at least one return statement "
            . 'unchecked, either because the declared type is `mixed` or because it proved that '
            . 'return already satisfies the declared type. Rewriting the type would be reported '
            . 'by reflection and never enforced on those paths.',
        );
    }

    /**
     * Whether every return path of the method runs through a return-type check
     *
     * The compiler emits ZEND_VERIFY_RETURN_TYPE immediately before the ZEND_RETURN it guards,
     * and omits it wherever the check is unnecessary - for a `mixed` declaration, or for a
     * return whose expression it already proved satisfies the type. Testing merely that the
     * method *contains* a check is not enough: even a `mixed` method carries one in its implicit
     * `return null` epilogue, so the guarded-return relation is what has to hold.
     *
     * @param \FFI\CData $opArray
     */
    private static function everyReturnIsVerified(object $opArray): bool
    {
        $total = $opArray->last;
        assert(is_int($total));

        $opcodes = $opArray->opcodes;
        assert($opcodes instanceof CData);

        for ($index = 0; $index < $total; $index++) {
            $opline = $opcodes[$index];
            assert($opline instanceof CData);
            if (!in_array($opline->opcode, [OpCode::RETURN, OpCode::RETURN_BY_REF, OpCode::GENERATOR_RETURN], true)) {
                continue;
            }
            if ($index === 0) {
                return false;
            }
            $previous = $opcodes[$index - 1];
            assert($previous instanceof CData);
            if ($previous->opcode !== OpCode::VERIFY_RETURN_TYPE) {
                return false;
            }
        }

        return true;
    }

    private function assertDeclaredHere(string $declaringClass, string $sourceClassName, string $context): void
    {
        if (strcasecmp($declaringClass, $sourceClassName) !== 0) {
            throw new ClassSpecializationException(
                "Cannot substitute inherited {$context}: the declaration is shared with {$declaringClass}",
            );
        }
    }

    /**
     * A writable slot carries exactly one type: untyped slots have no zend_type to rewrite and
     * composite slots are a zend_type list, which this API deliberately does not build
     */
    private function assertSlotTypeWritable(?\ReflectionType $type, string $context): void
    {
        if ($type === null) {
            throw new ClassSpecializationException(
                "Cannot substitute {$context}: the declaration has no type to replace",
            );
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw new ClassSpecializationException(
                "Cannot substitute the union/intersection type of {$context}: only single types are supported",
            );
        }
    }

    /**
     * Whether a declared default value would still satisfy the replacement type
     */
    private static function defaultSatisfies(string $replacement, mixed $default): bool
    {
        $nullable = str_starts_with($replacement, '?');
        $typeName = strtolower($nullable ? substr($replacement, 1) : $replacement);

        if ($default === null) {
            return $nullable || $typeName === 'null' || $typeName === 'mixed';
        }

        return match ($typeName) {
            'mixed'  => true,
            'int'    => is_int($default),
            'float'  => is_float($default) || is_int($default),
            'string' => is_string($default),
            'bool'   => is_bool($default),
            'true'   => $default === true,
            'false'  => $default === false,
            'array'  => is_array($default),
            'object' => is_object($default),
            'null'   => false,
            default  => is_object($default) && is_a($default, $typeName),
        };
    }

    /**
     * Validates one zend_type against the substitution map
     *
     * @param bool $isOwn Whether the containing declaration will be copied (own member)
     * @param \FFI\CData $type
     */
    private function assertTypeSubstitutable(
        object $type,
        TypeSubstitutionMap $substitutions,
        bool $isOwn,
        string $context,
    ): void {
        if (!$this->typeContainsPlaceholder($type, $substitutions)) {
            return;
        }
        if (!$isOwn) {
            throw new ClassSpecializationException(
                "Cannot substitute the type of inherited {$context}: it is shared with the declaring class",
            );
        }
        $typeMask = $type->type_mask;
        assert(is_int($typeMask));
        if (($typeMask & Core::engineConstant('_ZEND_TYPE_LIST_BIT')) !== 0) {
            throw new ClassSpecializationException(
                "Cannot substitute a placeholder inside the union/intersection type of {$context}: "
                . 'only simple placeholder types are supported',
            );
        }
    }

    /**
     * Checks (recursively for type lists) whether a zend_type references a placeholder name
     *
     * @param \FFI\CData $type
     */
    private function typeContainsPlaceholder(object $type, TypeSubstitutionMap $substitutions): bool
    {
        $typeMask = $type->type_mask;
        assert(is_int($typeMask));
        if (($typeMask & Core::engineConstant('_ZEND_TYPE_LIST_BIT')) !== 0) {
            foreach ($this->typeListEntries($type) as $listedType) {
                if ($this->typeContainsPlaceholder($listedType, $substitutions)) {
                    return true;
                }
            }

            return false;
        }
        if (($typeMask & Core::engineConstant('_ZEND_TYPE_NAME_BIT')) !== 0) {
            $rawName = $type->ptr;
            assert($rawName instanceof CData);
            $typeName = StringEntry::fromCData(Core::cast('zend_string *', $rawName))->getStringValue();

            return $substitutions->resolve($typeName) !== null;
        }

        return false;
    }

    /**
     * Builds the specialized zend_class_entry (fully materialized, not yet registered)
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData|null $reusedName
     * @return \FFI\CData
     */
    private function copyClassEntry(
        object $sourceEntry,
        string $newClassName,
        TypeSubstitutionMap $substitutions,
        SlotSubstitutionMap $slotSubstitutions,
        ?object $reusedName = null,
    ): object {
        // The class entry itself mimics a compiler-arena allocation: destroy_zend_class()
        // dismantles the tables but never frees the struct of a userland class, and the
        // request allocator reclaims the block at request end
        $entryStruct = Core::new('zend_class_entry', false);
        $newEntry    = Core::cast('zend_class_entry *', Core::addr($entryStruct));
        Core::memcpy($newEntry, $sourceEntry, Core::sizeof(Core::type('zend_class_entry')));

        // Identity: fresh refcount, owned name, no shared-memory storage flags
        $newEntry->refcount = 1;
        $classFlags         = $newEntry->ce_flags;
        assert(is_int($classFlags));
        foreach (self::STORAGE_CE_FLAGS as $storageFlag) {
            $classFlags &= ~Core::engineConstant($storageFlag);
        }
        $newEntry->ce_flags = $classFlags;
        // A copy published under a new name mints (and owns) its own name string; the
        // shared-memory copy-out reuses the interned name of the source instead, see
        // copyOutOfSharedMemory()
        $newEntry->name = $reusedName
            ?? StringEntry::fromString($newClassName)->transferReferenceOwnership()->getRawValue();

        // Per-class engine caches must start empty on the copy
        $newEntry->static_members_table__ptr = null;
        $newEntry->mutable_data__ptr         = null;
        $newEntry->inheritance_cache         = null;

        // The class-level attribute table and source-file metadata are shared with an
        // owned reference each (engine assignment semantics, interned/immutable-aware)
        $classAttributes = $newEntry->attributes;
        if ($classAttributes !== null) {
            assert($classAttributes instanceof CData);
            self::addHashTableReference($classAttributes);
        }
        $docComment = $newEntry->doc_comment;
        if ($docComment !== null) {
            assert($docComment instanceof CData);
            self::addStringReference($docComment);
        }
        $classInfo = $newEntry->info;
        assert($classInfo instanceof CData);
        $userInfo = $classInfo->user;
        assert($userInfo instanceof CData);
        $fileName = $userInfo->filename;
        if ($fileName !== null) {
            assert($fileName instanceof CData);
            self::addStringReference($fileName);
        }

        $this->copyDefaultPropertiesTable($sourceEntry, $newEntry);
        $this->copyDefaultStaticMembersTable($sourceEntry, $newEntry);
        $this->copyInterfaceList($sourceEntry, $newEntry);
        $this->copyTraitInfo($sourceEntry, $newEntry);

        $functionMap = $this->copyFunctionTable($sourceEntry, $newEntry, $substitutions, $slotSubstitutions);
        $this->relinkFunctionPointers($newEntry, $functionMap);
        $this->copyIteratorFunctionCaches($sourceEntry, $newEntry, $functionMap);

        $propertyMap = $this->copyPropertiesInfo($sourceEntry, $newEntry, $substitutions, $slotSubstitutions);
        $this->copyPropertiesInfoTable($sourceEntry, $newEntry, $propertyMap);

        $this->copyConstantsTable($sourceEntry, $newEntry);

        return $newEntry;
    }

    /**
     * Copies the default (non-static) property value table with an owned reference per slot
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyDefaultPropertiesTable(object $sourceEntry, object $newEntry): void
    {
        $totalSlots = $sourceEntry->default_properties_count;
        assert(is_int($totalSlots));
        if ($totalSlots === 0) {
            return;
        }
        $sourceTable = $sourceEntry->default_properties_table;
        assert($sourceTable instanceof CData);
        // destroy_zend_class() releases every slot and efree()s the table: plain request
        // memory with one owned reference per refcounted value matches that exactly
        $newEntry->default_properties_table = $this->copyZvalTable($sourceTable, $totalSlots);
    }

    /**
     * Copies the default static member table; IS_INDIRECT views into the parent's storage
     * are copied as-is (zval_add_ref does not touch non-refcounted slots) and re-resolve
     * against the same parent when the copy materializes its live statics
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyDefaultStaticMembersTable(object $sourceEntry, object $newEntry): void
    {
        $totalSlots = $sourceEntry->default_static_members_count;
        assert(is_int($totalSlots));
        if ($totalSlots === 0) {
            return;
        }
        $sourceTable = $sourceEntry->default_static_members_table;
        assert($sourceTable instanceof CData);
        $newEntry->default_static_members_table = $this->copyZvalTable($sourceTable, $totalSlots);
    }

    /**
     * Duplicates an engine zval table into request memory, taking one owned reference per slot
     *
     * @param \FFI\CData $sourceTable
     * @return \FFI\CData
     */
    private function copyZvalTable(object $sourceTable, int $totalSlots): object
    {
        $zvalSize = Core::sizeof(Core::type('zval'));
        $memory   = Core::new("zval[{$totalSlots}]", false);
        Core::memcpy($memory, $sourceTable, $zvalSize * $totalSlots);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotValue = $memory[$slot];
            assert($slotValue instanceof CData);
            Core::call('zval_add_ref', Core::addr($slotValue));
        }

        return Core::cast('zval *', Core::addr($memory));
    }

    /**
     * Copies the resolved interface list (destroy_zend_class() efree()s it per class)
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyInterfaceList(object $sourceEntry, object $newEntry): void
    {
        $totalInterfaces = $sourceEntry->num_interfaces;
        assert(is_int($totalInterfaces));
        if ($totalInterfaces === 0) {
            return;
        }
        $itemSize = Core::sizeof(Core::type('zend_class_entry *'));
        $memory   = Core::new("zend_class_entry *[{$totalInterfaces}]", false);
        Core::memcpy($memory, $sourceEntry->interfaces, $itemSize * $totalInterfaces);
        $newEntry->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));
    }

    /**
     * Deep-copies the trait metadata (names, aliases, precedences): the engine releases
     * every stored name and efree()s every block per class, so sharing them with the
     * source would double-free at teardown
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyTraitInfo(object $sourceEntry, object $newEntry): void
    {
        $totalTraits = $sourceEntry->num_traits;
        assert(is_int($totalTraits));
        if ($totalTraits > 0) {
            $itemSize = Core::sizeof(Core::type('zend_class_name'));
            $memory   = Core::new("zend_class_name[{$totalTraits}]", false);
            Core::memcpy($memory, $sourceEntry->trait_names, $itemSize * $totalTraits);
            for ($index = 0; $index < $totalTraits; $index++) {
                $traitNamePair = $memory[$index];
                assert($traitNamePair instanceof CData);
                $traitName   = $traitNamePair->name;
                $lcTraitName = $traitNamePair->lc_name;
                assert($traitName instanceof CData && $lcTraitName instanceof CData);
                self::addStringReference($traitName);
                self::addStringReference($lcTraitName);
            }
            $newEntry->trait_names = Core::cast('zend_class_name *', Core::addr($memory));
        }

        $aliasList = $sourceEntry->trait_aliases;
        if ($aliasList !== null) {
            assert($aliasList instanceof CData);
            $aliases = [];
            for ($index = 0; $aliasList[$index] !== null; $index++) {
                $sourceAlias = $aliasList[$index];
                assert($sourceAlias instanceof CData);
                $aliasCopy = Core::new('zend_trait_alias', false);
                Core::memcpy($aliasCopy, $sourceAlias, Core::sizeof(Core::type('zend_trait_alias')));
                $aliasMethodRef = $aliasCopy->trait_method;
                assert($aliasMethodRef instanceof CData);
                $this->addTraitMethodReferenceNames($aliasMethodRef);
                $aliasName = $aliasCopy->alias;
                if ($aliasName !== null) {
                    assert($aliasName instanceof CData);
                    self::addStringReference($aliasName);
                }
                $aliases[] = Core::cast('zend_trait_alias *', Core::addr($aliasCopy));
            }
            $newEntry->trait_aliases = $this->packPointerList('zend_trait_alias', $aliases);
        }

        $precedenceList = $sourceEntry->trait_precedences;
        if ($precedenceList !== null) {
            assert($precedenceList instanceof CData);
            $pointerSize = Core::sizeof(Core::type('zend_string *'));
            $precedences = [];
            for ($index = 0; $precedenceList[$index] !== null; $index++) {
                $sourcePrecedence = $precedenceList[$index];
                assert($sourcePrecedence instanceof CData);
                $totalExcludes = $sourcePrecedence->num_excludes;
                assert(is_int($totalExcludes));
                $structSize = Core::sizeof(Core::type('zend_trait_precedence'))
                    + max(0, $totalExcludes - 1) * $pointerSize;
                $memory = Core::new("char[{$structSize}]", false);
                Core::memcpy($memory, $sourcePrecedence, $structSize);
                $precedenceCopy      = Core::cast('zend_trait_precedence *', $memory);
                $precedenceMethodRef = $precedenceCopy->trait_method;
                assert($precedenceMethodRef instanceof CData);
                $this->addTraitMethodReferenceNames($precedenceMethodRef);
                $excludeNames = self::precedenceExcludeNames($precedenceCopy);
                for ($excludeIndex = 0; $excludeIndex < $totalExcludes; $excludeIndex++) {
                    $excludeName = $excludeNames[$excludeIndex];
                    assert($excludeName instanceof CData);
                    self::addStringReference($excludeName);
                }
                $precedences[] = $precedenceCopy;
            }
            $newEntry->trait_precedences = $this->packPointerList('zend_trait_precedence', $precedences);
        }
    }

    /**
     * Takes owned references on the method/class names of a copied trait reference
     *
     * @param \FFI\CData $traitMethodRef
     */
    private function addTraitMethodReferenceNames(object $traitMethodRef): void
    {
        $methodName = $traitMethodRef->method_name;
        if ($methodName !== null) {
            assert($methodName instanceof CData);
            self::addStringReference($methodName);
        }
        $traitName = $traitMethodRef->class_name;
        if ($traitName !== null) {
            assert($traitName instanceof CData);
            self::addStringReference($traitName);
        }
    }

    /**
     * Packs copied adaptation structures into a NULL-terminated pointer list block
     *
     * @param list<CData> $items
     * @return \FFI\CData
     */
    private function packPointerList(string $itemType, array $items): object
    {
        $totalItems = count($items);
        $memory     = Core::new("{$itemType} *[" . ($totalItems + 1) . ']', false);
        foreach ($items as $position => $item) {
            self::storePointerSlot($memory, $position, $item);
        }

        return Core::cast("{$itemType} **", Core::addr($memory));
    }

    /**
     * Stores one pointer (or null) into the given slot of a pointer-array block
     *
     * The write mutates engine-visible memory behind the FFI pointer, which static
     * analysis cannot see - hence the explicit impurity marker.
     *
     * @phpstan-impure
     * @param \FFI\CData $arrayMemory
     * @param \FFI\CData|null $pointer
     */
    private static function storePointerSlot(object $arrayMemory, int $slot, ?object $pointer): void
    {
        $arrayMemory[$slot] = $pointer;
    }

    /**
     * Gives the copy its own per-class Iterator/ArrayAccess method-pointer caches
     *
     * The engine fills these blocks at interface-implementation time (they are NOT
     * lazy), so the copy gets a fresh block whose entries are re-targeted through the
     * copied method table: pointers at source-declared methods move onto the copies,
     * pointers at inherited shared entries stay as-is.
     *
     * @param array<int, CData> $functionMap Source zend_function address => copy pointer
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyIteratorFunctionCaches(object $sourceEntry, object $newEntry, array $functionMap): void
    {
        $sourceIteratorFuncs = $sourceEntry->iterator_funcs_ptr;
        if ($sourceIteratorFuncs !== null) {
            assert($sourceIteratorFuncs instanceof CData);
            $funcs = Core::new('zend_class_iterator_funcs', false);
            foreach (['zf_new_iterator', 'zf_valid', 'zf_current', 'zf_key', 'zf_next', 'zf_rewind'] as $slotName) {
                $this->remapFunctionSlot($sourceIteratorFuncs, $funcs, $slotName, $functionMap);
            }
            $newEntry->iterator_funcs_ptr = Core::cast('zend_class_iterator_funcs *', Core::addr($funcs));
        }
        $sourceArrayAccessFuncs = $sourceEntry->arrayaccess_funcs_ptr;
        if ($sourceArrayAccessFuncs !== null) {
            assert($sourceArrayAccessFuncs instanceof CData);
            $funcs = Core::new('zend_class_arrayaccess_funcs', false);
            foreach (['zf_offsetget', 'zf_offsetexists', 'zf_offsetset', 'zf_offsetunset'] as $slotName) {
                $this->remapFunctionSlot($sourceArrayAccessFuncs, $funcs, $slotName, $functionMap);
            }
            $newEntry->arrayaccess_funcs_ptr = Core::cast('zend_class_arrayaccess_funcs *', Core::addr($funcs));
        }
    }

    /**
     * Copies one cached zend_function pointer slot, re-targeting it through the method map
     *
     * @param array<int, CData> $functionMap Source zend_function address => copy pointer
     * @param \FFI\CData $sourceFuncs
     * @param \FFI\CData $targetFuncs
     */
    private function remapFunctionSlot(object $sourceFuncs, object $targetFuncs, string $slotName, array $functionMap): void
    {
        $sourceFunction = $sourceFuncs->{$slotName};
        if ($sourceFunction === null) {
            return;
        }
        assert($sourceFunction instanceof CData);
        $targetFuncs->{$slotName} = $functionMap[Core::addressOf($sourceFunction)] ?? $sourceFunction;
    }

    /**
     * Rebuilds the method table on the copy
     *
     * Own methods (scope == source class, including trait clones) are duplicated at the
     * zend_op_array level with the shared-body refcount bumped; methods inherited from a
     * userland parent stay SHARED pointers with the same refcount/name bump the engine's
     * own zend_duplicate_function() performs during inheritance.
     *
     * @return array<int, CData> Source zend_function address => zend_function pointer on the copy
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyFunctionTable(
        object $sourceEntry,
        object $newEntry,
        TypeSubstitutionMap $substitutions,
        SlotSubstitutionMap $slotSubstitutions,
    ): array {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'function_table');
        $sourceAddress    = Core::addressOf($sourceEntry);
        $newFunctionTable = $newEntry->function_table;
        assert($newFunctionTable instanceof CData);
        $newTable    = HashTable::fromCData(Core::addr($newFunctionTable));
        $functionMap = [];

        foreach ($this->methodTable($sourceEntry) as $methodName => $methodValue) {
            assert(is_string($methodName));
            $sourceFunction = $methodValue->getRawFunction();
            $sourceOpArray  = Core::cast('zend_op_array *', $sourceFunction);
            $scope          = $sourceOpArray->scope;
            assert($scope instanceof CData);

            if (Core::addressOf($scope) === $sourceAddress) {
                $publishedFunction = $this->duplicateMethod(
                    $sourceOpArray,
                    $newEntry,
                    $substitutions,
                    $slotSubstitutions,
                    $newTable,
                    $methodName,
                );
            } else {
                // Inherited entry: share the pointer exactly like zend_duplicate_function()
                self::addOpArrayBodyReference($sourceOpArray);
                $sharedView        = StructArray::at($sourceFunction);
                $publishedFunction = $newTable->addFunctionEntry($methodName, $sharedView);
            }
            $functionMap[Core::addressOf($sourceFunction)] = $publishedFunction;
        }

        return $functionMap;
    }

    /**
     * Duplicates one own method into the new class (trait-clone model)
     *
     * @param HashTable $newTable Method table of the copy
     * @param \FFI\CData $sourceOpArray
     * @param \FFI\CData $newEntry
     * @return \FFI\CData
     */
    private function duplicateMethod(
        object $sourceOpArray,
        object $newEntry,
        TypeSubstitutionMap $substitutions,
        SlotSubstitutionMap $slotSubstitutions,
        HashTable $newTable,
        string $methodName,
    ): object {
        $opArrayCopy = Core::new('zend_op_array', false);
        Core::memcpy($opArrayCopy, $sourceOpArray, Core::sizeof(Core::type('zend_op_array')));

        // Share the compiled body through the op_array refcount; each holder owns one
        // reference on the display name (destroy_op_array releases it per holder)
        self::addOpArrayBodyReference(Core::cast('zend_op_array *', Core::addr($opArrayCopy)));

        // Scope fix-up and per-copy engine caches: the run-time cache and the live
        // static-variables table are lazily materialized per copy by the engine
        $opArrayCopy->scope                     = $newEntry;
        $opArrayCopy->run_time_cache__ptr       = null;
        $opArrayCopy->static_variables_ptr__ptr = null;
        $functionFlags                          = $opArrayCopy->fn_flags;
        assert(is_int($functionFlags));
        // IMMUTABLE must not leak onto a mutable copy; HEAP_RT_CACHE is cleared because
        // the engine arena-allocates the lazily created cache, which destroy_op_array()
        // must not efree()
        $opArrayCopy->fn_flags = $functionFlags & ~(Core::ZEND_ACC_IMMUTABLE | Core::ZEND_ACC_HEAP_RT_CACHE);

        // A slot-addressed method always duplicates: the block it would otherwise share with
        // the template is exactly the block we are about to write into
        $needsOwnArgInfo = $slotSubstitutions->addressesMethod($methodName)
            || (!$substitutions->isEmpty() && $this->methodNeedsArgInfoSubstitution($sourceOpArray, $substitutions));
        if ($needsOwnArgInfo) {
            $this->duplicateArgInfo($opArrayCopy, $sourceOpArray, $substitutions, $slotSubstitutions, $methodName);
        }
        if ($slotSubstitutions->addressesMethod($methodName)) {
            $this->patchReceiveOpcodes($opArrayCopy, $sourceOpArray, $methodName, $slotSubstitutions);
        }

        return $newTable->addFunctionEntry($methodName, $opArrayCopy);
    }

    /**
     * Applies the engine's zend_duplicate_function() reference semantics to a user function:
     * bump the shared-body refcount and take one owned reference on the display name
     *
     * @param \FFI\CData $opArray
     */
    private static function addOpArrayBodyReference(object $opArray): void
    {
        $bodyRefcount = $opArray->refcount;
        if ($bodyRefcount !== null) {
            assert($bodyRefcount instanceof CData);
            $currentBodyCount = $bodyRefcount[0];
            assert(is_int($currentBodyCount));
            $bodyRefcount[0] = $currentBodyCount + 1;
        }
        $functionName = $opArray->function_name;
        if ($functionName !== null) {
            assert($functionName instanceof CData);
            self::addStringReference($functionName);
        }
    }

    /**
     * Checks whether any argument/return type of the method references a placeholder
     *
     * @param \FFI\CData $opArray
     */
    private function methodNeedsArgInfoSubstitution(object $opArray, TypeSubstitutionMap $substitutions): bool
    {
        foreach ($this->argInfoEntries($opArray) as $argInfo) {
            $argumentType = $argInfo->type;
            assert($argumentType instanceof CData);
            if ($this->typeContainsPlaceholder($argumentType, $substitutions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Duplicates the whole arg_info block of a method copy and applies type substitution
     *
     * The engine releases arg names and types (and efree()s the block) only when the
     * shared body refcount drops to zero, so exactly one of the sibling blocks is
     * released through the engine; the other block stays allocated until the request
     * allocator reclaims it (bounded: one block per substituted method).
     *
     * @param \FFI\CData $opArrayCopy
     * @param \FFI\CData $sourceOpArray
     */
    private function duplicateArgInfo(
        object $opArrayCopy,
        object $sourceOpArray,
        TypeSubstitutionMap $substitutions,
        SlotSubstitutionMap $slotSubstitutions,
        string $methodName,
    ): void {
        $functionFlags = $sourceOpArray->fn_flags;
        $numberOfArgs  = $sourceOpArray->num_args;
        assert(is_int($functionFlags) && is_int($numberOfArgs));
        $hasReturnEntry = ($functionFlags & Core::ZEND_ACC_HAS_RETURN_TYPE) !== 0;
        $totalEntries   = $numberOfArgs
            + ($hasReturnEntry ? 1 : 0)
            + (($functionFlags & Core::ZEND_ACC_VARIADIC) !== 0 ? 1 : 0);
        if ($totalEntries === 0) {
            return;
        }

        $entrySize  = Core::sizeof(Core::type('zend_arg_info'));
        $sourceArgs = $sourceOpArray->arg_info;
        assert($sourceArgs instanceof CData);
        // The allocation starts one entry before arg_info when a return entry exists
        $blockAddress = Core::addressOf($sourceArgs) - ($hasReturnEntry ? $entrySize : 0);
        $memory       = Core::new("zend_arg_info[{$totalEntries}]", false);
        Core::memcpy($memory, Core::pointerAtAddress('zend_arg_info *', $blockAddress), $entrySize * $totalEntries);

        for ($index = 0; $index < $totalEntries; $index++) {
            $entry = $memory[$index];
            assert($entry instanceof CData);
            $argName = $entry->name;
            if ($argName !== null) {
                assert($argName instanceof CData);
                self::addStringReference($argName);
            }
            $argumentType = $entry->type;
            assert($argumentType instanceof CData);
            // Physical index 0 is the return entry when the method declares one; every other
            // entry is parameter (index - returnEntryOffset)
            $isReturnEntry = $hasReturnEntry && $index === 0;
            $replacement   = $isReturnEntry
                ? $slotSubstitutions->resolveReturnType($methodName)
                : $slotSubstitutions->resolveParameter($methodName, $index - ($hasReturnEntry ? 1 : 0));
            if ($replacement !== null) {
                self::writeTypeInPlace($argumentType, $replacement);
            } else {
                $this->copyTypeInPlace($argumentType, $substitutions);
            }
        }

        $opArrayCopy->arg_info = Core::pointerAtAddress(
            'zend_arg_info *',
            Core::addressOf(Core::cast('zend_arg_info *', Core::addr($memory))) + ($hasReturnEntry ? $entrySize : 0),
        );
    }

    /**
     * Un-shares the opcode array when a substituted parameter's check lives in it
     *
     * ZEND_RECV caches its type mask in the opline: `opline->op2.num` is a verbatim copy of
     * `arg_info.type.type_mask`, and the handler tests that copy rather than reading arg_info
     * back on every call. Rewriting arg_info alone is therefore invisible at run time for a
     * plain RECV, which is why this exists at all.
     *
     * ZEND_RECV_INIT (a parameter with a default) and ZEND_RECV_VARIADIC cache nothing and are
     * enforced straight from arg_info, so they never reach this method - and neither does a
     * method whose cached masks already match, which keeps the common case free.
     *
     * The opcodes are shared with the template by design, so they are copied before being
     * written. See duplicateOpcodes() for what that copy has to fix up.
     *
     * @param \FFI\CData $opArrayCopy
     * @param \FFI\CData $sourceOpArray
     */
    private function patchReceiveOpcodes(
        object $opArrayCopy,
        object $sourceOpArray,
        string $methodName,
        SlotSubstitutionMap $slotSubstitutions,
    ): void {
        $total = $sourceOpArray->last;
        assert(is_int($total));

        // Collect (opline index => new mask) before touching anything: a method whose cached
        // masks already agree with arg_info needs no copy at all
        $sourceOpcodes = $sourceOpArray->opcodes;
        $copiedArgs    = $opArrayCopy->arg_info;
        assert($sourceOpcodes instanceof CData && $copiedArgs instanceof CData);

        $patches = [];
        for ($index = 0; $index < $total; $index++) {
            $opline = $sourceOpcodes[$index];
            assert($opline instanceof CData);
            if ($opline->opcode !== OpCode::RECV) {
                continue;
            }
            $argument = $opline->op1;
            assert($argument instanceof CData);
            $argumentNumber = $argument->num;
            assert(is_int($argumentNumber));
            if ($slotSubstitutions->resolveParameter($methodName, $argumentNumber - 1) === null) {
                continue;
            }
            $copiedArgument = $copiedArgs[$argumentNumber - 1];
            assert($copiedArgument instanceof CData);
            $copiedType = $copiedArgument->type;
            assert($copiedType instanceof CData);
            $newMask = $copiedType->type_mask;
            $cached  = $opline->op2;
            assert(is_int($newMask) && $cached instanceof CData);
            $cachedMask = $cached->num;
            assert(is_int($cachedMask));

            // A cached mask carrying a name or list bit means the compiler already routed this
            // parameter through the generic path that reads arg_info on every call, so the
            // rewrite is live without touching an opcode - and the opcodes stay shared, which
            // is both cheaper and free of the 32-bit reach limit below.
            $routedThroughArgInfo = ($cachedMask & (
                Core::engineConstant('_ZEND_TYPE_NAME_BIT') | Core::engineConstant('_ZEND_TYPE_LIST_BIT')
            )) !== 0;
            if (!$routedThroughArgInfo && $cachedMask !== $newMask) {
                $patches[$index] = $newMask;
            }
        }
        if ($patches === []) {
            return;
        }

        $copiedOpcodes = $this->duplicateOpcodes($opArrayCopy, $sourceOpArray, $total);
        foreach ($patches as $index => $newMask) {
            $patched = $copiedOpcodes[$index];
            assert($patched instanceof CData);
            $cachedMask = $patched->op2;
            assert($cachedMask instanceof CData);
            $cachedMask->num = $newMask;
        }
    }

    /**
     * Copies a method's opcode array into request memory so the copy can be written to
     *
     * Two of the three operand encodings survive a straight memcpy and one does not:
     *
     *  - jump targets are *signed* byte offsets from the opline itself, so they survive because
     *    the whole array moves as a unit and every target keeps the same relative distance;
     *  - `live_range` and `try_catch_array` address oplines by index, so they are unaffected;
     *  - **IS_CONST operands are byte offsets from the opline itself** (the engine resolves them
     *    as `(char *) opline + node.constant`, and literals sit immediately after the opcodes in
     *    one compiler-arena block), so every one of them has to be rebased by the distance the
     *    array moved.
     *
     * Ownership mirrors the duplicated arg_info blocks: `destroy_op_array()` frees whichever
     * `opcodes` pointer its holder carries once the shared body refcount reaches zero, so one
     * sibling block is released through the engine and the other is reclaimed by the request
     * allocator at request end. Bounded at one block per patched method. An opcache-shared
     * source is safe because it is only ever read - the copy is what gets written.
     *
     * @return CData The copied zend_op[] block
     * @param \FFI\CData $opArrayCopy
     * @param \FFI\CData $sourceOpArray
     */
    private function duplicateOpcodes(object $opArrayCopy, object $sourceOpArray, int $total): object
    {
        $sourceOpcodes = $sourceOpArray->opcodes;
        assert($sourceOpcodes instanceof CData);
        $opcodeSize = Core::sizeof(Core::type('zend_op'));
        $memory     = Core::new("zend_op[{$total}]", false);
        Core::memcpy($memory, $sourceOpcodes, $total * $opcodeSize);

        $sourceBase = Core::addressOf($sourceOpcodes);
        $copyBase   = Core::addressOf(Core::cast('zend_op *', Core::addr($memory)));
        $shift      = $sourceBase - $copyBase;

        for ($index = 0; $index < $total; $index++) {
            $opline = $memory[$index];
            assert($opline instanceof CData);
            foreach (self::CONSTANT_OPERAND_FIELDS as $typeField => $operandField) {
                if ($opline->{$typeField} !== OpLine::IS_CONST) {
                    continue;
                }
                $operand = $opline->{$operandField};
                assert($operand instanceof CData);
                $current = $operand->constant;
                assert(is_int($current));
                // znode_op.constant is a uint32_t holding a SIGNED opline-relative offset, so the
                // literal has to stay within 2GB of the relocated opline. Request memory and the
                // compiler arena are neighbours, but an opcache-shared body lives in an mmap'd
                // region that can be arbitrarily far away - and a silently truncated offset would
                // read whatever happens to sit at the wrapped address.
                $relocated = self::asSignedOffset($current) + $shift;
                if ($relocated < -0x80000000 || $relocated > 0x7FFFFFFF) {
                    throw new ClassSpecializationException(
                        'Cannot un-share the opcodes of this method: its literals are '
                        . abs($relocated) . ' bytes from the relocated opcode array, which does not '
                        . 'fit the signed 32-bit offset an IS_CONST operand stores. This happens when '
                        . 'the body is opcache-shared, because shared memory is too far from the '
                        . 'request heap; substituting a builtin parameter type needs a body that is '
                        . 'not in shared memory.',
                    );
                }
                $operand->constant = $relocated & 0xFFFFFFFF;
            }
        }

        $opArrayCopy->opcodes = Core::cast('zend_op *', Core::addr($memory));
        assert(self::opcodeCopyResolvesIdentically($sourceOpcodes, $memory, $total, $opcodeSize));

        return $memory;
    }

    /**
     * Verifies that a relocated opcode block still means exactly what the source meant
     *
     * Every IS_CONST operand must resolve to the same zval address it resolved to before the
     * move, and every jump offset must still land inside the array. This runs under
     * zend.assertions=1 and compiles out in production: a wrong relocation rule is the one
     * mistake here that would otherwise surface as memory corruption at some later call rather
     * than as a failure at specialization time.
     *
     * @param \FFI\CData $sourceOpcodes
     * @param \FFI\CData $copiedOpcodes
     */
    private static function opcodeCopyResolvesIdentically(
        object $sourceOpcodes,
        object $copiedOpcodes,
        int $total,
        int $opcodeSize,
    ): bool {
        $sourceBase = Core::addressOf($sourceOpcodes);
        $copyBase   = Core::addressOf(Core::cast('zend_op *', Core::addr($copiedOpcodes)));

        for ($index = 0; $index < $total; $index++) {
            $sourceOpline = $sourceOpcodes[$index];
            $copiedOpline = $copiedOpcodes[$index];
            assert($sourceOpline instanceof CData && $copiedOpline instanceof CData);

            if ($sourceOpline->opcode !== $copiedOpline->opcode) {
                return false;
            }
            foreach (self::CONSTANT_OPERAND_FIELDS as $typeField => $operandField) {
                if ($sourceOpline->{$typeField} !== OpLine::IS_CONST) {
                    continue;
                }
                $sourceOperand = $sourceOpline->{$operandField};
                $copiedOperand = $copiedOpline->{$operandField};
                assert($sourceOperand instanceof CData && $copiedOperand instanceof CData);
                $sourceConstant = $sourceOperand->constant;
                $copiedConstant = $copiedOperand->constant;
                assert(is_int($sourceConstant) && is_int($copiedConstant));
                // Both are unsigned views of a signed offset, so compare the resolved addresses
                $sourceTarget = $sourceBase + $index * $opcodeSize + self::asSignedOffset($sourceConstant);
                $copiedTarget = $copyBase   + $index * $opcodeSize + self::asSignedOffset($copiedConstant);
                if ($sourceTarget !== $copiedTarget) {
                    return false;
                }
            }
            $jumpTarget = $copiedOpline->op2;
            $copiedCode = $copiedOpline->opcode;
            assert($jumpTarget instanceof CData && is_int($copiedCode));
            if (!self::isJumpOperand($copiedCode)) {
                continue;
            }
            // Jump offsets are signed and relative to the opline itself, so a backward jump is a
            // large unsigned value; resolve it the way the engine does before range-checking
            $jumpOffset = $jumpTarget->num;
            assert(is_int($jumpOffset));
            $targetIndex = $index + intdiv(self::asSignedOffset($jumpOffset), $opcodeSize);
            if ($targetIndex < 0 || $targetIndex > $total) {
                return false;
            }
        }

        return true;
    }

    private static function asSignedOffset(int $unsigned): int
    {
        return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
    }

    private static function isJumpOperand(int $opcode): bool
    {
        return in_array($opcode, [OpCode::JMPZ, OpCode::JMPNZ, OpCode::JMPZ_EX, OpCode::JMPNZ_EX], true);
    }

    /**
     * Rebuilds the properties_info table: own zend_property_info entries are copied
     * (with type substitution), inherited entries stay shared with the declaring class
     *
     * @return array<int, CData> Source zend_property_info address => copy pointer
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyPropertiesInfo(
        object $sourceEntry,
        object $newEntry,
        TypeSubstitutionMap $substitutions,
        SlotSubstitutionMap $slotSubstitutions,
    ): array {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'properties_info');
        $sourceAddress      = Core::addressOf($sourceEntry);
        $newPropertiesTable = $newEntry->properties_info;
        assert($newPropertiesTable instanceof CData);
        $newTable    = HashTable::fromCData(Core::addr($newPropertiesTable));
        $propertyMap = [];
        $ownCopies   = [];

        foreach ($this->propertiesTable($sourceEntry) as $propertyName => $propertyValue) {
            assert(is_string($propertyName));
            $sourceInfo     = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $declaringClass = $sourceInfo->ce;
            assert($declaringClass instanceof CData);

            if (Core::addressOf($declaringClass) === $sourceAddress) {
                $infoCopy = Core::new('zend_property_info', false);
                Core::memcpy($infoCopy, $sourceInfo, Core::sizeof(Core::type('zend_property_info')));
                $infoCopy->ce      = $newEntry;
                $propertyNameValue = $infoCopy->name;
                assert($propertyNameValue instanceof CData);
                self::addStringReference($propertyNameValue);
                $propertyDoc = $infoCopy->doc_comment;
                if ($propertyDoc !== null) {
                    assert($propertyDoc instanceof CData);
                    self::addStringReference($propertyDoc);
                }
                $propertyAttributes = $infoCopy->attributes;
                if ($propertyAttributes !== null) {
                    assert($propertyAttributes instanceof CData);
                    self::addHashTableReference($propertyAttributes);
                }
                $copiedPropertyType = $infoCopy->type;
                assert($copiedPropertyType instanceof CData);
                $slotReplacement = $slotSubstitutions->resolveProperty($propertyName);
                if ($slotReplacement !== null) {
                    self::writeTypeInPlace($copiedPropertyType, $slotReplacement);
                } else {
                    $this->copyTypeInPlace($copiedPropertyType, $substitutions);
                }

                $storedInfo  = Core::cast('zend_property_info *', Core::addr($infoCopy));
                $ownCopies[] = $storedInfo;
            } else {
                $storedInfo = $sourceInfo;
            }
            $this->publishPointerEntry($newTable, $propertyName, $storedInfo);
            $propertyMap[Core::addressOf($sourceInfo)] = $storedInfo;
        }

        // The prototype of a root declaration points at the declaration itself; re-target
        // copied self-references (and references to other copied infos) onto the copies
        foreach ($ownCopies as $infoCopy) {
            $prototype = $infoCopy->prototype;
            if ($prototype !== null) {
                assert($prototype instanceof CData);
                $mappedPrototype = $propertyMap[Core::addressOf($prototype)] ?? null;
                if ($mappedPrototype !== null) {
                    $infoCopy->prototype = $mappedPrototype;
                }
            }
        }

        return $propertyMap;
    }

    /**
     * Mirrors the slot-indexed properties_info_table onto the copy (GC, foreach and
     * var_dump read it): copied slots point at the copied infos, inherited slots keep
     * the shared pointers. The block mimics the compiler arena (never freed by the engine).
     *
     * @param array<int, CData> $propertyMap Source zend_property_info address => copy pointer
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyPropertiesInfoTable(object $sourceEntry, object $newEntry, array $propertyMap): void
    {
        $sourceTable = $sourceEntry->properties_info_table;
        if ($sourceTable === null) {
            return;
        }
        assert($sourceTable instanceof CData);
        $totalSlots = $sourceEntry->default_properties_count;
        assert(is_int($totalSlots));
        if ($totalSlots === 0) {
            return;
        }
        $memory = Core::new("zend_property_info *[{$totalSlots}]", false);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $sourceInfo = $sourceTable[$slot];
            if ($sourceInfo === null) {
                continue;
            }
            assert($sourceInfo instanceof CData);
            self::storePointerSlot($memory, $slot, $propertyMap[Core::addressOf($sourceInfo)] ?? $sourceInfo);
        }
        $newEntry->properties_info_table = Core::cast('zend_property_info **', Core::addr($memory));
    }

    /**
     * Rebuilds the constants table: constants owned by the source class (declared there,
     * or lazily materialized inherited AST constants carrying CONST_OWNED) are copied
     * with an owned value reference; the rest stay shared with the declaring class
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function copyConstantsTable(object $sourceEntry, object $newEntry): void
    {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'constants_table');
        $sourceAddress     = Core::addressOf($sourceEntry);
        $newConstantsTable = $newEntry->constants_table;
        assert($newConstantsTable instanceof CData);
        $newTable = HashTable::fromCData(Core::addr($newConstantsTable));

        foreach ($this->constantsTable($sourceEntry) as $constantName => $constantValue) {
            assert(is_string($constantName));
            $sourceConstant = Core::cast('zend_class_constant *', $constantValue->getRawPointer());
            $declaringClass = $sourceConstant->ce;
            $constantZval   = $sourceConstant->value;
            assert($declaringClass instanceof CData && $constantZval instanceof CData);
            $constantExtra = $constantZval->u2;
            assert($constantExtra instanceof CData);
            $constantFlags = $constantExtra->constant_flags;
            assert(is_int($constantFlags));
            $isOwnDeclaration = Core::addressOf($declaringClass) === $sourceAddress;
            $isOwnedValue     = ($constantFlags & Core::engineConstant('CONST_OWNED')) !== 0;

            if ($isOwnDeclaration || $isOwnedValue) {
                $constantCopy = Core::new('zend_class_constant', false);
                Core::memcpy($constantCopy, $sourceConstant, Core::sizeof(Core::type('zend_class_constant')));
                if ($isOwnDeclaration) {
                    $constantCopy->ce = $newEntry;
                }
                $copiedValue = $constantCopy->value;
                assert($copiedValue instanceof CData);
                Core::call('zval_add_ref', Core::addr($copiedValue));
                $constantDoc = $constantCopy->doc_comment;
                if ($constantDoc !== null) {
                    assert($constantDoc instanceof CData);
                    self::addStringReference($constantDoc);
                }
                $constantAttributes = $constantCopy->attributes;
                if ($constantAttributes !== null) {
                    assert($constantAttributes instanceof CData);
                    self::addHashTableReference($constantAttributes);
                }
                $storedConstant = Core::cast('zend_class_constant *', Core::addr($constantCopy));
            } else {
                $storedConstant = $sourceConstant;
            }
            $this->publishPointerEntry($newTable, $constantName, $storedConstant);
        }
    }

    /**
     * Re-targets the cached constructor/destructor/magic-method pointers onto the copied
     * method table (inherited pointers map onto the shared entries and stay unchanged)
     *
     * @param array<int, CData> $functionMap Source zend_function address => copy pointer
     * @param \FFI\CData $newEntry
     */
    private function relinkFunctionPointers(object $newEntry, array $functionMap): void
    {
        foreach (self::FUNCTION_POINTER_FIELDS as $fieldName) {
            $currentFunction = $newEntry->{$fieldName};
            if ($currentFunction === null) {
                continue;
            }
            assert($currentFunction instanceof CData);
            $mappedFunction = $functionMap[Core::addressOf($currentFunction)] ?? null;
            if ($mappedFunction !== null) {
                $newEntry->{$fieldName} = $mappedFunction;
            }
        }
    }

    /**
     * Initializes one embedded class-entry HashTable of the copy in the engine's
     * uninitialized state (field-for-field port of _zend_hash_init_int for request
     * tables), keeping the destructor of the corresponding source table
     *
     * @param \FFI\CData $sourceEntry
     * @param \FFI\CData $newEntry
     */
    private function initEmbeddedTable(object $sourceEntry, object $newEntry, string $tableField): void
    {
        $sourceTable = $sourceEntry->{$tableField};
        $targetTable = $newEntry->{$tableField};
        assert($sourceTable instanceof CData && $targetTable instanceof CData);
        $targetHeader = $targetTable->gc;
        assert($targetHeader instanceof CData);
        $targetHeaderInfo = $targetHeader->u;
        $targetFlagsUnion = $targetTable->u;
        assert($targetHeaderInfo instanceof CData && $targetFlagsUnion instanceof CData);

        $targetHeader->refcount        = 1;
        $targetHeaderInfo->type_info   = Core::engineConstant('GC_ARRAY');
        $targetFlagsUnion->flags       = Core::engineConstant('HASH_FLAG_UNINITIALIZED');
        $targetTable->nTableMask       = Core::engineConstant('HT_MIN_MASK');
        $targetTable->arData           = PersistentHashTable::uninitializedBucketData();
        $targetTable->nNumUsed         = 0;
        $targetTable->nNumOfElements   = 0;
        $targetTable->nTableSize       = Core::engineConstant('HT_MIN_SIZE');
        $targetTable->nInternalPointer = 0;
        $targetTable->nNextFreeElement = PHP_INT_MIN;
        $targetTable->pDestructor      = $sourceTable->pDestructor;
    }

    /**
     * Publishes an IS_PTR entry into an engine table under a string key
     *
     * The passed pointer is dereferenced because ReflectionValue::newEntry() stores the
     * ADDRESS of the given structure view in the zval it builds.
     *
     * @param HashTable $table Table on the copy
     * @param \FFI\CData $pointer
     */
    private function publishPointerEntry(HashTable $table, string $key, object $pointer): void
    {
        $structureView = $pointer[0];
        assert($structureView instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $structureView);
        $table->add($key, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Writes a brand-new zend_type into an already-memcpy'd slot
     *
     * The counterpart of copyTypeInPlace(): that one preserves whatever the template declared
     * and only rewrites a matching placeholder name, this one replaces the declaration wholesale.
     * Because the owner block was memcpy'd and the template keeps its own reference on the old
     * name, replacing simply means *not* taking the reference copyTypeInPlace() would have taken
     * - there is nothing to release here.
     *
     * Nullability is taken from the replacement (`?int`) rather than preserved from the slot:
     * a slot-addressed substitution states the whole type, and `mixed` implies null, so
     * preserving would silently turn every `mixed` slot into a nullable one.
     *
     * @param \FFI\CData $type
     */
    private static function writeTypeInPlace(object $type, string $replacement): void
    {
        $isNullable = str_starts_with($replacement, '?');
        $typeName   = $isNullable ? substr($replacement, 1) : $replacement;
        $nullBit    = $isNullable ? Core::engineConstant('_ZEND_TYPE_NULLABLE_BIT') : 0;

        // Everything the type itself describes (MAY_BE_* bits, NAME/LITERAL_NAME/LIST kind bits,
        // ARENA, UNION/INTERSECTION) lives inside _ZEND_TYPE_MASK and is replaced wholesale. The
        // bits ABOVE it are not type information at all: on a zend_arg_info they carry the send
        // mode, the variadic marker and the tentative-type marker, and dropping them silently
        // changes how the engine treats the parameter.
        $currentMask = $type->type_mask;
        assert(is_int($currentMask));
        $preservedBits = $currentMask & ~Core::engineConstant('_ZEND_TYPE_MASK');

        $builtinMask = self::builtinTypeMask($typeName);
        if ($builtinMask !== null) {
            $type->ptr       = null;
            $type->type_mask = $preservedBits | $builtinMask | $nullBit;

            return;
        }

        // A class-like type: owned name with refcount 1, and LITERAL_NAME cleared because the
        // name written here is already fully qualified and resolved
        $replacementName = StringEntry::fromString($typeName)->transferReferenceOwnership()->getRawValue();
        $type->ptr       = Core::cast('void *', $replacementName);
        $type->type_mask = $preservedBits | Core::engineConstant('_ZEND_TYPE_NAME_BIT') | $nullBit;
    }

    /**
     * Copies one zend_type in place on an already-memcpy'd owner structure: substitutes
     * matching single-name placeholder types, takes owned name references for kept names
     * and duplicates type lists into request memory
     *
     * @param \FFI\CData $type
     */
    private function copyTypeInPlace(object $type, TypeSubstitutionMap $substitutions): void
    {
        $typeMask = $type->type_mask;
        assert(is_int($typeMask));

        if (($typeMask & Core::engineConstant('_ZEND_TYPE_LIST_BIT')) !== 0) {
            $this->copyTypeListInPlace($type, $substitutions);

            return;
        }
        if (($typeMask & Core::engineConstant('_ZEND_TYPE_NAME_BIT')) === 0) {
            // Plain MAY_BE_* masks carry no owned payload
            return;
        }

        $rawName = $type->ptr;
        assert($rawName instanceof CData);
        $nameEntry   = StringEntry::fromCData(Core::cast('zend_string *', $rawName));
        $replacement = $substitutions->resolve($nameEntry->getStringValue());
        if ($replacement === null) {
            // The copy shares the source name with an owned reference (interned-aware)
            self::addStringReference(Core::cast('zend_string *', $rawName));

            return;
        }

        $builtinMask = self::builtinTypeMask($replacement);
        if ($builtinMask !== null) {
            // Placeholder becomes a builtin type: drop the name, keep nullability and
            // any other MAY_BE_* bits the declaration carried (eg ?T)
            $type->ptr       = null;
            $type->type_mask = ($typeMask & ~Core::engineConstant('_ZEND_TYPE_NAME_BIT')) | $builtinMask;

            return;
        }
        // Placeholder becomes another class-like type: owned name, refcount 1
        $replacementName = StringEntry::fromString($replacement)->transferReferenceOwnership()->getRawValue();
        $type->ptr       = Core::cast('void *', $replacementName);
    }

    /**
     * Duplicates a union/intersection type list into request memory
     *
     * The copied list carries the ARENA ownership bit, so zend_type_release() on the
     * copy releases the contained names but never frees the block itself - it is
     * reclaimed by the request allocator, mimicking the compiler-arena lists of
     * runtime-compiled classes (and never touching a possibly shared source list).
     *
     * @param \FFI\CData $type
     */
    private function copyTypeListInPlace(object $type, TypeSubstitutionMap $substitutions): void
    {
        $rawList = $type->ptr;
        assert($rawList instanceof CData);
        $sourceList = Core::cast('zend_type_list *', $rawList);
        $totalTypes = $sourceList->num_types;
        assert(is_int($totalTypes));

        $typeSize   = Core::sizeof(Core::type('zend_type'));
        $baseOffset = Core::type('zend_type_list')->getStructFieldOffset('types');
        $blockSize  = $baseOffset + $totalTypes * $typeSize;
        $memory     = Core::new("char[{$blockSize}]", false);
        Core::memcpy($memory, $sourceList, $blockSize);

        $listCopy = Core::cast('zend_type_list *', $memory);
        $entries  = Core::pointerAtAddress('zend_type *', Core::addressOf(Core::cast('zend_type_list *', $memory)) + $baseOffset);
        for ($index = 0; $index < $totalTypes; $index++) {
            $listedType = $entries[$index];
            assert($listedType instanceof CData);
            // Placeholders inside lists were rejected up front, so this only re-owns names
            // and recursively duplicates nested (DNF) lists
            $this->copyTypeInPlace($listedType, $substitutions);
        }

        $typeMask = $type->type_mask;
        assert(is_int($typeMask));
        $type->ptr       = Core::cast('void *', $listCopy);
        $type->type_mask = $typeMask | Core::engineConstant('_ZEND_TYPE_ARENA_BIT');
    }

    /**
     * Maps a builtin replacement type name onto its MAY_BE_* mask (null for class-like names)
     */
    private static function builtinTypeMask(string $typeName): ?int
    {
        return match (strtolower($typeName)) {
            'int'    => 1 << Core::engineConstant('IS_LONG'),
            'float'  => 1 << Core::engineConstant('IS_DOUBLE'),
            'string' => 1 << Core::engineConstant('IS_STRING'),
            'bool'   => (1 << Core::engineConstant('IS_FALSE')) | (1 << Core::engineConstant('IS_TRUE')),
            'true'   => 1 << Core::engineConstant('IS_TRUE'),
            'false'  => 1 << Core::engineConstant('IS_FALSE'),
            'null'   => 1 << Core::engineConstant('IS_NULL'),
            'array'  => 1 << Core::engineConstant('IS_ARRAY'),
            'object' => 1 << Core::engineConstant('IS_OBJECT'),
            'mixed'  => (1 << Core::engineConstant('IS_NULL'))
                | (1 << Core::engineConstant('IS_FALSE'))
                | (1 << Core::engineConstant('IS_TRUE'))
                | (1 << Core::engineConstant('IS_LONG'))
                | (1 << Core::engineConstant('IS_DOUBLE'))
                | (1 << Core::engineConstant('IS_STRING'))
                | (1 << Core::engineConstant('IS_ARRAY'))
                | (1 << Core::engineConstant('IS_OBJECT'))
                | (1 << Core::engineConstant('IS_RESOURCE')),
            default => null,
        };
    }

    /**
     * Takes one owned reference on an engine string with interned/immutable awareness
     * (the exact counterpart of the release the engine performs at teardown)
     *
     * @param \FFI\CData $stringPointer
     */
    private static function addStringReference(object $stringPointer): void
    {
        $stringEntry = StringEntry::fromCData($stringPointer);
        if (!$stringEntry->isImmutable()) {
            $stringEntry->incrementReferenceCount();
        }
    }

    /**
     * Takes one owned reference on an engine hashtable with immutable awareness
     * (zend_hash_release skips immutable tables symmetrically)
     *
     * @param \FFI\CData $tablePointer
     */
    private static function addHashTableReference(object $tablePointer): void
    {
        $table = HashTable::fromCData($tablePointer);
        if (!$table->isImmutable()) {
            $table->incrementReferenceCount();
        }
    }

    /**
     * Borrowed view over the source method table
     *
     * @return HashTable
     * @param \FFI\CData $classEntry
     */
    private function methodTable(object $classEntry): HashTable
    {
        $tableStruct = $classEntry->function_table;
        assert($tableStruct instanceof CData);

        return HashTable::fromCData(Core::addr($tableStruct));
    }

    /**
     * Borrowed view over the source properties-info table
     *
     * @return HashTable
     * @param \FFI\CData $classEntry
     */
    private function propertiesTable(object $classEntry): HashTable
    {
        $tableStruct = $classEntry->properties_info;
        assert($tableStruct instanceof CData);

        return HashTable::fromCData(Core::addr($tableStruct));
    }

    /**
     * Borrowed view over the source constants table
     *
     * @return HashTable
     * @param \FFI\CData $classEntry
     */
    private function constantsTable(object $classEntry): HashTable
    {
        $tableStruct = $classEntry->constants_table;
        assert($tableStruct instanceof CData);

        return HashTable::fromCData(Core::addr($tableStruct));
    }

    /**
     * Iterates every zend_arg_info entry of a user function: the return entry (index -1
     * when a return type is declared), all declared parameters and the variadic entry
     *
     * @return iterable<CData>
     * @param \FFI\CData $opArray
     */
    private function argInfoEntries(object $opArray): iterable
    {
        $argInfoTable = $opArray->arg_info;
        if ($argInfoTable === null) {
            return;
        }
        assert($argInfoTable instanceof CData);
        $functionFlags = $opArray->fn_flags;
        $numberOfArgs  = $opArray->num_args;
        assert(is_int($functionFlags) && is_int($numberOfArgs));
        $firstIndex = ($functionFlags & Core::ZEND_ACC_HAS_RETURN_TYPE) !== 0 ? -1 : 0;
        $lastIndex  = $numberOfArgs - 1 + (($functionFlags & Core::ZEND_ACC_VARIADIC) !== 0 ? 1 : 0);
        $entrySize  = Core::sizeof(Core::type('zend_arg_info'));
        for ($index = $firstIndex; $index <= $lastIndex; $index++) {
            $entry = Core::pointerAtAddress(
                'zend_arg_info *',
                Core::addressOf($argInfoTable) + $index * $entrySize,
            )[0];
            assert($entry instanceof CData);
            yield $entry;
        }
    }

    /**
     * Iterates the zend_type entries of a type list
     *
     * @return iterable<CData>
     * @param \FFI\CData $type
     */
    private function typeListEntries(object $type): iterable
    {
        $rawList = $type->ptr;
        assert($rawList instanceof CData);
        $list       = Core::cast('zend_type_list *', $rawList);
        $totalTypes = $list->num_types;
        assert(is_int($totalTypes));
        $baseOffset = Core::type('zend_type_list')->getStructFieldOffset('types');
        $entries    = Core::pointerAtAddress('zend_type *', Core::addressOf($list) + $baseOffset);
        for ($index = 0; $index < $totalTypes; $index++) {
            $entry = $entries[$index];
            assert($entry instanceof CData);
            yield $entry;
        }
    }

    /**
     * Returns a freely indexable pointer to the flexible exclude_class_names array of a
     * zend_trait_precedence (the declared 1-element FFI view is bounds-checked)
     *
     * @param \FFI\CData $precedenceEntry
     * @return \FFI\CData
     */
    private static function precedenceExcludeNames(object $precedenceEntry): object
    {
        $excludeOffset = Core::type('zend_trait_precedence')->getStructFieldOffset('exclude_class_names');

        return Core::pointerAtAddress('zend_string **', Core::addressOf($precedenceEntry) + $excludeOffset);
    }
}
