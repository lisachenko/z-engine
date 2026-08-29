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
use ZEngine\ClassExtension\Hook\CloneObjectHook;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\CountElementsHook;
use ZEngine\ClassExtension\Hook\CreateObjectHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\Hook\GetClassNameHook;
use ZEngine\ClassExtension\Hook\GetClosureHook;
use ZEngine\ClassExtension\Hook\GetConstructorHook;
use ZEngine\ClassExtension\Hook\GetDebugInfoHook;
use ZEngine\ClassExtension\Hook\GetIteratorHook;
use ZEngine\ClassExtension\Hook\GetMethodHook;
use ZEngine\ClassExtension\Hook\GetPropertiesForHook;
use ZEngine\ClassExtension\Hook\GetPropertiesHook;
use ZEngine\ClassExtension\Hook\GetPropertyPointerHook;
use ZEngine\ClassExtension\Hook\HasDimensionHook;
use ZEngine\ClassExtension\Hook\HasPropertyHook;
use ZEngine\ClassExtension\Hook\InterfaceGetsImplementedHook;
use ZEngine\ClassExtension\Hook\ReadDimensionHook;
use ZEngine\ClassExtension\Hook\ReadPropertyHook;
use ZEngine\ClassExtension\Hook\UnsetDimensionHook;
use ZEngine\ClassExtension\Hook\UnsetPropertyHook;
use ZEngine\ClassExtension\Hook\WriteDimensionHook;
use ZEngine\ClassExtension\Hook\WritePropertyHook;
use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCloneInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCountElementsInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\ClassExtension\ObjectGetClassNameInterface;
use ZEngine\ClassExtension\ObjectGetClosureInterface;
use ZEngine\ClassExtension\ObjectGetConstructorInterface;
use ZEngine\ClassExtension\ObjectGetDebugInfoInterface;
use ZEngine\ClassExtension\ObjectGetIteratorInterface;
use ZEngine\ClassExtension\ObjectGetMethodInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesForInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesInterface;
use ZEngine\ClassExtension\ObjectGetPropertyPointerInterface;
use ZEngine\ClassExtension\ObjectHasDimensionInterface;
use ZEngine\ClassExtension\ObjectHasPropertyInterface;
use ZEngine\ClassExtension\ObjectReadDimensionInterface;
use ZEngine\ClassExtension\ObjectReadPropertyInterface;
use ZEngine\ClassExtension\ObjectUnsetDimensionInterface;
use ZEngine\ClassExtension\ObjectUnsetPropertyInterface;
use ZEngine\ClassExtension\ObjectWriteDimensionInterface;
use ZEngine\ClassExtension\ObjectWritePropertyInterface;
use ZEngine\Core;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_class_name;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_trait_precedence;
use ZEngine\Generated\zval;
use ZEngine\Hook\AbstractHook;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

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

    /**
     * @var zend_class_entry Typed view of the wrapped class entry; the runtime value is
     *                       the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

    /**
     * Function pointers in zend_class_entry that are grafted from the parent during inheritance
     * and therefore must be detached together with the parent methods
     */
    private const INHERITED_FUNCTION_POINTERS = [
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
     * Class-extension hook registry: interface => [magic method that implements it, installer]
     *
     * The map is the single source of truth for installExtensionHandlers(): registration
     * happens in insertion order, and ObjectCreateInterface comes first because the
     * create_object handler is the mandatory prerequisite of every other one. Supporting a
     * new hook interface is one line here, nothing else.
     *
     * @var array<class-string, array{string, string}>
     */
    private const EXTENSION_HANDLERS = [
        ObjectCreateInterface::class             => ['__init', 'setCreateObjectHandler'],
        ObjectCastInterface::class               => ['__cast', 'setCastObjectHandler'],
        ObjectDoOperationInterface::class        => ['__doOperation', 'setDoOperationHandler'],
        ObjectCompareValuesInterface::class      => ['__compare', 'setCompareValuesHandler'],
        ObjectReadPropertyInterface::class       => ['__fieldRead', 'setReadPropertyHandler'],
        ObjectWritePropertyInterface::class      => ['__fieldWrite', 'setWritePropertyHandler'],
        ObjectGetPropertyPointerInterface::class => ['__fieldPointer', 'setGetPropertyPointerHandler'],
        ObjectHasPropertyInterface::class        => ['__fieldIsset', 'setHasPropertyHandler'],
        ObjectUnsetPropertyInterface::class      => ['__fieldUnset', 'setUnsetPropertyHandler'],
        ObjectGetPropertiesForInterface::class   => ['__getFields', 'setGetPropertiesForHandler'],
        ObjectGetDebugInfoInterface::class       => ['__getDebugInfo', 'setGetDebugInfoHandler'],
        ObjectCloneInterface::class              => ['__cloneObject', 'setCloneObjectHandler'],
        ObjectReadDimensionInterface::class      => ['__dimensionRead', 'setReadDimensionHandler'],
        ObjectWriteDimensionInterface::class     => ['__dimensionWrite', 'setWriteDimensionHandler'],
        ObjectHasDimensionInterface::class       => ['__dimensionHas', 'setHasDimensionHandler'],
        ObjectUnsetDimensionInterface::class     => ['__dimensionUnset', 'setUnsetDimensionHandler'],
        ObjectCountElementsInterface::class      => ['__count', 'setCountElementsHandler'],
        ObjectGetClassNameInterface::class       => ['__getClassName', 'setGetClassNameHandler'],
        ObjectGetConstructorInterface::class     => ['__getConstructor', 'setGetConstructorHandler'],
        ObjectGetPropertiesInterface::class      => ['__getProperties', 'setGetPropertiesHandler'],
        ObjectGetClosureInterface::class         => ['__getClosure', 'setGetClosureHandler'],
        ObjectGetMethodInterface::class          => ['__getMethod', 'setGetMethodHandler'],
        ObjectGetIteratorInterface::class        => ['__getIterator', 'setGetIteratorHandler'],
    ];


    /**
     * Remembers the slot capacity of the engine-allocated properties_info_table per class
     *
     * The table is arena-allocated by the engine during linking, so it can only be compacted
     * or refilled in place, never grown. removeParentClass() records the capacity here and
     * setParent() consults it to refuse a re-link that would not fit (keyed by
     * zend_class_entry address, like the object handlers cache below).
     *
     * @var array<int, int>
     */
    private static array $propertyTableCapacity = [];

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
     * @param CData|zend_class_entry $classEntry Pointer to the structure
     *
     * @return ReflectionClass
     */
    public static function fromCData(object $classEntry): ReflectionClass
    {
        /** @var ReflectionClass $reflectionClass */
        $reflectionClass = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_class_entry $classEntry Narrowed to the stub view at the owning boundary */
        $reflectionClass->initLowLevelStructures($classEntry);
        $className = $classEntry->name;
        assert($className !== null);
        $classNameValue = StringEntry::fromCData($className);
        try {
            Core::callParentConstructor($reflectionClass, static::class, $classNameValue->getStringValue());
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }

        return $reflectionClass;
    }

    /**
     * Looks a class up in the engine class table WITHOUT autoloading it
     *
     * The named public form of the EG(class_table) lookup: the engine-global wrappers behind
     * Core::$executor are core-layer state and not a consumer API (AGENTS.md), so a package
     * asking "is this class already in the engine, and which entry is it?" asks here.
     *
     * Unlike the constructor (and unlike `new \ReflectionClass($name)`) this neither triggers
     * the autoloader nor throws for a class the engine does not know: a miss is null, which is
     * what makes it usable as a probe before re-attaching data recorded for that class name.
     *
     * Names are matched the way the engine keys its table, lowercased.
     */
    public static function fromClassTable(string $className): ?ReflectionClass
    {
        $classEntryValue = Core::$executor->classTable->find(strtolower($className));
        if ($classEntryValue === null) {
            return null;
        }

        return static::fromCData($classEntryValue->getRawClass());
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    #[\Override]
    public function getName()
    {
        $className = $this->pointer->name;
        // Engine invariant: every registered class entry carries a name
        assert($className !== null);

        return StringEntry::fromCData($className)->getStringValue();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInterfaceNames(): array
    {
        $interfaceNames  = [];
        $isLinked        = (bool) ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED);
        $totalInterfaces = $this->pointer->num_interfaces;
        // A non-zero interface count guarantees the matching table pointer is set
        $resolvedTable = $this->pointer->interfaces;
        $namesTable    = $this->pointer->interface_names;
        $namesArray    = $namesTable !== null ? new StructArray($namesTable, $totalInterfaces) : null;
        for ($index = 0; $index < $totalInterfaces; $index++) {
            if ($isLinked) {
                assert($resolvedTable !== null);
                $rawInterfaceName = $resolvedTable[$index]->name;
                assert($rawInterfaceName instanceof CData);
            } else {
                assert($namesArray !== null);
                $rawInterfaceName = $namesArray[$index]->name;
                assert($rawInterfaceName !== null);
            }
            $interfaceNames[] = StringEntry::fromCData($rawInterfaceName)->getStringValue();
        }

        return $interfaceNames;
    }

    /**
     * Gets the interfaces
     *
     * @return ReflectionClass[] An associative array of interfaces, with keys as interface
     * names and the array values as <b>ReflectionClass</b> objects.
     */
    #[\Override]
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

        $itemsSize = Core::sizeOfType('zend_class_entry *');
        if ($totalInterfaces > 0) {
            Core::memcpy($memory, $this->pointer->interfaces, $itemsSize * $totalInterfaces);
        }
        for ($position = $totalInterfaces, $index = 0; $index < $numInterfacesToAdd; $position++, $index++) {
            $interfaceName = $interfacesToAdd[$index];
            if (!interface_exists($interfaceName)) {
                throw new \ReflectionException("Interface {$interfaceName} was not found");
            }
            $classValueEntry = Core::$executor->classTable->find(strtolower($interfaceName));
            if ($classValueEntry === null) {
                throw new \ReflectionException("Interface {$interfaceName} was not found in the engine");
            }
            $interfaceClass    = $classValueEntry->getRawClass();
            $memory[$position] = $interfaceClass;
        }

        // Free the previous buffer if z-engine allocated it; engine-original arrays are left alone
        if ($totalInterfaces > 0) {
            $previousTable = $this->pointer->interfaces;
            assert($previousTable !== null);
            Core::untrackAndFree($previousTable);
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
        $previousTable = $this->pointer->interfaces;
        if ($numResultInterfaces === 0) {
            if ($totalInterfaces > 0) {
                assert($previousTable !== null);
                Core::untrackAndFree($previousTable);
            }
            // We should also clean ZEND_ACC_RESOLVED_INTERFACES
            $this->pointer->interfaces = null;
            $this->pointer->ce_flags &= (~ Core::ZEND_ACC_RESOLVED_INTERFACES);
        } else {
            assert($previousTable !== null);
            // Allocate tracked memory, either persistent (for internal classes) or not (for user-defined)
            $memory = Core::trackedNew("zend_class_entry *[$numResultInterfaces]", $isPersistent);
            for ($index = 0, $destIndex = 0; $index < $this->pointer->num_interfaces; $index++) {
                if (!isset($indexesToRemove[$index])) {
                    $memory[$destIndex++] = $previousTable[$index];
                }
            }
            if ($totalInterfaces > 0) {
                Core::untrackAndFree($previousTable);
            }
            $this->pointer->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));
        }
        // Decrease the total number of interfaces in the class entry
        $this->pointer->num_interfaces = $numResultInterfaces;
    }

    /**
     * @inheritDoc
     *
     * @return ReflectionMethod
     */
    #[\ReturnTypeWillChange]
    #[\Override]
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
     *
     * Reads the low-level method table, so it also resolves methods that live only as
     * structures in memory (dynamic/hot-swap entries not registered natively).
     */
    #[\ReturnTypeWillChange]
    #[\Override]
    public function hasMethod($name)
    {
        return $this->methodTable->find(strtolower($name)) !== null;
    }

    /**
     * @inheritDoc
     * @return ReflectionMethod[]
     */
    #[\ReturnTypeWillChange]
    #[\Override]
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
     * Returns the engine attributes table of this class or null if the class has no attributes
     *
     * Each element of the returned table is an IS_PTR value pointing to a zend_attribute:
     * wrap it with ReflectionAttributeEntry::fromValueEntry() for structured access.
     *
     * @return HashTable|ReflectionValue[]|null
     */
    public function getAttributesTable(): ?HashTable
    {
        return $this->attributesTable;
    }

    /**
     * Adds a new method to the class in runtime
     *
     * Rejected for opcache-shared (immutable) classes: the method table lives inside
     * the SHM class entry and cannot be written per-process (see docs/hot-swap.md).
     *
     * @internal
     */
    public function addMethod(string $methodName, \Closure $method): ReflectionMethod
    {
        $this->ensureWritableEntry('add a method');

        $closureEntry = new ClosureEntry($method);
        // This line will make this closure live until the end of script/request
        $closureEntry->getClosureObjectEntry()->incrementReferenceCount();
        $closureEntry->setCalledScope($this->name);

        // Bind the closure-backed zend_function to this class through the reflection
        // wrappers: renaming, scope binding and closure-flag surgery live in the
        // ReflectionMethod/FunctionLikeTrait API instead of hand-written field writes
        $boundMethod = ReflectionMethod::fromClosureEntry($closureEntry, $this->name, $methodName);
        $boundMethod->setPublic();

        // Publish the function in the method table and re-wrap it so the returned
        // reflection is backed by fully-initialized native reflection state
        return $this->addRawMethod($methodName, $closureEntry->getRawFunction());
    }

    #[\ReturnTypeWillChange]
    #[\Override]
    public function isInternal()
    {
        // Since PHP 8.6 ce->type is a uint8-typed enum (zend_class_type), not a char
        return $this->pointer->type === Core::ZEND_INTERNAL_CLASS;
    }

    /**
     * Deep-clones this (linked, userland) class under a new runtime name, applying the
     * given type substitutions to the copy, and registers the result in the engine class
     * table as a first-class, instantiable class.
     *
     * The specialized class is a SIBLING of this one (same parent, same interfaces), not
     * a subclass: instances of the copy are not instanceof the template. See
     * docs/class-specialization.md for the copy model, the support matrix and the
     * memory-ownership contract; unsupported sources fail with
     * ClassSpecializationException before any engine state is modified.
     *
     * @param string                   $newClassName      Fully-qualified name for the specialized copy
     * @param TypeSubstitutionMap|null $substitutions     Placeholder-to-type substitutions (optional)
     * @param SlotSubstitutionMap|null $slotSubstitutions Slot-addressed substitutions, which also reach
     *                                                    builtin-typed slots such as `mixed` (optional)
     */
    public function specialize(
        string $newClassName,
        ?TypeSubstitutionMap $substitutions = null,
        ?SlotSubstitutionMap $slotSubstitutions = null,
    ): ReflectionClass {
        return (new ClassSpecializer())->specialize(
            $this->getName(),
            $newClassName,
            $substitutions,
            $slotSubstitutions,
        );
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
    #[\Override]
    public function isUserDefined()
    {
        // Since PHP 8.6 ce->type is a uint8-typed enum (zend_class_type), not a char
        return $this->pointer->type === Core::ZEND_USER_CLASS;
    }

    /**
     * Checks if this class entry lives in opcache shared memory (ZEND_ACC_IMMUTABLE)
     *
     * Opcache marks every class it publishes from its shared memory as immutable:
     * such an entry is visible to all worker processes and must never be written or
     * freed in place, so every class-level mutation API rejects it (see the support
     * matrix in docs/hot-swap.md).
     */
    public function isImmutable(): bool
    {
        return ($this->getFlags() & Core::ZEND_ACC_IMMUTABLE) !== 0;
    }

    /**
     * Checks if this class entry is the temporary copy opcache links classes on
     *
     * Under opcache, a class declared in a cached script is linked on a temporary
     * mutable copy of the shared-memory entry: zend_lazy_class_load() stamps
     * ZEND_ACC_CACHED on the copy while ZEND_ACC_LINKED is still clear, and once
     * linking completes the inheritance cache persists the linked result and discards
     * the temporary. Only engine callbacks that fire mid-linking (the
     * interface_gets_implemented hook) can ever observe this state - and anything
     * keyed to the temporary's address, like installed object handlers, dies with it
     * (issue #238). ZEND_ACC_CACHED is set by no non-opcache code path, so a true
     * result implies opcache; with opcache off this is always false.
     */
    public function isLazyLinkingCopy(): bool
    {
        $classFlags = $this->getFlags();

        return ($classFlags & Core::ZEND_ACC_CACHED) !== 0
            && ($classFlags & Core::ZEND_ACC_LINKED) === 0;
    }

    /**
     * Whether this class entry came from an opcache preload region
     *
     * A preloaded entry is shared memory that is republished into every request of the worker
     * process rather than rebuilt, so - unlike an ordinary immutable entry, which can be copied
     * out per request - its class-table bucket outlives any request-memory replacement put in
     * its place. That makes it the one shape neither copy-out nor eviction may touch.
     */
    public function isPreloaded(): bool
    {
        return ($this->getFlags() & Core::ZEND_ACC_PRELOADED) !== 0;
    }

    /**
     * Copies this opcache-shared (immutable) class entry out of shared memory and rebinds
     * this reflection to the writable per-process copy
     *
     * Shared memory is visible to every worker process, so a mutation API can not write the
     * class entry opcache published. Instead the whole class is deep-copied into request
     * memory (the ClassSpecializer copy model: own tables, own property/constant blocks,
     * method entries duplicated at the zend_op_array level with the compiled bodies still
     * shared), the per-process class-table bucket is repointed at the copy and the
     * shared-memory original is left byte-for-byte untouched. From that moment on the class
     * behaves like any runtime-declared class: it carries the full mutation surface and the
     * engine dismantles it at request end.
     *
     * Two consequences follow from repointing a bucket and are the caller's responsibility
     * (see docs/hot-swap.md):
     *
     *  - Structures that captured the shared-memory entry BEFORE the copy-out keep pointing
     *    at it: objects instantiated earlier, subclasses already linked against it and
     *    warmed-up inline caches. Copy out (or mutate) before such state exists - at
     *    bootstrap - exactly like every other z-engine class mutation.
     *  - Static properties materialized before the copy-out stay with the shared-memory
     *    entry: the copy re-materializes its statics from the declared defaults.
     *
     * Calling this on a class that is not opcache-shared is a no-op.
     *
     * @throws SharedMemoryException When this class cannot be copied out of shared memory
     *
     * @internal called by the mutation APIs; also useful to make the class writable up front
     */
    public function copyOutOfSharedMemory(): void
    {
        if (!$this->isImmutable()) {
            return;
        }
        $className = $this->getName();
        try {
            $writableClass = (new ClassSpecializer())->copyOutOfSharedMemory($className);
        } catch (ClassSpecializationException $copyFailure) {
            throw SharedMemoryException::classCopyOutFailed($className, $copyFailure);
        }
        $this->initLowLevelStructures($writableClass->getRawValue());

        // Native reflection resolves (and caches) the class entry at construction time, so
        // the inherited API would keep reading the shared-memory entry without this refresh
        try {
            Core::callParentConstructor($this, self::class, $className);
        } catch (\ReflectionException $e) {
            // Same tolerance as the constructor: the low-level view is enough to keep working
        }
    }

    /**
     * Returns the raw zend_class_entry pointer this reflection wraps
     *
     * The pointer is a live view into engine memory; prefer the typed accessors on
     * this class over poking fields on the result.
     *
     * @internal shared with the hot-swap machinery (ClassDelta/HotSwap)
     */
    /**
     * @return zend_class_entry
     */
    public function getRawValue(): object
    {
        return $this->pointer;
    }

    /**
     * Returns the numeric address of the underlying class entry, for identity checks
     */
    public function getAddress(): int
    {
        return Core::addressOf($this->pointer);
    }

    /**
     * Returns the ZEND_ACC_* flags word of this class entry
     */
    public function getFlags(): int
    {
        $flags = $this->pointer->ce_flags;
        assert(is_int($flags));

        return $flags;
    }

    /**
     * Returns the borrowed view of this class method table (zend_function entries)
     *
     * @return HashTable|ReflectionValue[]
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getMethodTable(): HashTable
    {
        return $this->methodTable;
    }

    /**
     * Returns the borrowed view of this class constants table (zend_class_constant entries)
     *
     * @return HashTable|ReflectionValue[]
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getConstantsTable(): HashTable
    {
        return $this->constantsTable;
    }

    /**
     * Returns the user methods the class declares itself, keyed by lowercased name
     *
     * Reads the low-level method table, so it also sees dynamic/non-persistent methods
     * that live only as structures in memory; inherited entries (whose scope is another
     * class) are excluded. Each value is a pointer-level ReflectionMethod.
     *
     * @return array<string, ReflectionMethod>
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getDeclaredMethods(): array
    {
        $declaredMethods = [];
        $selfAddress     = $this->getAddress();
        foreach ($this->methodTable as $methodName => $methodEntryValue) {
            assert(is_string($methodName));
            $method = ReflectionMethod::fromRawEntry($methodEntryValue->getRawFunction());
            if (!$method->isUserDefined()) {
                continue;
            }
            $scope = $method->getCommonPointer()->scope;
            if ($scope === null || Core::addressOf($scope) !== $selfAddress) {
                continue;
            }
            $declaredMethods[$methodName] = $method;
        }

        return $declaredMethods;
    }

    /**
     * Returns a single declared/inherited constant by name, or null when not present
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function findConstant(string $name): ?ReflectionClassConstant
    {
        $constantValue = $this->constantsTable->find($name);
        if ($constantValue === null) {
            return null;
        }

        return ReflectionClassConstant::fromRawEntry(
            Core::cast('zend_class_constant *', $constantValue->getRawPointer()),
        );
    }

    /**
     * Returns the constants the class declares itself, keyed by name
     *
     * Reads the low-level constants table (dynamic constants included); inherited
     * constants (declared by another class) are excluded. Each value is a
     * pointer-level ReflectionClassConstant.
     *
     * @return array<string, ReflectionClassConstant>
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getDeclaredConstants(): array
    {
        $declaredConstants = [];
        $selfAddress       = $this->getAddress();
        foreach ($this->constantsTable as $constantName => $constantValue) {
            assert(is_string($constantName));
            $constant = ReflectionClassConstant::fromRawEntry(
                Core::cast('zend_class_constant *', $constantValue->getRawPointer()),
            );
            if ($constant->getDeclaringClass()->getAddress() === $selfAddress) {
                $declaredConstants[$constantName] = $constant;
            }
        }

        return $declaredConstants;
    }

    /**
     * Returns the properties the class declares itself, keyed by name
     *
     * Reads the low-level properties_info table; inherited properties are excluded.
     * Each value is a pointer-level ReflectionProperty.
     *
     * @return array<string, ReflectionProperty>
     *
     * @internal shared with the hot-swap machinery (HotSwap/ClassDelta)
     */
    public function getDeclaredProperties(): array
    {
        $declaredProperties = [];
        $selfAddress        = $this->getAddress();
        foreach ($this->propertiesTable as $propertyName => $propertyValue) {
            assert(is_string($propertyName));
            $property = ReflectionProperty::fromRawEntry(
                Core::cast('zend_property_info *', $propertyValue->getRawPointer()),
            );
            if ($property->getDeclaringClass()->getAddress() === $selfAddress) {
                $declaredProperties[$propertyName] = $property;
            }
        }

        return $declaredProperties;
    }

    /**
     * Resolves the default-value zval slot of an instance property as a ReflectionValue
     *
     * The slot index is derived from the property storage offset; the returned wrapper
     * is a live view into default_properties_table.
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getDefaultPropertyValueOf(ReflectionProperty $property): ReflectionValue
    {
        $zvalSize = Core::sizeOfType(zval::class);
        $slotBase = Core::type('zend_object')->getStructFieldOffset('properties_table');
        $slot     = intdiv($property->getOffset() - $slotBase, $zvalSize);
        $table    = $this->pointer->default_properties_table;
        $count    = $this->pointer->default_properties_count;
        assert($table instanceof CData && is_int($count));

        return ReflectionValue::fromValueEntry((new StructArray($table, $count))[$slot]);
    }

    /**
     * Resolves the default-value zval slot of a static property as a ReflectionValue
     *
     * The slot index for static members is the property offset itself; the returned
     * wrapper is a live view into default_static_members_table.
     *
     * @internal shared with the hot-swap machinery (ClassDelta)
     */
    public function getDefaultStaticValueOf(ReflectionProperty $property): ReflectionValue
    {
        $slot  = $property->getOffset();
        $table = $this->pointer->default_static_members_table;
        $count = $this->pointer->default_static_members_count;
        assert($table instanceof CData && is_int($count));

        return ReflectionValue::fromValueEntry((new StructArray($table, $count))[$slot]);
    }

    /**
     * Drops the ZEND_ACC_CONSTANTS_UPDATED shortcut so the engine re-evaluates constant
     * expressions lazily, and returns the previous ce_flags word for restoration
     *
     * @internal used by the hot-swap machinery after changing constants/defaults
     */
    public function invalidateConstants(): int
    {
        $previousFlags           = $this->getFlags();
        $this->pointer->ce_flags = $previousFlags & ~Core::ZEND_ACC_CONSTANTS_UPDATED;

        return $previousFlags;
    }

    /**
     * Restores the ce_flags word saved by invalidateConstants()
     *
     * @internal rollback helper
     */
    public function restoreFlags(int $flags): void
    {
        $this->pointer->ce_flags = $flags;
    }

    /**
     * Marks the class entry as declaring at least one method with static variables
     *
     * The engine's shutdown walk destroys per-method live static tables only for classes
     * carrying this flag; a body swapped in with static variables must set it or the
     * materialized table leaks at request end.
     *
     * @internal used by the body-swap machinery (FunctionBodySwap)
     */
    public function markHasStaticInMethods(): void
    {
        $this->pointer->ce_flags = $this->getFlags() | Core::engineConstant('ZEND_HAS_STATIC_IN_METHODS');
    }

    /**
     * Returns the magic shortcut field name the class entry references the given method
     * through (constructor/destructor/__get/...), or null when the method is not wired
     * as a magic slot
     *
     * @internal used by ReflectionMethod::isRemovable()
     */
    public function getMagicSlotFor(ReflectionMethod $method): ?string
    {
        $methodAddress = $method->getAddress();

        return array_find(
            self::INHERITED_FUNCTION_POINTERS,
            function (string $fieldName) use ($methodAddress): bool {
                $fieldFunction = $this->pointer->{$fieldName};
                if ($fieldFunction === null) {
                    return false;
                }
                assert($fieldFunction instanceof CData);

                return Core::addressOf($fieldFunction) === $methodAddress;
            },
        );
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
     * @return (HashTable&iterable<int|string, ReflectionValue>)|null Borrowed table for backed enums
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

        return HashTable::fromCData($rawTable);
    }

    /**
     * Removes given methods from the class
     *
     * @param string ...$methodNames Name of methods to remove
     * @internal
     */
    public function removeMethods(string ...$methodNames): void
    {
        $this->ensureWritableEntry('remove methods');
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
    #[\Override]
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

        $itemsSize = Core::sizeOfType(zend_class_name::class);
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
            $previousNames = $this->pointer->trait_names;
            assert($previousNames !== null);
            Core::untrackAndFree($previousNames);
        }

        $this->pointer->trait_names = Core::cast(zend_class_name::class, Core::addr($memory));
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
        $previousNames = $this->pointer->trait_names;
        assert($previousNames !== null);
        $previousNamesArray = new StructArray($previousNames, $totalTraits);
        for ($index = 0, $destIndex = 0; $index < $totalTraits; $index++) {
            $traitNameStruct = $previousNamesArray[$index];
            if (!isset($indexesToRemove[$index])) {
                assert($memory instanceof CData);
                $memory[$destIndex++] = $traitNameStruct;
            } else {
                // Clean strings to prevent memory leaks
                // Drop the class entry's own reference on the removed trait names with
                // engine semantics (previously this was a wrong-allocator FFI free)
                $removedName   = $traitNameStruct->name;
                $removedLcName = $traitNameStruct->lc_name;
                assert($removedName !== null && $removedLcName !== null);
                StringEntry::fromCData($removedName)->releaseReference();
                StringEntry::fromCData($removedLcName)->releaseReference();
            }
        }
        if ($totalTraits > 0) {
            Core::untrackAndFree($previousNames);
        }
        if ($numResultTraits > 0) {
            assert($memory instanceof CData);
            $this->pointer->trait_names = Core::cast(zend_class_name::class, Core::addr($memory));
        } else {
            $this->pointer->trait_names = null;
        }
        $this->pointer->num_traits = $numResultTraits;
    }

    /**
     * Returns the engine-level view of the trait aliases configured for this class
     *
     * Unlike the native reflection method, this reads the zend_trait_alias structures
     * directly, so it also works on classes that are not linked yet and reports the
     * modifier flags. Keys are the visible method names (the alias, or the original
     * method name for modifier-only adaptations); each value describes the referenced
     * trait method: the trait name (null when the reference is unqualified), the method
     * name and the ZEND_ACC_* modifier flags (0 keeps the original modifiers).
     *
     * @return array<string, array{trait: ?string, method: string, flags: int}>
     */
    #[\Override] // @phpstan-ignore method.childReturnType (the engine-level view is deliberately richer than the native alias-name map)
    public function getTraitAliases(): array
    {
        $traitAliases = [];
        $aliasList    = $this->pointer->trait_aliases;
        if ($aliasList === null) {
            return $traitAliases;
        }
        assert($aliasList instanceof CData);
        for ($index = 0; $aliasList[$index] !== null; $index++) {
            $aliasEntry = $aliasList[$index];
            assert($aliasEntry instanceof CData);
            $traitMethodRef = $aliasEntry->trait_method;
            assert($traitMethodRef instanceof CData);
            $rawMethodName = $traitMethodRef->method_name;
            assert($rawMethodName instanceof CData);
            $methodName = StringEntry::fromCData($rawMethodName)->getStringValue();

            $traitName    = null;
            $rawTraitName = $traitMethodRef->class_name;
            if ($rawTraitName !== null) {
                assert($rawTraitName instanceof CData);
                $traitName = StringEntry::fromCData($rawTraitName)->getStringValue();
            }

            $visibleName = $methodName;
            $rawAlias    = $aliasEntry->alias;
            if ($rawAlias !== null) {
                assert($rawAlias instanceof CData);
                $visibleName = StringEntry::fromCData($rawAlias)->getStringValue();
            }

            $modifiers = $aliasEntry->modifiers;
            assert(is_int($modifiers));
            $traitAliases[$visibleName] = ['trait' => $traitName, 'method' => $methodName, 'flags' => $modifiers];
        }

        return $traitAliases;
    }

    /**
     * Returns the engine-level view of the trait precedences configured for this class
     *
     * @return array<string, list<string>> Map of "TraitName::methodName" references to the
     *         list of trait names the method is taken instead of
     */
    public function getTraitPrecedences(): array
    {
        $traitPrecedences = [];
        $precedenceList   = $this->pointer->trait_precedences;
        if ($precedenceList === null) {
            return $traitPrecedences;
        }
        assert($precedenceList instanceof CData);
        for ($index = 0; $precedenceList[$index] !== null; $index++) {
            $precedenceEntry = $precedenceList[$index];
            assert($precedenceEntry instanceof CData);
            $traitMethodRef = $precedenceEntry->trait_method;
            assert($traitMethodRef instanceof CData);
            $rawMethodName = $traitMethodRef->method_name;
            $rawTraitName  = $traitMethodRef->class_name;
            assert($rawMethodName instanceof CData && $rawTraitName instanceof CData);
            $reference = StringEntry::fromCData($rawTraitName)->getStringValue()
                . '::' . StringEntry::fromCData($rawMethodName)->getStringValue();

            $excludedTraits = [];
            $totalExcludes  = $precedenceEntry->num_excludes;
            assert(is_int($totalExcludes));
            $excludeNames = self::precedenceExcludeNames($precedenceEntry);
            for ($excludeIndex = 0; $excludeIndex < $totalExcludes; $excludeIndex++) {
                $rawExcludeName = $excludeNames[$excludeIndex];
                assert($rawExcludeName instanceof CData);
                $excludedTraits[] = StringEntry::fromCData($rawExcludeName)->getStringValue();
            }
            $traitPrecedences[$reference] = $excludedTraits;
        }

        return $traitPrecedences;
    }

    /**
     * Registers a runtime trait alias, the equivalent of `use T { T::method as flags alias; }`
     *
     * Writes only affect FUTURE linking: like addTraits(), the configured alias is consumed
     * when the engine links a class (zend_do_link_class/zend_bind_traits), so a class that
     * is already linked keeps its current method table until it is explicitly re-linked.
     *
     * Memory ownership contract (see docs/long-running.md): the zend_trait_alias structure,
     * the alias list and the stored names are tracked z-engine allocations with the same
     * allocation class the engine expects (request memory for user classes - freed by
     * destroy_zend_class() with the class - and persistent memory for internal classes).
     * removeTraitAlias() releases an entry eagerly; a replaced engine-original alias list
     * is never freed (bounded, at most one per touched class).
     *
     * @param string $traitMethod Trait method reference, either "TraitName::method" or "method"
     * @param string $alias       New visible name for the trait method
     * @param int    $flags       Optional new modifiers (ZEND_ACC_PUBLIC/PROTECTED/PRIVATE/FINAL),
     *                            0 keeps the modifiers of the original method
     * @internal
     */
    public function addTraitAlias(string $traitMethod, string $alias, int $flags = 0): void
    {
        $allowedFlags = Core::ZEND_ACC_PPP_MASK | Core::ZEND_ACC_FINAL;
        if (($flags & ~$allowedFlags) !== 0) {
            throw new \ReflectionException(
                'Trait alias flags accept only public/protected/private/final modifiers',
            );
        }
        $this->ensureWritableForTraitConfiguration();
        [$traitName, $methodName] = self::parseTraitMethodReference($traitMethod);
        $isPersistent             = $this->isPersistentAllocation();

        $aliasEntry     = Core::trackedNew('zend_trait_alias', $isPersistent);
        $traitMethodRef = $aliasEntry->trait_method;
        assert($traitMethodRef instanceof CData);
        $traitMethodRef->method_name = $this->newOwnedNamePointer($methodName, $isPersistent);
        if ($traitName !== null) {
            $traitMethodRef->class_name = $this->newOwnedNamePointer($traitName, $isPersistent);
        }
        $aliasEntry->alias     = $this->newOwnedNamePointer($alias, $isPersistent);
        $aliasEntry->modifiers = $flags;

        $this->pointer->trait_aliases = $this->appendToTraitAdaptationList(
            $this->pointer->trait_aliases,
            'zend_trait_alias',
            Core::addr($aliasEntry),
        );
    }

    /**
     * Removes a trait alias by its visible name (the alias, or the method name for
     * modifier-only adaptations)
     *
     * Only affects future linking, exactly like addTraitAlias(): methods that were already
     * bound through the alias stay in the method table. The entry's names are released and
     * the structure is freed with the allocator that owns it (tracked z-engine block or
     * engine request memory).
     *
     * @internal
     */
    public function removeTraitAlias(string $alias): void
    {
        $this->ensureWritableForTraitConfiguration();
        $aliasList = $this->pointer->trait_aliases;
        if ($aliasList !== null) {
            assert($aliasList instanceof CData);
            for ($index = 0; $aliasList[$index] !== null; $index++) {
                $aliasEntry = $aliasList[$index];
                assert($aliasEntry instanceof CData);
                $traitMethodRef = $aliasEntry->trait_method;
                assert($traitMethodRef instanceof CData);
                $rawVisibleName = $aliasEntry->alias;
                if ($rawVisibleName === null) {
                    $rawVisibleName = $traitMethodRef->method_name;
                }
                assert($rawVisibleName instanceof CData);
                if (strcasecmp(StringEntry::fromCData($rawVisibleName)->getStringValue(), $alias) !== 0) {
                    continue;
                }
                self::releaseEngineName($traitMethodRef->method_name);
                self::releaseEngineName($traitMethodRef->class_name);
                self::releaseEngineName($aliasEntry->alias);
                $this->freeTraitAdaptation($aliasEntry);
                $this->pointer->trait_aliases = $this->removeFromTraitAdaptationList(
                    $aliasList,
                    'zend_trait_alias',
                    $index,
                );

                return;
            }
        }

        throw new \ReflectionException("Trait alias {$alias} was not found in the class");
    }

    /**
     * Registers a runtime trait precedence, the equivalent of `use ... { T::method insteadof T2; }`
     *
     * Writes only affect FUTURE linking, exactly like addTraitAlias(): an already-linked
     * class keeps its current method table until it is explicitly re-linked. Memory
     * ownership follows addTraitAlias() as well (tracked allocations in the allocation
     * class of this class entry, owned by the engine once stored).
     *
     * @param string $traitMethod Qualified trait method reference "TraitName::method"
     * @param string ...$insteadOf Names of the traits whose colliding method is excluded
     * @internal
     */
    public function addTraitPrecedence(string $traitMethod, string ...$insteadOf): void
    {
        if (count($insteadOf) === 0) {
            throw new \ReflectionException('At least one trait name to exclude is required');
        }
        [$traitName, $methodName] = self::parseTraitMethodReference($traitMethod);
        if ($traitName === null) {
            throw new \ReflectionException(
                'Trait precedence requires a qualified "TraitName::method" reference',
            );
        }
        $this->ensureWritableForTraitConfiguration();
        $isPersistent  = $this->isPersistentAllocation();
        $totalExcludes = count($insteadOf);

        // zend_trait_precedence embeds a flexible array of excluded names, so the block is
        // sized manually like the compiler does (one zend_string* slot is already part of
        // the structure itself)
        $pointerSize = Core::sizeOfType('zend_string *');
        $structSize  = Core::sizeOfType(zend_trait_precedence::class) + ($totalExcludes - 1) * $pointerSize;
        $memory      = Core::trackedNew("char[{$structSize}]", $isPersistent);

        $precedenceEntry = Core::cast('zend_trait_precedence *', $memory);
        $traitMethodRef  = $precedenceEntry->trait_method;
        assert($traitMethodRef instanceof CData);
        $traitMethodRef->method_name   = $this->newOwnedNamePointer($methodName, $isPersistent);
        $traitMethodRef->class_name    = $this->newOwnedNamePointer($traitName, $isPersistent);
        $precedenceEntry->num_excludes = $totalExcludes;
        $excludeNames                  = self::precedenceExcludeNames($precedenceEntry);
        foreach (array_values($insteadOf) as $position => $excludedTrait) {
            self::storeAdaptationListSlot($excludeNames, $position, $this->newOwnedNamePointer($excludedTrait, $isPersistent));
        }

        $this->pointer->trait_precedences = $this->appendToTraitAdaptationList(
            $this->pointer->trait_precedences,
            'zend_trait_precedence',
            $precedenceEntry,
        );
    }

    /**
     * Removes a trait precedence by its qualified "TraitName::method" reference
     *
     * Only affects future linking; already-bound methods stay in the method table.
     *
     * @internal
     */
    public function removeTraitPrecedence(string $traitMethod): void
    {
        [$traitName, $methodName] = self::parseTraitMethodReference($traitMethod);
        if ($traitName === null) {
            throw new \ReflectionException(
                'Trait precedence requires a qualified "TraitName::method" reference',
            );
        }
        $this->ensureWritableForTraitConfiguration();
        $precedenceList = $this->pointer->trait_precedences;
        if ($precedenceList !== null) {
            assert($precedenceList instanceof CData);
            for ($index = 0; $precedenceList[$index] !== null; $index++) {
                $precedenceEntry = $precedenceList[$index];
                assert($precedenceEntry instanceof CData);
                $traitMethodRef = $precedenceEntry->trait_method;
                assert($traitMethodRef instanceof CData);
                $rawMethodName = $traitMethodRef->method_name;
                $rawTraitName  = $traitMethodRef->class_name;
                assert($rawMethodName instanceof CData && $rawTraitName instanceof CData);
                $sameMethod = strcasecmp(StringEntry::fromCData($rawMethodName)->getStringValue(), $methodName) === 0;
                $sameTrait  = strcasecmp(StringEntry::fromCData($rawTraitName)->getStringValue(), $traitName)   === 0;
                if (!$sameMethod || !$sameTrait) {
                    continue;
                }
                self::releaseEngineName($rawMethodName);
                self::releaseEngineName($rawTraitName);
                $totalExcludes = $precedenceEntry->num_excludes;
                assert(is_int($totalExcludes));
                $excludeNames = self::precedenceExcludeNames($precedenceEntry);
                for ($excludeIndex = 0; $excludeIndex < $totalExcludes; $excludeIndex++) {
                    self::releaseEngineName($excludeNames[$excludeIndex]);
                }
                $this->freeTraitAdaptation($precedenceEntry);
                $this->pointer->trait_precedences = $this->removeFromTraitAdaptationList(
                    $precedenceList,
                    'zend_trait_precedence',
                    $index,
                );

                return;
            }
        }

        throw new \ReflectionException("Trait precedence {$traitMethod} was not found in the class");
    }

    /**
     * Splits a "TraitName::method" (or plain "method") reference into its two parts
     *
     * @return array{?string, string} Trait name (null when not qualified) and method name
     */
    private static function parseTraitMethodReference(string $traitMethod): array
    {
        $separatorPosition = strrpos($traitMethod, '::');
        if ($separatorPosition === false) {
            if ($traitMethod === '') {
                throw new \ReflectionException('Trait method reference can not be empty');
            }

            return [null, $traitMethod];
        }
        $traitName  = substr($traitMethod, 0, $separatorPosition);
        $methodName = substr($traitMethod, $separatorPosition + 2);
        if ($traitName === '' || $methodName === '') {
            throw new \ReflectionException("Invalid trait method reference {$traitMethod}");
        }

        return [$traitName, $methodName];
    }

    /**
     * Makes the class entry writable before a trait configuration change
     */
    private function ensureWritableForTraitConfiguration(): void
    {
        $this->ensureWritableEntry('modify the trait configuration');
    }

    /**
     * Makes sure this reflection points at a class entry this process may write
     *
     * An opcache-shared (immutable) class entry lives in memory every worker process of the
     * pool sees, so it is copied out into a writable per-process copy first and this
     * reflection is rebound to the copy (see copyOutOfSharedMemory()). Only a class the copy
     * machinery cannot reproduce still rejects the mutation.
     *
     * @throws SharedMemoryException When the shared-memory class entry cannot be copied out
     */
    private function ensureWritableEntry(string $operation): void
    {
        if (!$this->isImmutable()) {
            return;
        }
        try {
            $this->copyOutOfSharedMemory();
        } catch (SharedMemoryException $copyFailure) {
            throw SharedMemoryException::immutableClassMutation($operation, $copyFailure);
        }
    }

    /**
     * Mints an owned engine string in the allocation class of this class entry and hands
     * the reference over to the engine sink that will store it
     *
     * @return \FFI\CData
     */
    private function newOwnedNamePointer(string $name, bool $isPersistent): object
    {
        $stringEntry = $isPersistent ? StringEntry::persistent($name) : StringEntry::fromString($name);

        return $stringEntry->transferReferenceOwnership()->getRawValue();
    }

    /**
     * Drops the class entry's own reference on a stored name (no-op for empty slots)
     */
    private static function releaseEngineName(mixed $stringPointer): void
    {
        if ($stringPointer === null) {
            return;
        }
        assert($stringPointer instanceof CData);
        StringEntry::fromCData($stringPointer)->releaseReference();
    }

    /**
     * Returns a freely indexable pointer to the flexible exclude_class_names array
     *
     * The FFI view of the declared zend_string *exclude_class_names[1] field is
     * bounds-checked, so the runtime-sized tail is addressed through the raw field offset.
     *
     * @param \FFI\CData $precedenceEntry
     * @return \FFI\CData
     */
    private static function precedenceExcludeNames(object $precedenceEntry): object
    {
        $excludeOffset = Core::type('zend_trait_precedence')->getStructFieldOffset('exclude_class_names');

        return Core::pointerAtAddress('zend_string **', Core::addressOf($precedenceEntry) + $excludeOffset);
    }

    /**
     * Appends one adaptation structure to a NULL-terminated engine list
     *
     * The new list is a tracked z-engine block in the allocation class of this class
     * entry; the previous list is freed if and only if z-engine allocated it (an
     * engine-original list may live in shared memory and is left alone, bounded to at
     * most one replaced list per touched class).
     *
     * @param mixed  $list     Current NULL-terminated list (CData or null)
     * @param string $itemType Engine structure name of the list items
     * @param CData  $item     Pointer to the structure to append
     * @return \FFI\CData
     */
    private function appendToTraitAdaptationList(mixed $list, string $itemType, object $item): object
    {
        // Collect the existing entries first: every value that leaves the CData boundary
        // is asserted, and the source list may disappear right after the copy
        $existingItems = [];
        if ($list !== null) {
            assert($list instanceof CData);
            for ($index = 0; $list[$index] !== null; $index++) {
                $existingItem = $list[$index];
                assert($existingItem instanceof CData);
                $existingItems[] = $existingItem;
            }
        }
        $totalItems = count($existingItems);
        // One extra slot keeps the list NULL-terminated (FFI zero-initializes new blocks)
        $memory = Core::trackedNew("{$itemType} *[" . ($totalItems + 2) . ']', $this->isPersistentAllocation());
        foreach ($existingItems as $position => $existingItem) {
            self::storeAdaptationListSlot($memory, $position, $existingItem);
        }
        self::storeAdaptationListSlot($memory, $totalItems, $item);
        if ($list !== null) {
            Core::untrackAndFree($list);
        }

        return Core::cast("{$itemType} **", Core::addr($memory));
    }

    /**
     * Stores one structure pointer into the given slot of an adaptation list block
     *
     * The write mutates engine-visible memory behind the FFI pointer, which static
     * analysis cannot see - hence the explicit impurity marker.
     *
     * @phpstan-impure
     * @param \FFI\CData $listMemory
     * @param \FFI\CData $item
     */
    private static function storeAdaptationListSlot(object $listMemory, int $position, object $item): void
    {
        $listMemory[$position] = $item;
    }

    /**
     * Rebuilds a NULL-terminated adaptation list without the given position
     *
     * @return CData|null New list, or null when the last entry was removed
     * @param \FFI\CData $list
     */
    private function removeFromTraitAdaptationList(object $list, string $itemType, int $removeIndex): ?object
    {
        $survivingItems = [];
        for ($index = 0; $list[$index] !== null; $index++) {
            if ($index === $removeIndex) {
                continue;
            }
            $survivingItem = $list[$index];
            assert($survivingItem instanceof CData);
            $survivingItems[] = $survivingItem;
        }
        if ($survivingItems === []) {
            Core::untrackAndFree($list);

            return null;
        }
        $memory = Core::trackedNew(
            "{$itemType} *[" . (count($survivingItems) + 1) . ']',
            $this->isPersistentAllocation(),
        );
        foreach ($survivingItems as $position => $survivingItem) {
            self::storeAdaptationListSlot($memory, $position, $survivingItem);
        }
        Core::untrackAndFree($list);

        return Core::cast("{$itemType} **", Core::addr($memory));
    }

    /**
     * Frees one adaptation structure with the allocator that owns it
     *
     * Tracked blocks were allocated by z-engine; other blocks in a user-defined class are
     * engine request memory (emalloc), released exactly like destroy_zend_class() would.
     * Engine-original structures of persistent classes are left alone - z-engine cannot
     * know their allocator, and such classes cannot carry traits in practice.
     *
     * @param \FFI\CData $adaptationPointer
     */
    private function freeTraitAdaptation(object $adaptationPointer): void
    {
        if (Core::isTrackedBlock($adaptationPointer)) {
            Core::untrackAndFree($adaptationPointer);
        } elseif ($this->isUserDefined()) {
            Core::free($adaptationPointer);
        }
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    #[\Override]
    public function getParentClass(): ?ReflectionClass
    {
        if (!$this->hasParentClass()) {
            return null;
        }

        // For linked class we should look at parent name directly
        if ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED) {
            $parent = $this->pointer->parent;
            assert($parent !== null);
            $rawParentName = $parent->name;
        } else {
            $rawParentName = $this->pointer->parent_name;
        }
        assert($rawParentName !== null);

        $parentNameValue = StringEntry::fromCData($rawParentName);
        $classReflection = new ReflectionClass($parentNameValue->getStringValue());

        return $classReflection;
    }

    /**
     * Removes the linked parent class from the existing class
     *
     * Besides interfaces and methods, this detaches everything the engine grafted from the
     * parent during linking: class constants, property definitions (including the default
     * property/static member tables and the property slot offsets) and the inherited
     * constructor/destructor/magic-method pointers.
     *
     * <span style="color:red; font-weight:bold">Danger!</span> No instance of this class may
     * be alive across this call: existing objects keep the old property slot layout, and
     * destroying them after the slot count changed reads memory out of bounds. The same
     * applies to opcodes with warmed-up property inline caches.
     *
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
        if ($this->isParentStateDetachable()) {
            $this->detachParentState();
        }
        $this->pointer->parent = null;
    }

    /**
     * Checks if the grafted parent state (constants, properties, statics) can be detached safely
     *
     * Only linked user-defined classes are detachable: unlinked classes have nothing grafted
     * yet, internal classes store their tables with persistent destructors that would free
     * the parent's shared entries, and immutable (opcache-shared) classes must not be
     * modified in place at all.
     */
    private function isParentStateDetachable(): bool
    {
        $classFlags = $this->pointer->ce_flags;
        assert(is_int($classFlags));
        $isLinked  = ($classFlags & Core::ZEND_ACC_LINKED) !== 0;
        $isMutable = ($classFlags & Core::ZEND_ACC_IMMUTABLE) === 0;

        return $isLinked && $isMutable && $this->isUserDefined();
    }

    /**
     * Detaches all class state grafted from the (former) parent class
     *
     * Every entry whose declaring class is not this class itself is removed, symmetric with
     * the method detachment loop in removeParentClass().
     */
    private function detachParentState(): void
    {
        $selfAddress = Core::addressOf($this->pointer);

        $this->detachParentConstants($selfAddress);
        $this->detachParentFunctionPointers($selfAddress);
        $hookedProperties = $this->pointer->num_hooked_props;
        assert(is_int($hookedProperties));
        if ($hookedProperties === 0) {
            $this->detachParentProperties($selfAddress);
        }
        // TODO: classes with property hooks (num_hooked_props > 0) keep the parent property
        // state attached: hooked properties add extra properties_info_table entries whose
        // layout cannot be compacted with plain slot arithmetic
    }

    /**
     * Removes constants declared by the (former) parent class chain
     *
     * The constants_table of a user-defined class has no payload destructor: deleting a
     * bucket only drops the pointer to the zend_class_constant that is still owned (and
     * later freed) by its declaring class.
     */
    private function detachParentConstants(int $selfAddress): void
    {
        $inheritedKeys = [];
        foreach ($this->constantsTable as $constantName => $constantValue) {
            $rawConstant    = Core::cast('zend_class_constant *', $constantValue->getRawPointer());
            $declaringClass = $rawConstant->ce;
            assert(is_string($constantName) && $declaringClass instanceof CData);
            if (Core::addressOf($declaringClass) !== $selfAddress) {
                $inheritedKeys[] = $constantName;
            }
        }
        foreach ($inheritedKeys as $constantKey) {
            $this->constantsTable->delete($constantKey);
        }
    }

    /**
     * Clears constructor/destructor/magic-method shortcuts pointing into the detached parent
     *
     * The zend_function entries themselves stay owned by their declaring class; only the
     * cached pointers inside this class entry are dropped, otherwise `new`, object
     * destruction or magic-method dispatch would still call into the detached parent.
     */
    private function detachParentFunctionPointers(int $selfAddress): void
    {
        foreach (self::INHERITED_FUNCTION_POINTERS as $fieldName) {
            $function = $this->pointer->{$fieldName};
            if ($function === null) {
                continue;
            }
            assert($function instanceof CData);
            $functionCommon = $function->common;
            assert($functionCommon instanceof CData);
            $functionScope = $functionCommon->scope;
            assert($functionScope instanceof CData);
            if (Core::addressOf($functionScope) !== $selfAddress) {
                $this->pointer->{$fieldName} = null;
            }
        }
    }

    /**
     * Removes parent-declared properties and compacts all property storages in place
     *
     * Instance properties: the default_properties_table is indexed by the slot number encoded
     * in zend_property_info.offset (OBJ_PROP_TO_NUM math: slot = (offset - base) / sizeof(zval)).
     * Parent-declared slots - including shadow slots of parent private properties that have no
     * properties_info entry, and dead slots left behind by property overrides - are released
     * and the surviving own slots are re-numbered consecutively. The engine-allocated buffers
     * are compacted in place: they stay owned by the engine, which frees them with the class.
     *
     * Static members: same compaction by table index, applied to default_static_members_table
     * and, when the class statics were already materialized (CE_STATIC_MEMBERS), to the live
     * table as well. Slots inherited from a userland parent are IS_INDIRECT views into the
     * parent storage and are dropped without releasing anything.
     */
    private function detachParentProperties(int $selfAddress): void
    {
        $zvalSize = Core::sizeOfType(zval::class);
        $slotBase = Core::type('zend_object')->getStructFieldOffset('properties_table');

        // Partition properties_info into inherited entries and own instance/static definitions
        $inheritedKeys  = [];
        $ownSlotInfos   = [];
        $ownStaticInfos = [];
        foreach ($this->propertiesTable as $propertyName => $propertyValue) {
            $rawInfo        = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $flags          = $rawInfo->flags;
            $offset         = $rawInfo->offset;
            $declaringClass = $rawInfo->ce;
            assert(is_string($propertyName) && is_int($flags) && is_int($offset));
            assert($declaringClass instanceof CData);
            if (Core::addressOf($declaringClass) !== $selfAddress) {
                $inheritedKeys[] = $propertyName;
            } elseif (($flags & Core::ZEND_ACC_STATIC) !== 0) {
                $ownStaticInfos[$offset] = $rawInfo;
            } elseif (($flags & Core::ZEND_ACC_VIRTUAL) === 0) {
                $ownSlotInfos[intdiv($offset - $slotBase, $zvalSize)] = $rawInfo;
            }
        }
        foreach ($inheritedKeys as $propertyKey) {
            $this->propertiesTable->delete($propertyKey);
        }

        $this->compactDefaultPropertiesTable($ownSlotInfos, $slotBase, $zvalSize);
        $this->compactStaticMembersTables($ownStaticInfos, $zvalSize);
    }

    /**
     * Compacts default_properties_table and properties_info_table down to the own slots
     *
     * @param array<int, CData> $ownSlotInfos zend_property_info entries of own properties, by old slot
     */
    private function compactDefaultPropertiesTable(array $ownSlotInfos, int $slotBase, int $zvalSize): void
    {
        $totalSlots = $this->pointer->default_properties_count;
        assert(is_int($totalSlots));
        if ($totalSlots === 0) {
            return;
        }
        // The engine-allocated slot table can be refilled in place but never grown: remember
        // its capacity so setParent() can check whether a new parent still fits
        self::$propertyTableCapacity[Core::addressOf($this->pointer)] = $totalSlots;

        // Renumber the surviving slots consecutively, keeping their relative order
        ksort($ownSlotInfos);
        $newSlotByOldSlot = [];
        $nextSlot         = 0;
        foreach ($ownSlotInfos as $oldSlot => $rawInfo) {
            $newSlotByOldSlot[$oldSlot] = $nextSlot;
            $rawInfo->offset            = $slotBase + $nextSlot * $zvalSize;
            $nextSlot++;
        }

        $defaultTable = $this->pointer->default_properties_table;
        assert($defaultTable instanceof CData);
        $this->compactZvalTable($defaultTable, $totalSlots, $newSlotByOldSlot, $zvalSize);

        // The slot-indexed property info table (used by GC, foreach and var_dump) must mirror
        // the new slot numbering; it is compacted in place for the same ownership reason
        $infoTable = $this->pointer->properties_info_table;
        if ($infoTable !== null) {
            assert($infoTable instanceof CData);
            foreach ($ownSlotInfos as $oldSlot => $rawInfo) {
                self::storePropertyInfoSlot($infoTable, $newSlotByOldSlot[$oldSlot], $rawInfo);
            }
            for ($slot = $nextSlot; $slot < $totalSlots; $slot++) {
                self::storePropertyInfoSlot($infoTable, $slot, null);
            }
        }

        $this->pointer->default_properties_count = $nextSlot;
    }

    /**
     * Compacts one zval slot table in place according to the old-to-new slot mapping
     *
     * Dropped slots are released with engine semantics - except IS_INDIRECT views into
     * foreign storage (inherited static member slots), which are dropped without touching
     * the value they point to. Surviving slots move down to their new position and the
     * vacated tail is neutralized to IS_UNDEF so no stale zval bytes can ever be
     * interpreted - or double-released - again.
     *
     * @param CData|zval      $table            Base pointer of the zval table
     * @param array<int, int> $newSlotByOldSlot Surviving slots, old slot => new slot (ascending)
     */
    private function compactZvalTable(object $table, int $totalSlots, array $newSlotByOldSlot, int $zvalSize): void
    {
        $nextSlot = count($newSlotByOldSlot);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotValue = self::zvalTableSlot($table, $slot);
            if (!isset($newSlotByOldSlot[$slot])) {
                if (!self::isIndirectZval($slotValue)) {
                    Core::call('zval_ptr_dtor', Core::addr($slotValue));
                }
            } elseif ($newSlotByOldSlot[$slot] !== $slot) {
                Core::memcpy(self::zvalTableSlot($table, $newSlotByOldSlot[$slot]), $slotValue, $zvalSize);
            }
        }
        for ($slot = $nextSlot; $slot < $totalSlots; $slot++) {
            self::markZvalUndef(self::zvalTableSlot($table, $slot));
        }
    }

    /**
     * Returns the zval stored in the given slot of an engine zval table
     *
     * @param CData|zval $table
     * @return \FFI\CData
     */
    private static function zvalTableSlot(object $table, int $slot): object
    {
        $slotValue = $table[$slot];
        assert($slotValue instanceof CData);

        return $slotValue;
    }

    /**
     * Checks if the given zval is an IS_INDIRECT view into another storage location
     *
     * @param \FFI\CData $zvalEntry
     */
    private static function isIndirectZval(object $zvalEntry): bool
    {
        $typeUnion = $zvalEntry->u1;
        assert($typeUnion instanceof CData);
        $typeInfo = $typeUnion->v;
        assert($typeInfo instanceof CData);

        return $typeInfo->type === ReflectionValue::IS_INDIRECT;
    }

    /**
     * Overwrites the type of the given zval with IS_UNDEF (without releasing anything)
     *
     * @param \FFI\CData $zvalEntry
     */
    private static function markZvalUndef(object $zvalEntry): void
    {
        $typeUnion = $zvalEntry->u1;
        assert($typeUnion instanceof CData);
        $typeUnion->type_info = ReflectionValue::IS_UNDEF;
    }

    /**
     * Stores a zend_property_info pointer (or null for an empty slot) into the slot-indexed
     * properties_info_table
     *
     * The offset write mutates engine memory behind the FFI pointer, which static analysis
     * cannot see - hence the explicit impurity marker.
     *
     * @phpstan-impure
     * @param \FFI\CData $infoTable
     * @param \FFI\CData|null $propertyInfo
     */
    private static function storePropertyInfoSlot(object $infoTable, int $slot, ?object $propertyInfo): void
    {
        $infoTable[$slot] = $propertyInfo;
    }

    /**
     * Compacts the default (and, if materialized, the live) static member tables
     *
     * @param array<int, CData> $ownStaticInfos zend_property_info entries of own statics, by old index
     */
    private function compactStaticMembersTables(array $ownStaticInfos, int $zvalSize): void
    {
        $totalSlots = $this->pointer->default_static_members_count;
        assert(is_int($totalSlots));
        if ($totalSlots === 0) {
            return;
        }

        ksort($ownStaticInfos);
        $newSlotByOldSlot = [];
        $nextSlot         = 0;
        foreach ($ownStaticInfos as $oldSlot => $rawInfo) {
            $newSlotByOldSlot[$oldSlot] = $nextSlot;
            $rawInfo->offset            = $nextSlot;
            $nextSlot++;
        }

        $tables       = [];
        $defaultTable = $this->pointer->default_static_members_table;
        if ($defaultTable !== null) {
            assert($defaultTable instanceof CData);
            $tables[] = $defaultTable;
        }
        // Inherited slots (IS_INDIRECT views into the parent storage) are dropped without
        // releasing the parent's value - both in the default table and in the live one
        $materialized = $this->getMaterializedStaticMembersTable();
        if ($materialized !== null) {
            $tables[] = $materialized;
        }
        foreach ($tables as $table) {
            $this->compactZvalTable($table, $totalSlots, $newSlotByOldSlot, $zvalSize);
        }

        $this->pointer->default_static_members_count = $nextSlot;
    }

    /**
     * Returns the materialized CE_STATIC_MEMBERS table if it is a plain separate pointer
     *
     * Once any static member is touched, the engine materializes a live copy of the static
     * table (zend_class_init_statics) and every further access goes through it. Map-ptr
     * offsets (low bit set, opcache-shared classes) and the legacy case where the map ptr
     * aliases default_static_members_table are reported as null - nothing extra to fix then.
     */
    /**
     * @return zval|null Pointer to the first zval slot of the materialized table
     */
    private function getMaterializedStaticMembersTable(): ?object
    {
        $materialized = $this->pointer->static_members_table__ptr;
        if ($materialized === null) {
            return null;
        }
        $materializedAddress = Core::addressOf($materialized);
        if (($materializedAddress & 1) !== 0) {
            return null;
        }
        $defaultTable = $this->pointer->default_static_members_table;
        if ($defaultTable !== null) {
            assert($defaultTable instanceof CData);
            if ($materializedAddress === Core::addressOf($defaultTable)) {
                return null;
            }
        }

        return $materialized;
    }

    /**
     * Configures a new parent class for this one
     *
     * <span style="color:red; font-weight:bold">Danger!</span> Like removeParentClass(), this
     * changes the property slot layout: no instance of this class may be alive across the
     * call, and a parent that declares more properties than the original one cannot be
     * attached to an already-linked class (the engine-allocated slot tables cannot grow).
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
        $parentEntry = $parentClassValue->getRawClass();

        $isDetachable = $this->isParentStateDetachable();
        if ($isDetachable) {
            // Refuse re-linking that would overflow the fixed-capacity slot tables before
            // any engine state is modified
            $this->assertParentFitsPropertyTables($parentEntry);
            // The materialized static members table (if any) matches the pre-inheritance
            // layout and cannot be resized: fold the live values back into the default
            // table and let the engine materialize a fresh one lazily
            $this->foldMaterializedStaticMembersTable();
        }

        // Call API to reduce the boilerplate code
        Core::call('zend_do_inheritance_ex', $this->pointer, $parentEntry, 0);

        if ($isDetachable) {
            // zend_do_inheritance_ex() does not rebuild the slot-indexed property info
            // table for an already-linked class, so mirror the new layout ourselves
            $this->refillPropertiesInfoTable($parentEntry);
        }
    }

    /**
     * Ensures the slot tables of this class can hold the properties of the new parent
     *
     * @param CData $parentEntry zend_class_entry of the new parent
     */
    private function assertParentFitsPropertyTables(object $parentEntry): void
    {
        if ($this->pointer->properties_info_table === null) {
            return;
        }
        $parentHookedProperties = $parentEntry->num_hooked_props;
        assert(is_int($parentHookedProperties));
        if ($parentHookedProperties > 0) {
            throw new \ReflectionException('Cannot inherit a class with property hooks in runtime');
        }
        $ownSlots    = $this->pointer->default_properties_count;
        $parentSlots = $parentEntry->default_properties_count;
        assert(is_int($ownSlots) && is_int($parentSlots));
        $capacity = self::$propertyTableCapacity[Core::addressOf($this->pointer)] ?? null;
        if ($capacity === null || $ownSlots + $parentSlots > $capacity) {
            throw new \ReflectionException(
                'Cannot set a parent class with more properties than the original parent: '
                . 'the engine-allocated property slot tables cannot grow after linking',
            );
        }
    }

    /**
     * Folds the live (materialized) static member values back into the default table and
     * releases the materialized table itself
     *
     * The materialized table cannot be reused after inheritance changes the static member
     * count. Its values are transferred into default_static_members_table (so they survive
     * the re-link) and the emalloc'd table block from zend_class_init_statics() is handed
     * back to the request allocator: FFI::free() on a pointer CData performs an engine
     * efree() of the pointed block, which matches the original allocation exactly.
     */
    private function foldMaterializedStaticMembersTable(): void
    {
        $mapPointer = $this->pointer->static_members_table__ptr;
        if ($mapPointer === null) {
            return;
        }
        assert($mapPointer instanceof CData);
        if ((Core::addressOf($mapPointer) & 1) !== 0) {
            // Map-ptr offset form (opcache-shared class): not ours to manage
            return;
        }
        $materialized = $this->getMaterializedStaticMembersTable();
        if ($materialized !== null) {
            $zvalSize     = Core::sizeOfType(zval::class);
            $defaultTable = $this->pointer->default_static_members_table;
            $totalSlots   = $this->pointer->default_static_members_count;
            assert($defaultTable instanceof CData && is_int($totalSlots));
            for ($slot = 0; $slot < $totalSlots; $slot++) {
                $liveValue = self::zvalTableSlot($materialized, $slot);
                if (self::isIndirectZval($liveValue) || self::isUndefZval($liveValue)) {
                    continue;
                }
                // The default slot hands its old value over and adopts the live one
                $defaultValue = self::zvalTableSlot($defaultTable, $slot);
                Core::call('zval_ptr_dtor', Core::addr($defaultValue));
                Core::memcpy($defaultValue, $liveValue, $zvalSize);
                self::markZvalUndef($liveValue);
            }
            // Every value has been moved out (or is an indirect view): return the bare
            // zval[] block to the request allocator, exactly reversing the engine's
            // emalloc in zend_class_init_statics()
            Core::free($materialized);
        }
        // Detach the map pointer: zend_do_inheritance_ex() reallocates the default table, so
        // a stale alias would dangle after re-linking; the engine re-materializes the
        // statics lazily on next access
        $this->pointer->static_members_table__ptr = null;
    }

    /**
     * Checks if the given zval slot is IS_UNDEF
     *
     * @param \FFI\CData $zvalEntry
     */
    private static function isUndefZval(object $zvalEntry): bool
    {
        $typeUnion = $zvalEntry->u1;
        assert($typeUnion instanceof CData);
        $typeInfo = $typeUnion->v;
        assert($typeInfo instanceof CData);

        return $typeInfo->type === ReflectionValue::IS_UNDEF;
    }

    /**
     * Refills the slot-indexed properties_info_table after a manual re-link
     *
     * Mirrors zend_build_properties_info_table(): parent slots are copied from the parent's
     * table, own non-static non-virtual properties are placed by their (already re-based)
     * slot offsets, dead override slots stay empty.
     *
     * @param CData $parentEntry zend_class_entry of the freshly attached parent
     */
    private function refillPropertiesInfoTable(object $parentEntry): void
    {
        $infoTable = $this->pointer->properties_info_table;
        if ($infoTable === null) {
            return;
        }
        assert($infoTable instanceof CData);
        $selfAddress = Core::addressOf($this->pointer);
        $zvalSize    = Core::sizeOfType(zval::class);
        $slotBase    = Core::type('zend_object')->getStructFieldOffset('properties_table');
        $totalSlots  = $this->pointer->default_properties_count;
        assert(is_int($totalSlots));

        for ($slot = 0; $slot < $totalSlots; $slot++) {
            self::storePropertyInfoSlot($infoTable, $slot, null);
        }
        $parentTable = $parentEntry->properties_info_table;
        if ($parentTable !== null) {
            assert($parentTable instanceof CData);
            $parentSlots = $parentEntry->default_properties_count;
            assert(is_int($parentSlots));
            for ($slot = 0; $slot < $parentSlots; $slot++) {
                $parentInfo = $parentTable[$slot];
                assert($parentInfo === null || $parentInfo instanceof CData);
                self::storePropertyInfoSlot($infoTable, $slot, $parentInfo);
            }
        }
        foreach ($this->propertiesTable as $propertyValue) {
            $rawInfo        = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $flags          = $rawInfo->flags;
            $offset         = $rawInfo->offset;
            $declaringClass = $rawInfo->ce;
            assert(is_int($flags) && is_int($offset) && $declaringClass instanceof CData);
            $isOwn = Core::addressOf($declaringClass) === $selfAddress;
            if ($isOwn && ($flags & (Core::ZEND_ACC_STATIC | Core::ZEND_ACC_VIRTUAL)) === 0) {
                self::storePropertyInfoSlot($infoTable, intdiv($offset - $slotBase, $zvalSize), $rawInfo);
            }
        }
    }

    /**
     * Declares this class as final/non-final
     *
     * @param bool $isFinal True to make class final/false to remove final flag
     */
    public function setFinal(bool $isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->ce_flags |= Core::ZEND_ACC_FINAL;
        } else {
            $this->pointer->ce_flags &= (~Core::ZEND_ACC_FINAL);
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
            $this->pointer->ce_flags |= Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS;
        } else {
            $this->pointer->ce_flags &= (~Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS);
            $this->pointer->ce_flags &= (~Core::ZEND_ACC_IMPLICIT_ABSTRACT_CLASS);
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
    #[\Override]
    public function getDefaultProperties(): iterable
    {
        return $this->readZvalTable(
            $this->pointer->default_properties_table,
            $this->pointer->default_properties_count,
        );
    }

    /**
     * Returns the list of default static members. Only for static ones
     *
     * @return iterable|ReflectionValue[]
     */
    public function getDefaultStaticMembers(): iterable
    {
        return $this->readZvalTable(
            $this->pointer->default_static_members_table,
            $this->pointer->default_static_members_count,
        );
    }

    /**
     * Wraps every entry of a contiguous class-level zval table into a ReflectionValue
     *
     * Both default tables of zend_class_entry (properties and static members) are plain
     * zval arrays sized by their own counter field, so one bounds-checked read serves both.
     *
     * @param mixed $rawTable zval * of the first entry (CData or null for an empty table)
     * @param mixed $count    Number of entries behind the pointer
     *
     * @return array<int, ReflectionValue>
     */
    private function readZvalTable(mixed $rawTable, mixed $count): array
    {
        assert(is_int($count));
        if ($rawTable === null || $count <= 0) {
            return [];
        }
        assert($rawTable instanceof CData);
        $entries = [];
        foreach (new StructArray($rawTable, $count) as $index => $valueEntry) {
            $entries[$index] = ReflectionValue::fromValueEntry($valueEntry);
        }

        return $entries;
    }

    /**
     * @inheritDoc
     * @return ReflectionClassConstant
     */
    #[\ReturnTypeWillChange]
    #[\Override]
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

        foreach (self::EXTENSION_HANDLERS as $interfaceName => [$methodName, $installerName]) {
            if (!$this->implementsInterface($interfaceName)) {
                continue;
            }
            $handler = parent::getMethod($methodName)->getClosure();
            $this->{$installerName}($handler);
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
        return $this->installObjectHook(CastObjectHook::class, $handler);
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
        return $this->installObjectHook(CompareValuesHook::class, $handler);
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
        return $this->installObjectHook(ReadPropertyHook::class, $handler);
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
        return $this->installObjectHook(WritePropertyHook::class, $handler);
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
        return $this->installObjectHook(UnsetPropertyHook::class, $handler);
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
        return $this->installObjectHook(HasPropertyHook::class, $handler);
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
        return $this->installObjectHook(GetPropertyPointerHook::class, $handler);
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
        return $this->installObjectHook(GetPropertiesForHook::class, $handler);
    }

    /**
     * Installs the "get_properties" handler for the current class
     *
     * @param Closure $handler Callback function (GetPropertiesHook $hook): array;
     *
     * @see ObjectGetPropertiesInterface
     */
    public function setGetPropertiesHandler(Closure $handler): GetPropertiesHook
    {
        return $this->installObjectHook(GetPropertiesHook::class, $handler);
    }

    /**
     * Installs the "get_debug_info" handler for the current class
     *
     * @param Closure $handler Callback function (GetDebugInfoHook $hook): array;
     *
     * @see ObjectGetDebugInfoInterface
     */
    public function setGetDebugInfoHandler(Closure $handler): GetDebugInfoHook
    {
        return $this->installObjectHook(GetDebugInfoHook::class, $handler);
    }

    /**
     * Installs the "clone_obj" handler for the current class
     *
     * @param Closure $handler Callback function (CloneObjectHook $hook): object;
     *
     * @see ObjectCloneInterface
     */
    public function setCloneObjectHandler(Closure $handler): CloneObjectHook
    {
        return $this->installObjectHook(CloneObjectHook::class, $handler);
    }

    /**
     * Installs the "read_dimension" handler for the current class
     *
     * @param Closure $handler Callback function (ReadDimensionHook $hook): mixed;
     *
     * @see ObjectReadDimensionInterface
     */
    public function setReadDimensionHandler(Closure $handler): ReadDimensionHook
    {
        return $this->installObjectHook(ReadDimensionHook::class, $handler);
    }

    /**
     * Installs the "write_dimension" handler for the current class
     *
     * @param Closure $handler Callback function (WriteDimensionHook $hook): void;
     *
     * @see ObjectWriteDimensionInterface
     */
    public function setWriteDimensionHandler(Closure $handler): WriteDimensionHook
    {
        return $this->installObjectHook(WriteDimensionHook::class, $handler);
    }

    /**
     * Installs the "has_dimension" handler for the current class
     *
     * @param Closure $handler Callback function (HasDimensionHook $hook): int;
     *
     * @see ObjectHasDimensionInterface
     */
    public function setHasDimensionHandler(Closure $handler): HasDimensionHook
    {
        return $this->installObjectHook(HasDimensionHook::class, $handler);
    }

    /**
     * Installs the "unset_dimension" handler for the current class
     *
     * @param Closure $handler Callback function (UnsetDimensionHook $hook): void;
     *
     * @see ObjectUnsetDimensionInterface
     */
    public function setUnsetDimensionHandler(Closure $handler): UnsetDimensionHook
    {
        return $this->installObjectHook(UnsetDimensionHook::class, $handler);
    }

    /**
     * Installs the "count_elements" handler for the current class
     *
     * @param Closure $handler Callback function (CountElementsHook $hook): int;
     *
     * @see ObjectCountElementsInterface
     */
    public function setCountElementsHandler(Closure $handler): CountElementsHook
    {
        return $this->installObjectHook(CountElementsHook::class, $handler);
    }

    /**
     * Installs the "get_method" handler for the current class
     *
     * @param Closure $handler Callback function (GetMethodHook $hook): ?\ReflectionMethod;
     *
     * @see ObjectGetMethodInterface
     */
    public function setGetMethodHandler(Closure $handler): GetMethodHook
    {
        return $this->installObjectHook(GetMethodHook::class, $handler);
    }

    /**
     * Installs the "get_closure" handler for the current class
     *
     * @param Closure $handler Callback function (GetClosureHook $hook): \Closure;
     *
     * @see ObjectGetClosureInterface
     */
    public function setGetClosureHandler(Closure $handler): GetClosureHook
    {
        return $this->installObjectHook(GetClosureHook::class, $handler);
    }

    /**
     * Installs the "get_constructor" handler for the current class
     *
     * @param Closure $handler Callback function (GetConstructorHook $hook): ?\ReflectionMethod;
     *
     * @see ObjectGetConstructorInterface
     */
    public function setGetConstructorHandler(Closure $handler): GetConstructorHook
    {
        return $this->installObjectHook(GetConstructorHook::class, $handler);
    }

    /**
     * Installs the "get_class_name" handler for the current class
     *
     * @param Closure $handler Callback function (GetClassNameHook $hook): string;
     *
     * @see ObjectGetClassNameInterface
     */
    public function setGetClassNameHandler(Closure $handler): GetClassNameHook
    {
        return $this->installObjectHook(GetClassNameHook::class, $handler);
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
        return $this->installObjectHook(DoOperationHook::class, $handler);
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
        $this->keepLazyLinkingCopyProcessLocal('create_object');
        self::getObjectHandlers($this->pointer);

        $hook = new CreateObjectHook($handler, $this->pointer);
        $hook->install();

        return $hook;
    }

    /**
     * Installs the "get_iterator" class-entry handler for the current class
     *
     * Unlike the zend_object_handlers family this slot lives on zend_class_entry itself
     * (like create_object): once installed, foreach and every other engine iterator
     * consumer drives the \Iterator returned by the handler through a native
     * zend_object_iterator bridge. See GetIteratorHook for the exception/by-ref contract.
     *
     * @param Closure $handler Callback function (GetIteratorHook $hook): \Iterator;
     *
     * @see ObjectGetIteratorInterface
     */
    public function setGetIteratorHandler(Closure $handler): GetIteratorHook
    {
        $this->keepLazyLinkingCopyProcessLocal('get_iterator');
        $hook = new GetIteratorHook($handler, $this->pointer);
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
        // An interface entry can itself be the lazy temporary while it links against
        // its own parent interfaces
        $this->keepLazyLinkingCopyProcessLocal('interface_gets_implemented');

        $hook = new InterfaceGetsImplementedHook($handler, $this->pointer);
        $hook->install();

        return $hook;
    }

    /**
     * Installs one zend_object_handlers hook of the given kind for the current class
     *
     * Every setXxxHandler() above is this one operation with a different hook class: the
     * class gets its own writable copy of the handlers block (allocated on first use) and
     * the hook writes its trampoline into the matching field. The template parameter keeps
     * the precise hook type on the caller side, so the public setters stay exactly typed.
     *
     * @template THook of AbstractHook
     *
     * @param class-string<THook> $hookClass Hook to instantiate for this handler field
     *
     * @return THook
     */
    private function installObjectHook(string $hookClass, Closure $handler): AbstractHook
    {
        $this->keepLazyLinkingCopyProcessLocal($hookClass);
        $handlers = self::getObjectHandlers($this->pointer);

        $hook = new $hookClass($handler, $handlers);
        $hook->install();

        return $hook;
    }

    /**
     * Makes handler installation on a temporary lazy-linking class copy stick (issue #241)
     *
     * The handlers block is keyed to this entry's address; without intervention the
     * temporary is discarded as soon as opcache's inheritance cache persists the linked
     * class, silently losing every installed handler (issue #238). So the entry is
     * recorded in the Core decline set: when its linking completes, the intercepted
     * zend_inheritance_cache_add answers NULL (the engine's ordinary "not cached"
     * outcome) and the temporary stays in the class table as a process-local class -
     * the handlers remain valid for the request, the class is simply re-linked per
     * process instead of reused from the cache, and no process-local trampoline
     * address ever reaches shared memory.
     *
     * When the interception is unavailable (engine definitions generated before the
     * zend_inheritance_cache_add symbol was exported), the loud guard of issue #238
     * remains: the installation throws instead of being silently lost. Probe with
     * isLazyLinkingCopy() before installing from an interface_gets_implemented hook.
     *
     * @param string $handlerName Handler field or hook class named in the diagnostic
     *
     * @throws SharedMemoryException When declining is unavailable in this process
     */
    private function keepLazyLinkingCopyProcessLocal(string $handlerName): void
    {
        if (!$this->isLazyLinkingCopy()) {
            return;
        }
        if (!Core::canDeclineInheritanceCachePublication()) {
            throw SharedMemoryException::handlerInstallationDuringLazyLinking($this->getName(), $handlerName);
        }
        Core::declineInheritanceCachePublication(Core::addressOf($this->pointer));
    }

    /**
     * Creates a new instance of zend_object.
     *
     * This method is useful within create_object handler
     *
     * @param CData|zend_class_entry $classType zend_class_entry type to create
     * @param bool $persistent Whether object should be allocated persistent or not. Low-level feature!
     *
     * @return CData Instance of zend_object *
     * @see zend_objects.c:zend_objects_new
     */
    public static function newInstanceRaw(object $classType, bool $persistent = false): object
    {
        $objectSize = Core::sizeOfType(zend_object::class);
        $totalSize  = $objectSize + self::getObjectPropertiesSize($classType);
        $memory     = Core::new("char[{$totalSize}]", false, $persistent);
        $object     = Core::cast('zend_object *', $memory);

        Core::call('zend_object_std_init', $object, $classType);
        $object->handlers = self::getObjectHandlers($classType);
        Core::call('object_properties_init', $object, $classType);

        return $object;
    }

    /**
     * Returns the full allocation size of an instance: zend_object plus the inline
     * properties_table slots for a given class type
     *
     * @param CData|zend_class_entry $classType zend_class_entry type to measure
     *
     * @see zend_objects_API.h:zend_objects_store_put (callers size allocations this way)
     */
    public static function getObjectSize(object $classType): int
    {
        return Core::sizeOfType(zend_object::class) + self::getObjectPropertiesSize($classType);
    }

    /**
     * Returns the number of inline property slots this class declares for its instances
     *
     * The number of zval slots the engine reserves in every instance (properties_table),
     * the property-guard slot included when the class uses guards. Machinery that works
     * on an object whose class entry may be stale (persistent graphs re-attached in a
     * later request) resolves the entry itself and reads the count through
     * ReflectionClass::fromCData($entry)->getDefaultPropertiesCount().
     */
    public function getDefaultPropertiesCount(): int
    {
        return $this->pointer->default_properties_count;
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
    /**
     * @param CData|zend_class_entry $classEntry
     */
    private function initLowLevelStructures(object $classEntry): void
    {
        /** @var zend_class_entry $classEntry Narrowed to the stub view at the owning boundary */
        $this->pointer         = $classEntry;
        $this->methodTable     = HashTable::fromCData(Core::addr($classEntry->function_table));
        $this->propertiesTable = HashTable::fromCData(Core::addr($classEntry->properties_info));
        $this->constantsTable  = HashTable::fromCData(Core::addr($classEntry->constants_table));

        $classAttributes = $classEntry->attributes;
        if ($classAttributes !== null) {
            $this->attributesTable = HashTable::fromCData($classAttributes);
        } else {
            $this->attributesTable = null;
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
    private function addRawMethod(string $methodName, object $rawFunction): ReflectionMethod
    {
        // Shared publish path with ReflectionFunction::addFunction(): the engine copies the
        // temporary container into its own bucket and returns the pointer it stores, which
        // is what pointer-level wrapper APIs like redefine() must operate on
        $storedFunction = $this->methodTable->addFunctionEntry($methodName, $rawFunction);

        return ReflectionMethod::fromCData($storedFunction);
    }

    /**
     * Returns the size of memory required for storing properties for a given class type
     *
     * @param CData|zend_class_entry $classType zend_class_entry type to get object property size
     *
     * @see zend_objects_API.h:zend_object_properties_size
     */
    private static function getObjectPropertiesSize(object $classType): int
    {
        /** @var zend_class_entry $classEntry Narrowed to the stub view at the owning boundary */
        $classEntry = $classType;
        $zvalSize   = Core::sizeOfType(zval::class);
        $useGuards  = (bool) ($classEntry->ce_flags & Core::ZEND_ACC_USE_GUARDS);

        return $zvalSize * ($classEntry->default_properties_count - ($useGuards ? 0 : 1));
    }

    /**
     * Returns a pointer to the zend_object_handlers for given zend_class_entry
     *
     * We always create our own object handlers structure to have an ability to adjust callbacks in runtime,
     * otherwise it is impossible because object handlers field is declared as "const"
     *
     * @param CData|zend_class_entry $classType zend_class_entry type to get object handlers
     * @return \FFI\CData
     */
    private static function getObjectHandlers(object $classType): object
    {
        $classEntryAddress = Core::addressOf($classType);
        if (!isset(self::$objectHandlers[$classEntryAddress])) {
            self::$objectHandlers[$classEntryAddress] = self::allocateClassObjectHandlers();
        }

        return self::$objectHandlers[$classEntryAddress];
    }

    /**
     * Allocates a new zend_object_handlers structure for a class as a copy of std_object_handlers
     *
     * @return \FFI\CData
     */
    private static function allocateClassObjectHandlers(): object
    {
        $handlers    = Core::trackedNew('zend_object_handlers', true);
        $stdHandlers = Core::getStandardObjectHandlers();
        Core::memcpy($handlers, $stdHandlers, Core::sizeof($stdHandlers));

        return Core::addr($handlers);
    }
}
