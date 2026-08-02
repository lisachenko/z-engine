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
use ZEngine\Type\HashTable;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;

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
    ): ReflectionClass {
        $substitutions ??= new TypeSubstitutionMap([]);

        // Give the autoloader a chance to bring the source declaration into the engine
        // (the raw class-table lookup below never triggers autoloading by itself)
        class_exists($sourceClassName);

        $sourceEntry = $this->findClassEntry($sourceClassName);
        if ($sourceEntry === null) {
            throw new ClassSpecializationException("Class {$sourceClassName} was not found in the engine");
        }
        $this->assertSupportedSource($sourceEntry, $sourceClassName, $newClassName);
        $this->assertSubstitutionsApplicable($sourceEntry, $sourceClassName, $substitutions);

        $newEntry = $this->copyClassEntry($sourceEntry, $newClassName, $substitutions);

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
     * Looks up a zend_class_entry pointer by class name (null when not registered)
     */
    private function findClassEntry(string $className): ?CData
    {
        $classValue = Core::$executor->classTable->find(strtolower($className));

        return $classValue?->getRawClass();
    }

    /**
     * Support matrix: every unsupported source fails here, before any allocation
     */
    private function assertSupportedSource(CData $sourceEntry, string $sourceClassName, string $newClassName): void
    {
        if ($newClassName === '') {
            throw new ClassSpecializationException('Specialized class name can not be empty');
        }
        if ($this->findClassEntry($newClassName) !== null) {
            throw new ClassSpecializationException("Class {$newClassName} already exists in the engine");
        }
        $sourceKind = $sourceEntry->type;
        assert(is_string($sourceKind));
        if (ord($sourceKind) !== Core::ZEND_USER_CLASS) {
            throw new ClassSpecializationException(
                "Cannot specialize internal class {$sourceClassName}: only userland classes are supported",
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
                "Cannot specialize {$sourceClassName}: it is {$unsupportedKind}, only plain classes are supported",
            );
        }
        if (($classFlags & Core::ZEND_ACC_LINKED) === 0) {
            throw new ClassSpecializationException(
                "Cannot specialize {$sourceClassName}: the class is not linked yet",
            );
        }
        $hookedProperties = $sourceEntry->num_hooked_props;
        assert(is_int($hookedProperties));
        if ($hookedProperties > 0) {
            throw new ClassSpecializationException(
                "Cannot specialize {$sourceClassName}: classes with property hooks are not supported",
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
                    "Cannot specialize {$sourceClassName}: ancestor {$parentName} is an internal class",
                );
            }
        }
        foreach ($this->methodTable($sourceEntry) as $methodValue) {
            $rawFunction  = $methodValue->getRawFunction();
            $functionKind = $rawFunction->type;
            assert(is_int($functionKind));
            if ($functionKind !== Core::ZEND_USER_FUNCTION) {
                throw new ClassSpecializationException(
                    "Cannot specialize {$sourceClassName}: the method table contains internal functions",
                );
            }
        }
    }

    /**
     * Refuses substitutions that would either mutate shared (inherited) declarations or
     * require rewriting inside union/intersection type lists
     */
    private function assertSubstitutionsApplicable(
        CData $sourceEntry,
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
     * Validates one zend_type against the substitution map
     *
     * @param bool $isOwn Whether the containing declaration will be copied (own member)
     */
    private function assertTypeSubstitutable(
        CData $type,
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
     */
    private function typeContainsPlaceholder(CData $type, TypeSubstitutionMap $substitutions): bool
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
     */
    private function copyClassEntry(CData $sourceEntry, string $newClassName, TypeSubstitutionMap $substitutions): CData
    {
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
        $newClassNameValue  = StringEntry::fromString($newClassName)->transferReferenceOwnership()->getRawValue();
        assert($newClassNameValue instanceof CData);
        $newEntry->name = $newClassNameValue;

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

        $functionMap = $this->copyFunctionTable($sourceEntry, $newEntry, $substitutions);
        $this->relinkFunctionPointers($newEntry, $functionMap);
        $this->copyIteratorFunctionCaches($sourceEntry, $newEntry, $functionMap);

        $propertyMap = $this->copyPropertiesInfo($sourceEntry, $newEntry, $substitutions);
        $this->copyPropertiesInfoTable($sourceEntry, $newEntry, $propertyMap);

        $this->copyConstantsTable($sourceEntry, $newEntry);

        return $newEntry;
    }

    /**
     * Copies the default (non-static) property value table with an owned reference per slot
     */
    private function copyDefaultPropertiesTable(CData $sourceEntry, CData $newEntry): void
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
     */
    private function copyDefaultStaticMembersTable(CData $sourceEntry, CData $newEntry): void
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
     */
    private function copyZvalTable(CData $sourceTable, int $totalSlots): CData
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
     */
    private function copyInterfaceList(CData $sourceEntry, CData $newEntry): void
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
     */
    private function copyTraitInfo(CData $sourceEntry, CData $newEntry): void
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
     */
    private function addTraitMethodReferenceNames(CData $traitMethodRef): void
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
     */
    private function packPointerList(string $itemType, array $items): CData
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
     */
    private static function storePointerSlot(CData $arrayMemory, int $slot, ?CData $pointer): void
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
     */
    private function copyIteratorFunctionCaches(CData $sourceEntry, CData $newEntry, array $functionMap): void
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
     */
    private function remapFunctionSlot(CData $sourceFuncs, CData $targetFuncs, string $slotName, array $functionMap): void
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
     */
    private function copyFunctionTable(CData $sourceEntry, CData $newEntry, TypeSubstitutionMap $substitutions): array
    {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'function_table');
        $sourceAddress    = Core::addressOf($sourceEntry);
        $newFunctionTable = $newEntry->function_table;
        assert($newFunctionTable instanceof CData);
        $newTable    = new HashTable(Core::addr($newFunctionTable));
        $functionMap = [];

        foreach ($this->methodTable($sourceEntry) as $methodName => $methodValue) {
            assert(is_string($methodName));
            $sourceFunction = $methodValue->getRawFunction();
            $sourceOpArray  = Core::cast('zend_op_array *', $sourceFunction);
            $scope          = $sourceOpArray->scope;
            assert($scope instanceof CData);

            if (Core::addressOf($scope) === $sourceAddress) {
                $publishedFunction = $this->duplicateMethod($sourceOpArray, $newEntry, $substitutions, $newTable, $methodName);
            } else {
                // Inherited entry: share the pointer exactly like zend_duplicate_function()
                self::addOpArrayBodyReference($sourceOpArray);
                $sharedView = $sourceFunction[0];
                assert($sharedView instanceof CData);
                $publishedFunction = $newTable->addFunctionEntry($methodName, $sharedView);
            }
            $functionMap[Core::addressOf($sourceFunction)] = $publishedFunction;
        }

        return $functionMap;
    }

    /**
     * Duplicates one own method into the new class (trait-clone model)
     *
     * @param HashTable&iterable<string|null, ReflectionValue> $newTable Method table of the copy
     */
    private function duplicateMethod(
        CData $sourceOpArray,
        CData $newEntry,
        TypeSubstitutionMap $substitutions,
        HashTable $newTable,
        string $methodName,
    ): CData {
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

        if (!$substitutions->isEmpty() && $this->methodNeedsArgInfoSubstitution($sourceOpArray, $substitutions)) {
            $this->duplicateArgInfo($opArrayCopy, $sourceOpArray, $substitutions);
        }

        return $newTable->addFunctionEntry($methodName, $opArrayCopy);
    }

    /**
     * Applies the engine's zend_duplicate_function() reference semantics to a user function:
     * bump the shared-body refcount and take one owned reference on the display name
     */
    private static function addOpArrayBodyReference(CData $opArray): void
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
     */
    private function methodNeedsArgInfoSubstitution(CData $opArray, TypeSubstitutionMap $substitutions): bool
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
     */
    private function duplicateArgInfo(CData $opArrayCopy, CData $sourceOpArray, TypeSubstitutionMap $substitutions): void
    {
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
            $this->copyTypeInPlace($argumentType, $substitutions);
        }

        $opArrayCopy->arg_info = Core::pointerAtAddress(
            'zend_arg_info *',
            Core::addressOf(Core::cast('zend_arg_info *', Core::addr($memory))) + ($hasReturnEntry ? $entrySize : 0),
        );
    }

    /**
     * Rebuilds the properties_info table: own zend_property_info entries are copied
     * (with type substitution), inherited entries stay shared with the declaring class
     *
     * @return array<int, CData> Source zend_property_info address => copy pointer
     */
    private function copyPropertiesInfo(CData $sourceEntry, CData $newEntry, TypeSubstitutionMap $substitutions): array
    {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'properties_info');
        $sourceAddress      = Core::addressOf($sourceEntry);
        $newPropertiesTable = $newEntry->properties_info;
        assert($newPropertiesTable instanceof CData);
        $newTable    = new HashTable(Core::addr($newPropertiesTable));
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
                $this->copyTypeInPlace($copiedPropertyType, $substitutions);

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
     */
    private function copyPropertiesInfoTable(CData $sourceEntry, CData $newEntry, array $propertyMap): void
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
     */
    private function copyConstantsTable(CData $sourceEntry, CData $newEntry): void
    {
        $this->initEmbeddedTable($sourceEntry, $newEntry, 'constants_table');
        $sourceAddress     = Core::addressOf($sourceEntry);
        $newConstantsTable = $newEntry->constants_table;
        assert($newConstantsTable instanceof CData);
        $newTable = new HashTable(Core::addr($newConstantsTable));

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
     */
    private function relinkFunctionPointers(CData $newEntry, array $functionMap): void
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
     */
    private function initEmbeddedTable(CData $sourceEntry, CData $newEntry, string $tableField): void
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
     * @param HashTable&iterable<string|null, ReflectionValue> $table Table on the copy
     */
    private function publishPointerEntry(HashTable $table, string $key, CData $pointer): void
    {
        $structureView = $pointer[0];
        assert($structureView instanceof CData);
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $structureView);
        $table->add($key, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Copies one zend_type in place on an already-memcpy'd owner structure: substitutes
     * matching single-name placeholder types, takes owned name references for kept names
     * and duplicates type lists into request memory
     */
    private function copyTypeInPlace(CData $type, TypeSubstitutionMap $substitutions): void
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
        assert($replacementName instanceof CData);
        $type->ptr = Core::cast('void *', $replacementName);
    }

    /**
     * Duplicates a union/intersection type list into request memory
     *
     * The copied list carries the ARENA ownership bit, so zend_type_release() on the
     * copy releases the contained names but never frees the block itself - it is
     * reclaimed by the request allocator, mimicking the compiler-arena lists of
     * runtime-compiled classes (and never touching a possibly shared source list).
     */
    private function copyTypeListInPlace(CData $type, TypeSubstitutionMap $substitutions): void
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
     */
    private static function addStringReference(CData $stringPointer): void
    {
        $stringEntry = StringEntry::fromCData($stringPointer);
        if (!$stringEntry->isImmutable()) {
            $stringEntry->incrementReferenceCount();
        }
    }

    /**
     * Takes one owned reference on an engine hashtable with immutable awareness
     * (zend_hash_release skips immutable tables symmetrically)
     */
    private static function addHashTableReference(CData $tablePointer): void
    {
        $table = new HashTable($tablePointer);
        if (!$table->isImmutable()) {
            $table->incrementReferenceCount();
        }
    }

    /**
     * Borrowed view over the source method table
     *
     * @return HashTable&iterable<string|null, ReflectionValue>
     */
    private function methodTable(CData $classEntry): HashTable
    {
        $tableStruct = $classEntry->function_table;
        assert($tableStruct instanceof CData);

        return new HashTable(Core::addr($tableStruct));
    }

    /**
     * Borrowed view over the source properties-info table
     *
     * @return HashTable&iterable<string|null, ReflectionValue>
     */
    private function propertiesTable(CData $classEntry): HashTable
    {
        $tableStruct = $classEntry->properties_info;
        assert($tableStruct instanceof CData);

        return new HashTable(Core::addr($tableStruct));
    }

    /**
     * Borrowed view over the source constants table
     *
     * @return HashTable&iterable<string|null, ReflectionValue>
     */
    private function constantsTable(CData $classEntry): HashTable
    {
        $tableStruct = $classEntry->constants_table;
        assert($tableStruct instanceof CData);

        return new HashTable(Core::addr($tableStruct));
    }

    /**
     * Iterates every zend_arg_info entry of a user function: the return entry (index -1
     * when a return type is declared), all declared parameters and the variadic entry
     *
     * @return iterable<CData>
     */
    private function argInfoEntries(CData $opArray): iterable
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
     */
    private function typeListEntries(CData $type): iterable
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
     */
    private static function precedenceExcludeNames(CData $precedenceEntry): CData
    {
        $excludeOffset = Core::type('zend_trait_precedence')->getStructFieldOffset('exclude_class_names');

        return Core::pointerAtAddress('zend_string **', Core::addressOf($precedenceEntry) + $excludeOffset);
    }
}
