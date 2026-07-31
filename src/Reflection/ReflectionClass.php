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
use ZEngine\ClassExtension\Hook\CastObjectHook;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\CreateObjectHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\Hook\GetPropertiesForHook;
use ZEngine\ClassExtension\Hook\GetPropertyPointerHook;
use ZEngine\ClassExtension\Hook\HasPropertyHook;
use ZEngine\ClassExtension\Hook\InterfaceGetsImplementedHook;
use ZEngine\ClassExtension\Hook\ReadPropertyHook;
use ZEngine\ClassExtension\Hook\UnsetPropertyHook;
use ZEngine\ClassExtension\Hook\WritePropertyHook;
use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesForInterface;
use ZEngine\ClassExtension\ObjectGetPropertyPointerInterface;
use ZEngine\ClassExtension\ObjectHasPropertyInterface;
use ZEngine\ClassExtension\ObjectReadPropertyInterface;
use ZEngine\ClassExtension\ObjectUnsetPropertyInterface;
use ZEngine\ClassExtension\ObjectWritePropertyInterface;
use ZEngine\Core;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

class ReflectionClass extends NativeReflectionClass
{
    /**
     * Stores the list of methods in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $methodTable;

    /**
     * Stores the list of properties in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $propertiesTable;

    /**
     * Stores the list of constants in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $constantsTable;

    /**
     * Stores the list of attributes
     *
     * @var ?HashTable|ReflectionValue[]
     */
    private ?HashTable $attributesTable;

    private CData $pointer;

    /**
     * Stores all allocated zend_object_handler pointers, keyed by zend_class_entry address
     *
     * Keying by address (and not by class name) keeps the cache bounded: anonymous classes
     * reuse name patterns while their class entries are distinct, and a name-keyed cache both
     * grew without limit and could alias a stale handlers block to an unrelated class.
     *
     * @var array<int, CData>
     */
    private static array $objectHandlers = [];

    public function __construct($classNameOrObject)
    {
        try {
            parent::__construct($classNameOrObject);
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }
        $className      = is_string($classNameOrObject) ? $classNameOrObject : get_class($classNameOrObject);
        $normalizedName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry = $classEntryValue->getRawClass();
        $this->initLowLevelStructures($classEntry);
    }

    /**
     * Creates a reflection from the zend_class_entry structure
     *
     * @param CData $classEntry Pointer to the structure
     *
     * @return ReflectionClass
     */
    public static function fromCData(CData $classEntry): ReflectionClass
    {
        /** @var ReflectionClass $reflectionClass */
        $reflectionClass = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionClass->initLowLevelStructures($classEntry);
        $classNameValue = StringEntry::fromCData($classEntry->name);
        try {
            Core::callParentConstructor($reflectionClass, static::class, $classNameValue->getStringValue());
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }

        return $reflectionClass;
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function getName()
    {
        return StringEntry::fromCData($this->pointer->name)->getStringValue();
    }

    /**
     * @inheritDoc
     */
    public function getInterfaceNames(): array
    {
        $interfaceNames = [];
        $isLinked       = (bool) ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED);
        for ($index = 0; $index < $this->pointer->num_interfaces; $index++) {
            if ($isLinked) {
                $rawInterfaceName = $this->pointer->interfaces[$index]->name;
            } else {
                $rawInterfaceName = $this->pointer->interface_names[$index]->name;
            }
            $interfaceNameValue = StringEntry::fromCData($rawInterfaceName);
            $interfaceNames[]   = $interfaceNameValue->getStringValue();
        }

        return $interfaceNames;
    }

    /**
     * Gets the interfaces
     *
     * @return ReflectionClass[] An associative array of interfaces, with keys as interface
     * names and the array values as <b>ReflectionClass</b> objects.
     */
    public function getInterfaces(): array
    {
        $interfaces = [];
        foreach ($this->getInterfaceNames() as $interfaceName) {
            $interfaces[$interfaceName] = new ReflectionClass($interfaceName);
        };

        return $interfaces;
    }

    /**
     * Adds interfaces to the current class
     *
     * @param string ...$interfaceNames Name of interfaces to add
     *
     * @see zend_inheritance.c:zend_do_implement_interface() function implementation for details
     * @internal
     */
    public function addInterfaces(string ...$interfaceNames): void
    {
        $availableInterfaces = $this->getInterfaceNames();
        $interfacesToAdd     = array_values(array_diff($interfaceNames, $availableInterfaces));
        $numInterfacesToAdd  = count($interfacesToAdd);
        $totalInterfaces     = count($availableInterfaces);
        $numResultInterfaces = $totalInterfaces + $numInterfacesToAdd;

        // Tracked non-owned memory outlives this method; persistent (malloc) only for internal
        // classes - user classes must get request memory, because destroy_zend_class() frees
        // these buffers with the request allocator when the class dies
        $isPersistent = $this->isPersistentAllocation();
        $memory       = Core::trackedNew("zend_class_entry *[$numResultInterfaces]", $isPersistent);

        $itemsSize = Core::sizeof(Core::type('zend_class_entry *'));
        if ($totalInterfaces > 0) {
            Core::memcpy($memory, $this->pointer->interfaces, $itemsSize * $totalInterfaces);
        }
        for ($position = $totalInterfaces, $index = 0; $index < $numInterfacesToAdd; $position++, $index++) {
            $interfaceName = $interfacesToAdd[$index];
            if (!interface_exists($interfaceName)) {
                throw new \ReflectionException("Interface {$interfaceName} was not found");
            }
            $classValueEntry   = Core::$executor->classTable->find(strtolower($interfaceName));
            $interfaceClass    = $classValueEntry->getRawClass();
            $memory[$position] = $interfaceClass;
        }

        // Free the previous buffer if z-engine allocated it; engine-original arrays are left alone
        if ($totalInterfaces > 0) {
            Core::untrackAndFree($this->pointer->interfaces);
        }
        $this->pointer->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));

        // We should also add ZEND_ACC_RESOLVED_INTERFACES explicitly with first interface
        if ($totalInterfaces === 0 && $numInterfacesToAdd > 0) {
            $this->pointer->ce_flags |= Core::ZEND_ACC_RESOLVED_INTERFACES;
        }
        $this->pointer->num_interfaces = $numResultInterfaces;
    }

    /**
     * Removes interfaces from the current class
     *
     * @param string ...$interfaceNames Name of interfaces to remove
     * @internal
     */
    public function removeInterfaces(string ...$interfaceNames): void
    {
        $availableInterfaces = $this->getInterfaceNames();
        $indexesToRemove     = [];
        foreach ($interfaceNames as $interfaceToRemove) {
            $interfacePosition = array_search($interfaceToRemove, $availableInterfaces, true);
            if ($interfacePosition === false) {
                throw new \ReflectionException("Interface {$interfaceToRemove} doesn't belong to the class");
            }
            $indexesToRemove[$interfacePosition] = true;
        }
        $totalInterfaces     = count($availableInterfaces);
        $numResultInterfaces = $totalInterfaces - count($indexesToRemove);

        // Tracked non-owned memory outlives this method; persistent (malloc) only for internal
        // classes - user classes must get request memory, because destroy_zend_class() frees
        // these buffers with the request allocator when the class dies
        $isPersistent = $this->isPersistentAllocation();

        // If we remove all interfaces then just clear $this->pointer->interfaces field
        if ($numResultInterfaces === 0) {
            if ($totalInterfaces > 0) {
                Core::untrackAndFree($this->pointer->interfaces);
            }
            // We should also clean ZEND_ACC_RESOLVED_INTERFACES
            $this->pointer->interfaces = null;
            $this->pointer->ce_flags &= (~ Core::ZEND_ACC_RESOLVED_INTERFACES);
        } else {
            // Allocate tracked memory, either persistent (for internal classes) or not (for user-defined)
            $memory = Core::trackedNew("zend_class_entry *[$numResultInterfaces]", $isPersistent);
            for ($index = 0, $destIndex = 0; $index < $this->pointer->num_interfaces; $index++) {
                if (!isset($indexesToRemove[$index])) {
                    $memory[$destIndex++] = $this->pointer->interfaces[$index];
                }
            }
            if ($totalInterfaces > 0) {
                Core::untrackAndFree($this->pointer->interfaces);
            }
            $this->pointer->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));
        }
        // Decrease the total number of interfaces in the class entry
        $this->pointer->num_interfaces = $numResultInterfaces;
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function getMethod($name)
    {
        $functionEntry = $this->methodTable->find(strtolower($name));
        if ($functionEntry === null) {
            throw new \ReflectionException("Method {$name} does not exist");
        }

        return ReflectionMethod::fromCData($functionEntry->getRawFunction());
    }

    /**
     * @inheritDoc
     * @return ReflectionMethod[]
     */
    #[\ReturnTypeWillChange]
    public function getMethods($filter = null)
    {
        $methods = [];
        foreach ($this->methodTable as $methodEntryValue) {
            $functionEntry = $methodEntryValue->getRawFunction();
            if (!isset($filter) || ($functionEntry->common->fn_flags & $filter)) {
                $methods[] = ReflectionMethod::fromCData($functionEntry);
            }
        }

        return $methods;
    }

    /**
     * Adds a new method to the class in runtime
     * @internal
     */
    public function addMethod(string $methodName, \Closure $method): ReflectionMethod
    {
        $closureEntry = new ClosureEntry($method);
        // This line will make this closure live until the end of script/request
        $closureEntry->getClosureObjectEntry()->incrementReferenceCount();
        $closureEntry->setCalledScope($this->name);

        // TODO: replace with ReflectionFunction instead of low-level structures
        $rawFunction  = $closureEntry->getRawFunction();
        $previousName = $rawFunction->common->function_name;
        if ($previousName !== null) {
            StringEntry::fromCData($previousName)->releaseReference();
        }
        // The function structure takes over one owned reference on its new name
        $rawFunction->common->function_name = StringEntry::fromString($methodName)
            ->transferReferenceOwnership()
            ->getRawValue();

        // Adjust the scope of our function to our class
        $classScopeValue            = Core::$executor->classTable->find(strtolower($this->name));
        $rawFunction->common->scope = $classScopeValue->getRawClass();

        // Clean closure flag
        $rawFunction->common->fn_flags &= (~Core::ZEND_ACC_CLOSURE);

        $refMethod = $this->addRawMethod($methodName, $rawFunction);
        $refMethod->setPublic();

        return $refMethod;
    }

    #[\ReturnTypeWillChange]
    public function isInternal()
    {
        return ord($this->pointer->type) === Core::ZEND_INTERNAL_CLASS;
    }

    /**
     * Selects the allocation class for buffers stored inside this class entry
     *
     * Internal classes are persistent engine structures (malloc), while user classes are
     * destroyed with the request allocator: destroy_zend_class() frees their interface and
     * trait buffers with efree(), so storing malloc memory there corrupts the heap.
     */
    private function isPersistentAllocation(): bool
    {
        return (bool) $this->isInternal();
    }

    #[\ReturnTypeWillChange]
    public function isUserDefined()
    {
        return ord($this->pointer->type) === Core::ZEND_USER_CLASS;
    }

    /**
     * Returns the raw backed-enum table of a backed enum (backing value => case name)
     *
     * The engine stores one entry per case: the key is the case backing value (a string
     * key for string-backed enums, an integer key for int-backed enums) and the value is
     * an IS_STRING zval holding the case name, exactly as zend_enum.c stored it.
     *
     * The engine materializes this table lazily: until the first Enum::from()/tryFrom()
     * call on the enum, ce->backed_enum_table stays NULL and this method returns null.
     * This is a raw read - it never triggers the materialization itself.
     *
     * Memory ownership contract (see docs/long-running.md): the returned HashTable is a
     * BORROWED view over the engine-owned ce->backed_enum_table - no addref is taken and
     * no ownership is transferred. The view and every ReflectionValue read from it stay
     * valid only while the class entry is alive; reading never changes refcounts, and
     * nothing on the PHP side may release the table or its buckets.
     *
     * @see zend_enum.c:zend_enum_build_backed_enum_table() for the table layout and laziness
     *
     * @return (HashTable&iterable<int|string|null, ReflectionValue>)|null Borrowed table for backed enums
     *         (once materialized), null for pure enums, non-enum classes and unmaterialized tables
     */
    public function getBackedEnumTable(): ?HashTable
    {
        $ceFlags = $this->pointer->ce_flags;
        if (!\is_int($ceFlags) || ($ceFlags & Core::ZEND_ACC_ENUM) === 0) {
            return null;
        }

        // Pure enums have no backing values: the engine leaves the table pointer NULL
        $rawTable = $this->pointer->backed_enum_table;
        if (!$rawTable instanceof CData) {
            return null;
        }

        return new HashTable($rawTable);
    }

    /**
     * Removes given methods from the class
     *
     * @param string ...$methodNames Name of methods to remove
     * @internal
     */
    public function removeMethods(string ...$methodNames): void
    {
        foreach ($methodNames as $methodName) {
            $this->methodTable->delete(strtolower($methodName));
        }
    }

    /**
     * Gets the traits
     *
     * @return ReflectionClass[] An associative array of traits, with keys as trait
     * names and the array values as <b>ReflectionClass</b> objects.
     */
    public function getTraits(): array
    {
        $traits = [];
        foreach ($this->getTraitNames() as $traitName) {
            $traits[$traitName] = new ReflectionClass($traitName);
        };

        return $traits;
    }

    /**
     * Adds traits to the current class
     *
     * @param string ...$traitNames Name of traits to add
     * @internal
     */
    public function addTraits(string ...$traitNames): void
    {
        $availableTraits = $this->getTraitNames();
        $traitsToAdd     = array_values(array_diff($traitNames, $availableTraits));
        $numTraitsToAdd  = count($traitsToAdd);
        $totalTraits     = count($availableTraits);
        $numResultTraits = $totalTraits + $numTraitsToAdd;

        // Tracked non-owned memory outlives this method; persistent (malloc) only for internal
        // classes - user classes must get request memory, because destroy_zend_class() frees
        // these buffers with the request allocator when the class dies
        $isPersistent = $this->isPersistentAllocation();
        $memory       = Core::trackedNew("zend_class_name [$numResultTraits]", $isPersistent);

        $itemsSize = Core::sizeof(Core::type('zend_class_name'));
        if ($totalTraits > 0) {
            Core::memcpy($memory, $this->pointer->trait_names, $itemsSize * $totalTraits);
        }
        for ($position = $totalTraits, $index = 0; $index < $numTraitsToAdd; $position++, $index++) {
            $traitName   = $traitsToAdd[$index];
            $lcTraitName = strtolower($traitName);
            // The class entry takes over one owned reference per stored name; persistent
            // class entries need malloc-backed strings the engine can release safely
            if ($isPersistent) {
                $name   = StringEntry::persistent($traitName);
                $lcName = StringEntry::persistent($lcTraitName);
            } else {
                $name   = StringEntry::fromString($traitName);
                $lcName = StringEntry::fromString($lcTraitName);
            }

            $memory[$position]->name    = $name->transferReferenceOwnership()->getRawValue();
            $memory[$position]->lc_name = $lcName->transferReferenceOwnership()->getRawValue();
        }
        // Free the previous buffer if z-engine allocated it; engine-original arrays are left alone
        if ($totalTraits > 0) {
            Core::untrackAndFree($this->pointer->trait_names);
        }

        $this->pointer->trait_names = Core::cast('zend_class_name *', Core::addr($memory));
        $this->pointer->num_traits  = $numResultTraits;
    }

    /**
     * Removes traits from the current class
     *
     * @param string ...$traitNames Name of traits to remove
     * @internal
     */
    public function removeTraits(string ...$traitNames): void
    {
        $availableTraits = $this->getTraitNames();
        $indexesToRemove = [];
        foreach ($traitNames as $traitToRemove) {
            $traitPosition = array_search($traitToRemove, $availableTraits, true);
            if ($traitPosition === false) {
                throw new \ReflectionException("Trait {$traitToRemove} doesn't belong to the class");
            }
            $indexesToRemove[$traitPosition] = true;
        }
        $totalTraits     = count($availableTraits);
        $numResultTraits = $totalTraits - count($indexesToRemove);

        // Tracked non-owned memory outlives this method; persistent (malloc) only for internal
        // classes - user classes must get request memory, because destroy_zend_class() frees
        // these buffers with the request allocator when the class dies
        $isPersistent = $this->isPersistentAllocation();

        if ($numResultTraits > 0) {
            $memory = Core::trackedNew("zend_class_name[$numResultTraits]", $isPersistent);
        } else {
            $memory = null;
        }
        for ($index = 0, $destIndex = 0; $index < $totalTraits; $index++) {
            $traitNameStruct = $this->pointer->trait_names[$index];
            if (!isset($indexesToRemove[$index])) {
                $memory[$destIndex++] = $traitNameStruct;
            } else {
                // Clean strings to prevent memory leaks
                // Drop the class entry's own reference on the removed trait names with
                // engine semantics (previously this was a wrong-allocator FFI free)
                StringEntry::fromCData($traitNameStruct->name)->releaseReference();
                StringEntry::fromCData($traitNameStruct->lc_name)->releaseReference();
            }
        }
        if ($totalTraits > 0) {
            Core::untrackAndFree($this->pointer->trait_names);
        }
        if ($numResultTraits > 0) {
            $this->pointer->trait_names = Core::cast('zend_class_name *', Core::addr($memory));
        } else {
            $this->pointer->trait_names = null;
        }
        $this->pointer->num_traits = $numResultTraits;
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function getParentClass(): ?ReflectionClass
    {
        if (!$this->hasParentClass()) {
            return null;
        }

        // For linked class we should look at parent name directly
        if ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED) {
            $rawParentName = $this->pointer->parent->name;
        } else {
            $rawParentName = $this->pointer->parent_name;
        }

        $parentNameValue = StringEntry::fromCData($rawParentName);
        $classReflection = new ReflectionClass($parentNameValue->getStringValue());

        return $classReflection;
    }

    /**
     * Removes the linked parent class from the existing class
     * @internal
     */
    public function removeParentClass(): void
    {
        if (!$this->hasParentClass()) {
            throw new \ReflectionException('Could not remove non-existent parent class');
        }
        try {
            $parentClass      = $this->getParentClass();
            $parentInterfaces = $parentClass->getInterfaceNames();
            if (count($parentInterfaces) > 0) {
                $this->removeInterfaces(...$parentInterfaces);
            }
            $methodsToRemove = [];
            foreach ($this->getMethods() as $reflectionMethod) {
                $methodClass     = $reflectionMethod->getDeclaringClass();
                $methodClassName = $methodClass->getName();
                $isParentMethod  = $parentClass->getName() === $methodClassName;
                $isGrandMethod   = $parentClass->isSubclassOf($methodClassName);

                if ($isParentMethod || $isGrandMethod) {
                    $methodsToRemove[] = $reflectionMethod->getName();
                }
            }
            if (count($methodsToRemove) > 0) {
                $this->removeMethods(...$methodsToRemove);
            }
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading (parent not loaded yet). But we ignore this error
        }
        // TODO: Detach all related constants, properties, etc...
        $this->pointer->parent = null;
    }

    /**
     * Configures a new parent class for this one
     *
     * @param string $newParent New parent class name
     * @internal
     */
    public function setParent(string $newParent)
    {
        // If this class has a parent, then we need to detach it first
        if ($this->hasParentClass()) {
            $this->removeParentClass();
        }

        // Look for the parent zend_class_entry
        $parentClassValue = Core::$executor->classTable->find(strtolower($newParent));
        if ($parentClassValue === null) {
            throw new \ReflectionException("Class {$newParent} was not found");
        }

        // Call API to reduce the boilerplate code
        Core::call('zend_do_inheritance_ex', $this->pointer, $parentClassValue->getRawClass(), 0);
    }

    /**
     * Declares this class as final/non-final
     *
     * @param bool $isFinal True to make class final/false to remove final flag
     */
    public function setFinal(bool $isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_FINAL);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_FINAL));
        }
    }

    /**
     * Declares this class as abstract/non-abstract
     *
     * @param bool $isAbstract True to make current class abstract or false to remove abstract flag
     */
    public function setAbstract(bool $isAbstract = true): void
    {
        if ($isAbstract) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS));
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_IMPLICIT_ABSTRACT_CLASS));
        }
    }


    /**
     * Sets a new start line for the class in the file
     */
    public function setStartLine(int $newStartLine): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('Line can be configured only for user-defined class');
        }
        $this->pointer->info->user->line_start = $newStartLine;
    }

    /**
     * Sets a new end line for the class in the file
     */
    public function setEndLine(int $newEndLine): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('Line can be configured only for user-defined class');
        }
        $this->pointer->info->user->line_end = $newEndLine;
    }

    /**
     * Sets a new filename for this class
     */
    public function setFileName(string $newFileName): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('File can be configured only for user-defined class');
        }
        // Release the previous filename (the class entry owned a reference on it) and store
        // an owned string whose reference is handed over to the class entry
        $previousFileName = $this->pointer->info->user->filename;
        if ($previousFileName !== null) {
            StringEntry::fromCData($previousFileName)->releaseReference();
        }
        $this->pointer->info->user->filename = StringEntry::fromString($newFileName)
            ->transferReferenceOwnership()
            ->getRawValue();
    }

    /**
     * Returns the list of default properties. Only for non-static ones
     *
     * @return iterable|ReflectionValue[]
     */
    #[\ReturnTypeWillChange]
    public function getDefaultProperties(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_properties_count) {
                $value = $this->pointer->default_properties_table[$propertyIndex];
                yield $propertyIndex => ReflectionValue::fromValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    /**
     * Returns the list of default static members. Only for static ones
     *
     * @return iterable|ReflectionValue[]
     */
    public function getDefaultStaticMembers(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_static_members_count) {
                $value = $this->pointer->default_static_members_table[$propertyIndex];
                yield $propertyIndex => ReflectionValue::fromValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    /**
     * @inheritDoc
     * @return ReflectionClassConstant
     */
    #[\ReturnTypeWillChange]
    public function getReflectionConstant($name)
    {
        $constantEntry = $this->constantsTable->find($name);
        if ($constantEntry === null) {
            throw new \ReflectionException("Constant {$name} does not exist");
        }
        $constantPtr = Core::cast('zend_class_constant *', $constantEntry->getRawPointer());

        return ReflectionClassConstant::fromCData($constantPtr, $name);
    }

    /**
     * Installs user-defined object handlers for given class to control extra-features of this class
     */
    public function installExtensionHandlers(): void
    {
        if (!$this->implementsInterface(ObjectCreateInterface::class)) {
            $str = 'Class ' . $this->name . ' should implement at least ObjectCreateInterface to setup user handlers';
            throw new \ReflectionException($str);
        }

        $handler = parent::getMethod('__init')->getClosure();
        $this->setCreateObjectHandler($handler);

        if ($this->implementsInterface(ObjectCastInterface::class)) {
            $handler = parent::getMethod('__cast')->getClosure();
            $this->setCastObjectHandler($handler);
        }

        if ($this->implementsInterface(ObjectDoOperationInterface::class)) {
            $handler = parent::getMethod('__doOperation')->getClosure();
            $this->setDoOperationHandler($handler);
        }

        if ($this->implementsInterface(ObjectCompareValuesInterface::class)) {
            $handler = parent::getMethod('__compare')->getClosure();
            $this->setCompareValuesHandler($handler);
        }

        if ($this->implementsInterface(ObjectReadPropertyInterface::class)) {
            $handler = parent::getMethod('__fieldRead')->getClosure();
            $this->setReadPropertyHandler($handler);
        }

        if ($this->implementsInterface(ObjectWritePropertyInterface::class)) {
            $handler = parent::getMethod('__fieldWrite')->getClosure();
            $this->setWritePropertyHandler($handler);
        }

        if ($this->implementsInterface(ObjectGetPropertyPointerInterface::class)) {
            $handler = parent::getMethod('__fieldPointer')->getClosure();
            $this->setGetPropertyPointerHandler($handler);
        }

        if ($this->implementsInterface(ObjectHasPropertyInterface::class)) {
            $handler = parent::getMethod('__fieldIsset')->getClosure();
            $this->setHasPropertyHandler($handler);
        }

        if ($this->implementsInterface(ObjectUnsetPropertyInterface::class)) {
            $handler = parent::getMethod('__fieldUnset')->getClosure();
            $this->setUnsetPropertyHandler($handler);
        }

        if ($this->implementsInterface(ObjectGetPropertiesForInterface::class)) {
            $handler = parent::getMethod('__getFields')->getClosure();
            $this->setGetPropertiesForHandler($handler);
        }
    }

    public function __debugInfo()
    {
        return [
            'name' => $this->getName(),
        ];
    }

    /**
     * Installs the cast_object handler for current class
     *
     * @param Closure $handler Callback function (object $instance, int $typeTo): mixed;
     *
     * @see ObjectCastInterface
     */
    public function setCastObjectHandler(Closure $handler): CastObjectHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new CastObjectHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the compare handler for current class
     *
     * @param Closure $handler Callback function ($left, $right): int;
     *
     * @see ObjectCompareValuesInterface
     */
    public function setCompareValuesHandler(Closure $handler): CompareValuesHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new CompareValuesHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "read_property" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, string $fieldName, int $type): mixed;
     *
     * @see ObjectReadPropertyInterface
     */
    public function setReadPropertyHandler(Closure $handler): ReadPropertyHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new ReadPropertyHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "write_property" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, string $fieldName, $value): mixed;
     *
     * @see ObjectWritePropertyInterface
     */
    public function setWritePropertyHandler(Closure $handler): WritePropertyHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new WritePropertyHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "unset_property" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, string $fieldName): void;
     *
     * @see ObjectUnsetPropertyInterface
     */
    public function setUnsetPropertyHandler(Closure $handler): UnsetPropertyHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new UnsetPropertyHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "has_property" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, string $fieldName, int $type): int;
     *
     * @see ObjectHasPropertyInterface
     */
    public function setHasPropertyHandler(Closure $handler): HasPropertyHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new HasPropertyHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "get_property_ptr_ptr" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, string $fieldName, int $type): mixed;
     *
     * @see ObjectGetPropertyPointerInterface
     */
    public function setGetPropertyPointerHandler(Closure $handler): GetPropertyPointerHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new GetPropertyPointerHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "get_properties_for" handler for the current class
     *
     * @param Closure $handler Callback function (object $instance, int $reason): array;
     *
     * @see ObjectGetPropertiesForInterface
     */
    public function setGetPropertiesForHandler(Closure $handler): GetPropertiesForHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new GetPropertiesForHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the do_operation handler for current class
     *
     * @param Closure $handler Callback function (object $instance, int $typeTo);
     *
     * @see ObjectDoOperationInterface
     */
    public function setDoOperationHandler(Closure $handler): DoOperationHook
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new DoOperationHook($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the create_object handler, this handler is required for all other handlers
     *
     * @param Closure $handler Callback function (CData $classType, Closure $initializer): CData
     *
     * @see ObjectCreateInterface
     */
    public function setCreateObjectHandler(Closure $handler): CreateObjectHook
    {
        // User handlers are only allowed with std_object_handler (when create_object handler is empty)
        if ($this->isInternal()) {
            trigger_error('Create object handler is available for user-defined classes only', E_USER_ERROR);
        }
        self::getObjectHandlers($this->pointer);

        $hook = new CreateObjectHook($handler, $this->pointer);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the handler when another class implements current interface
     *
     * @param Closure $handler Callback function (ReflectionClass $reflectionClass)
     */
    public function setInterfaceGetsImplementedHandler(Closure $handler): InterfaceGetsImplementedHook
    {
        if (!$this->isInterface()) {
            throw new \LogicException('Interface implemented handler can be installed only for interfaces');
        }

        $hook = new InterfaceGetsImplementedHook($handler, $this->pointer);
        $hook->install();

        return $hook;
    }

    /**
     * Creates a new instance of zend_object.
     *
     * This method is useful within create_object handler
     *
     * @param CData $classType zend_class_entry type to create
     * @param bool $persistent Whether object should be allocated persistent or not. Low-level feature!
     *
     * @return CData Instance of zend_object *
     * @see zend_objects.c:zend_objects_new
     */
    public static function newInstanceRaw(CData $classType, bool $persistent = false): CData
    {
        $objectSize = Core::sizeof(Core::type('zend_object'));
        $totalSize  = $objectSize + self::getObjectPropertiesSize($classType);
        $memory     = Core::new("char[{$totalSize}]", false, $persistent);
        $object     = Core::cast('zend_object *', $memory);

        Core::call('zend_object_std_init', $object, $classType);
        $object->handlers = self::getObjectHandlers($classType);
        Core::call('object_properties_init', $object, $classType);

        return $object;
    }

    /**
     * Checks if the current class has a parent
     */
    private function hasParentClass(): bool
    {
        return $this->pointer->parent_name !== null;
    }

    /**
     * Performs low-level initialization of fields
     *
     * @param CData $classEntry
     */
    private function initLowLevelStructures(CData $classEntry): void
    {
        $this->pointer         = $classEntry;
        $this->methodTable     = new HashTable(Core::addr($classEntry->function_table));
        $this->propertiesTable = new HashTable(Core::addr($classEntry->properties_info));
        $this->constantsTable  = new HashTable(Core::addr($classEntry->constants_table));
        if ($classEntry->attributes !== null) {
            $this->attributesTable = new HashTable(Core::addr($classEntry->attributes));
        }
    }

    /**
     * Adds a low-level function(method) to the class
     *
     * @param string $methodName Method name to use
     * @param CData  $rawFunction zend_function instance
     *
     * @return ReflectionMethod
     */
    private function addRawMethod(string $methodName, CData $rawFunction): ReflectionMethod
    {
        // The engine hashtable copies the zval into its own bucket, so the temporary
        // container exists only for the duration of this call and must be freed here
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $this->methodTable->add(strtolower($methodName), $valueEntry);
        $valueEntry->release();

        $refMethod = ReflectionMethod::fromCData($rawFunction);

        return $refMethod;
    }

    /**
     * Returns the size of memory required for storing properties for a given class type
     *
     * @param CData $classType zend_class_entry type to get object property size
     *
     * @see zend_objects_API.h:zend_object_properties_size
     */
    private static function getObjectPropertiesSize(CData $classType): int
    {
        $zvalSize  = Core::sizeof(Core::type('zval'));
        $useGuards = (bool) ($classType->ce_flags & Core::ZEND_ACC_USE_GUARDS);

        $totalSize = $zvalSize * ($classType->default_properties_count - ($useGuards ? 0 : 1));

        return $totalSize;
    }

    /**
     * Returns a pointer to the zend_object_handlers for given zend_class_entry
     *
     * We always create our own object handlers structure to have an ability to adjust callbacks in runtime,
     * otherwise it is impossible because object handlers field is declared as "const"
     *
     * @param CData $classType zend_class_entry type to get object handlers
     */
    private static function getObjectHandlers(CData $classType): CData
    {
        $classEntryAddress = Core::addressOf($classType);
        if (!isset(self::$objectHandlers[$classEntryAddress])) {
            self::$objectHandlers[$classEntryAddress] = self::allocateClassObjectHandlers();
        }

        return self::$objectHandlers[$classEntryAddress];
    }

    /**
     * Allocates a new zend_object_handlers structure for a class as a copy of std_object_handlers
     */
    private static function allocateClassObjectHandlers(): CData
    {
        $handlers    = Core::trackedNew('zend_object_handlers', true);
        $stdHandlers = Core::getStandardObjectHandlers();
        Core::memcpy($handlers, $stdHandlers, Core::sizeof($stdHandlers));

        return Core::addr($handlers);
    }
}
