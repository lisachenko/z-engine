<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Memory;

use FFI\CData;
use ZEngine\Core;
use ZEngine\EngineExtension\ExtensionManager;
use ZEngine\EngineExtension\ZEngineModule;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ObjectStore;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;
use ZEngine\Type\StructArray;

/**
 * A named in-process registry of object graphs that survive request shutdown
 *
 * The heap stores DEEP PERSISTENT CLONES: put() copies the reachable graph into malloc
 * memory (PersistentGraphCloner), get() re-attaches the stored clone to the current
 * request and returns a live alias of it. The source graph is never mutated and never
 * referenced again. Full subsystem documentation: docs/persistent-heap.md.
 *
 * Storage layout - everything the heap needs across requests lives in engine-visible
 * persistent memory, never in PHP state (PHP statics die with the request):
 *
 *  - the ROOT REGISTRY is a PersistentHashTable mapping heap key => descriptor table;
 *    its address is anchored in the ZEngineModule globals slot, which the
 *    persistent module registry keeps for the process lifetime;
 *  - one DESCRIPTOR table per key holds the root object pointer, the graph byte count
 *    and five integer-keyed inventory tables: cloned objects, their class names, their
 *    recorded object sizes, minted strings and minted array tables. The inventory lists
 *    every malloc block of the graph exactly once - shared DAG nodes appear once - which
 *    is what makes eviction exact and re-attachment verifiable.
 *
 * Request lifecycle (wired through the module's RequestStartupHook/RequestShutdownHook,
 * ordering documented in docs/persistent-heap.md):
 *
 *  - requestStartup: the heap becomes operational; nothing is touched eagerly;
 *  - first get() of a key per request RE-ATTACHES the graph: every recorded class is
 *    resolved in the current class table (MissingClassException), its current object
 *    size is compared against the recorded one (ClassLayoutChangedException), every
 *    stored slot is verified by address against the inventory (GraphCorruptedException),
 *    and only then are class-entry pointers rewritten and fresh object-store handles
 *    assigned. Verification is strictly read-only, so a failed re-attachment leaves the
 *    graph intact and evictable.
 *  - requestShutdown (delivered right AFTER Core::shutdown() at real request end): the
 *    heap turns INERT - every operation throws HeapInertException, because no engine
 *    memory may be written anymore. In simulated request cycles (worker managers driving
 *    the hooks while the process-level request is still alive) the heap additionally
 *    releases materialized property caches and recycles its object-store handles first.
 *
 * The garbage collector never traverses the persistent region: every cloned object
 * header carries GC_NOT_COLLECTABLE (never buffered as a possible root, never scanned),
 * and get_gc handlers are a declared non-goal. Refcount churn from request aliases lands
 * on the PIN_BASELINE-saturated counter and can never reach zero.
 */
final class PersistentHeap
{
    /**
     * Keys re-attached in the current request (per-request state, reset by the hooks)
     *
     * @var array<string, true>
     */
    private array $attachedKeys = [];

    /**
     * Object-store handles registered for each attached key in the current request
     *
     * @var array<string, list<int>>
     */
    private array $registeredHandles = [];

    /**
     * Between requestShutdown and the next requestStartup no operation may run
     */
    private bool $inert = false;

    /**
     * Set by destroy(): the wrapper (and the registry it owned) are gone
     */
    private bool $destroyed = false;

    /**
     * The registry table is INJECTED - the heap never creates its own storage. The
     * process heap receives the anchor-recovered registry from ZEngineModule::heap();
     * tests and embedders that manage their own anchor inject a table directly.
     */
    public function __construct(
        private readonly PersistentHashTable $registry,
        private readonly ?ZEngineModule $module = null,
    ) {}

    /**
     * Returns the process-global heap anchored in the zengine module
     *
     * Pure convenience over the typed module registry - no hidden bootstrap happens
     * here: the module must have been registered explicitly
     * (ExtensionManager::register(new ZEngineModule())) during application startup.
     *
     * @throws \ZEngine\EngineExtension\ExtensionNotRegisteredException when it was not
     */
    public static function global(): self
    {
        return ExtensionManager::get(ZEngineModule::class)->heap();
    }

    /**
     * Stores a deep persistent clone of the graph reachable from $root under $key
     *
     * The whole graph is validated against the supported-type matrix BEFORE the first
     * persistent byte is allocated; an existing graph under the same key is evicted
     * first (which requires that none of its aliases are alive).
     *
     * @throws UnsupportedGraphElementException for any value outside the matrix
     * @throws HeapInUseException when overwriting a key whose aliases are still alive
     */
    public function put(string $key, object $root): void
    {
        $this->assertOperational();

        $existing = $this->findDescriptor($key);
        if ($existing !== null) {
            $this->evict($key, $existing);
        }

        $graph = (new PersistentGraphCloner())->persist($root);

        // Metadata strings minted on top of the graph inventory: the registry bucket key
        // and one class-name string per stored object. They join the strings inventory so
        // eviction releases them together with the graph.
        $stringBlocks = $graph->strings;

        /** @var array<string, StringEntry> $classNamePool One interned block per distinct class name */
        $classNamePool = [];
        foreach ($graph->classNames as $className) {
            if (!isset($classNamePool[$className])) {
                $entry = StringEntry::persistentInterned($className);

                $classNamePool[$className] = $entry;
                $stringBlocks[]            = $entry->getRawValue();
            }
        }

        $keyEntry       = StringEntry::persistentInterned($key);
        $stringBlocks[] = $keyEntry->getRawValue();

        // All inventory tables are integer-keyed on purpose: no hidden interned-string
        // keys are minted, so the strings inventory above stays the complete list
        $objectsTable = new PersistentHashTable();
        $classesTable = new PersistentHashTable();
        $sizesTable   = new PersistentHashTable();
        $stringsTable = new PersistentHashTable();
        $arraysTable  = new PersistentHashTable();

        foreach ($graph->objects as $index => $objectPointer) {
            $classEntry = $classNamePool[$graph->classNames[$index]]->getRawValue();

            $this->addPointerEntry($objectsTable, $index, $objectPointer);
            $this->addPointerEntry($classesTable, $index, $classEntry);
            $this->addLongEntry($sizesTable, $index, $graph->classSizes[$index]);
        }
        foreach ($stringBlocks as $index => $stringPointer) {
            $this->addPointerEntry($stringsTable, $index, $stringPointer);
        }
        foreach ($graph->arrays as $index => $arrayPointer) {
            $this->addPointerEntry($arraysTable, $index, $arrayPointer);
        }

        $descriptor = new PersistentHashTable();
        $this->addPointerEntry($descriptor, DescriptorSlot::Root->value, $graph->root);
        $this->addPointerEntry($descriptor, DescriptorSlot::Objects->value, $objectsTable->getRawValue());
        $this->addPointerEntry($descriptor, DescriptorSlot::ObjectClasses->value, $classesTable->getRawValue());
        $this->addPointerEntry($descriptor, DescriptorSlot::ObjectSizes->value, $sizesTable->getRawValue());
        $this->addPointerEntry($descriptor, DescriptorSlot::Strings->value, $stringsTable->getRawValue());
        $this->addPointerEntry($descriptor, DescriptorSlot::Arrays->value, $arraysTable->getRawValue());
        $this->addLongEntry($descriptor, DescriptorSlot::Bytes->value, $graph->bytes);

        $descriptorValue = ReflectionValue::newEntry(ReflectionValue::IS_PTR, StructArray::at($descriptor->getRawValue()));
        $this->registry->addInterned($keyEntry, $descriptorValue);
        $descriptorValue->release();
    }

    /**
     * Returns a live alias of the stored graph root, or null when the key is absent
     *
     * The first call per request re-attaches the graph (see the class docblock); later
     * calls return an alias of the same zend_object, so two get() results of one key
     * are identical (===) within a request.
     *
     * @throws MissingClassException      when a recorded class is not defined anymore
     * @throws ClassLayoutChangedException when a recorded class changed its object size
     * @throws GraphCorruptedException     when a stored slot points outside the graph
     */
    public function get(string $key): ?object
    {
        $this->assertOperational();

        $descriptor = $this->findDescriptor($key);
        if ($descriptor === null) {
            return null;
        }

        $this->attach($key, $descriptor);

        $rootPointer = Core::cast('zend_object *', $this->requireEntry($descriptor, DescriptorSlot::Root->value)->getRawPointer());

        return ObjectEntry::fromCData($rootPointer)->getNativeValue();
    }

    /**
     * Evicts one graph: frees every block of its inventory exactly once
     *
     * @throws HeapKeyNotFoundException when the key is absent
     * @throws HeapInUseException       when userland aliases of the graph are still alive
     */
    public function remove(string $key): void
    {
        $this->assertOperational();

        $descriptor = $this->findDescriptor($key);
        if ($descriptor === null) {
            throw HeapKeyNotFoundException::forKey($key);
        }

        $this->evict($key, $descriptor);
    }

    /**
     * Returns allocation statistics: totals plus a per-key breakdown
     *
     * Counts are inventory blocks (objects, minted strings including metadata strings,
     * minted array tables); bytes are the directly allocated payload bytes recorded at
     * put() time (engine-grown table data blocks are not included).
     *
     * @return array{keys: int, objects: int, strings: int, arrays: int, bytes: int,
     *               perKey: array<string, array{objects: int, strings: int, arrays: int, bytes: int}>}
     */
    public function stats(): array
    {
        $this->assertOperational();

        $perKey  = [];
        $objects = $strings = $arrays = $bytes = 0;

        foreach ($this->registry->getIterator() as $key => $value) {
            assert(is_string($key));
            $descriptor = PersistentHashTable::fromCData(Core::cast('HashTable *', $value->getRawPointer()));

            $keyStats = [
                'objects' => $this->tableSlot($descriptor, DescriptorSlot::Objects)->count(),
                'strings' => $this->tableSlot($descriptor, DescriptorSlot::Strings)->count(),
                'arrays'  => $this->tableSlot($descriptor, DescriptorSlot::Arrays)->count(),
                'bytes'   => $this->byteCount($descriptor),
            ];

            $perKey[$key] = $keyStats;
            $objects += $keyStats['objects'];
            $strings += $keyStats['strings'];
            $arrays  += $keyStats['arrays'];
            $bytes   += $keyStats['bytes'];
        }

        return [
            'keys'    => count($perKey),
            'objects' => $objects,
            'strings' => $strings,
            'arrays'  => $arrays,
            'bytes'   => $bytes,
            'perKey'  => $perKey,
        ];
    }

    /**
     * Evicts every key and dismantles the registry itself
     *
     * After destroy() this wrapper is unusable; the process heap can be re-created by
     * the next global() call (the module entry itself stays registered - module entries
     * are immortal by design, see docs/long-running.md).
     *
     * @throws HeapInUseException when any graph still has live aliases
     */
    public function destroy(): void
    {
        $this->assertOperational();

        $keys = [];
        foreach ($this->registry->getIterator() as $key => $value) {
            assert(is_string($key));
            $keys[] = $key;
        }
        foreach ($keys as $key) {
            $descriptor = $this->findDescriptor($key);
            assert($descriptor !== null);
            $this->evict($key, $descriptor);
        }

        $this->registry->destroy();

        // The module clears its anchor so the next heap() call mints a fresh registry
        $this->module?->onHeapDestroyed();

        $this->destroyed = true;
    }

    /**
     * Request-startup delivery (ZEngineModule::requestStartup): heap operational
     *
     * @internal
     */
    public function onRequestStartup(): void
    {
        $this->inert             = false;
        $this->attachedKeys      = [];
        $this->registeredHandles = [];
    }

    /**
     * Request-shutdown delivery (ZEngineModule::requestShutdown): heap goes inert
     *
     * Delivered right after Core::shutdown() at real request end - engine writes are
     * forbidden then, so only PHP state is dropped. In a simulated cycle (the hooks are
     * driven while the process-level request is still alive) engine writes are still
     * legal, so materialized property caches are released and store handles recycled to
     * keep the object store and the request heap flat across cycles.
     *
     * @internal
     */
    public function onRequestShutdown(): void
    {
        if (!Core::isShutdown()) {
            $this->releaseMaterializedCaches();
            $this->recycleRegisteredHandles();
        }
        $this->inert             = true;
        $this->attachedKeys      = [];
        $this->registeredHandles = [];
    }

    /**
     * Re-attaches one stored graph to the current request (idempotent per request)
     *
     * Order matters: all three verification passes are strictly read-only and run to
     * completion BEFORE the first write, so any typed failure leaves the graph exactly
     * as it was - still evictable, never half-attached.
     */
    private function attach(string $key, PersistentHashTable $descriptor): void
    {
        if (isset($this->attachedKeys[$key])) {
            return;
        }

        $objectsTable = $this->tableSlot($descriptor, DescriptorSlot::Objects);
        $classesTable = $this->tableSlot($descriptor, DescriptorSlot::ObjectClasses);
        $sizesTable   = $this->tableSlot($descriptor, DescriptorSlot::ObjectSizes);

        $objectCount = $objectsTable->count();

        // Pass 1: resolve every recorded class in the current request and verify layout
        $objects    = [];
        $classNames = [];
        $resolved   = [];
        for ($index = 0; $index < $objectCount; $index++) {
            $objects[$index] = Core::cast('zend_object *', $this->requireEntry($objectsTable, $index)->getRawPointer());

            $classNames[$index] = StringEntry::fromCData(
                Core::cast('zend_string *', $this->requireEntry($classesTable, $index)->getRawPointer()),
            )->getStringValue();

            $classValue = Core::$executor->classTable->find($classNames[$index]);
            if ($classValue === null) {
                throw MissingClassException::forClass($key, $classNames[$index]);
            }
            $classEntry = $classValue->getRawClass();

            $this->requireEntry($sizesTable, $index)->getNativeValue($expectedSize);
            assert(is_int($expectedSize));
            $actualSize = ReflectionClass::getObjectSize($classEntry);
            if ($actualSize !== $expectedSize) {
                throw ClassLayoutChangedException::forClass($key, $classNames[$index], $expectedSize, $actualSize);
            }
            $resolved[$index] = $classEntry;
        }

        // Pass 2: verify by ADDRESS (no dereference of any payload pointer) that every
        // stored slot still points into the graph's own inventory
        $objectAddresses = [];
        foreach ($objects as $objectPointer) {
            $objectAddresses[Core::addressOf($objectPointer)] = true;
        }
        $stringAddresses = $this->addressSet($this->tableSlot($descriptor, DescriptorSlot::Strings));
        $arrayAddresses  = $this->addressSet($this->tableSlot($descriptor, DescriptorSlot::Arrays));

        foreach ($objects as $index => $objectPointer) {
            // A stored object still carries the class entry of the request that minted the
            // clone (pass 3 rewrites it), so the slot count MUST come from the entry resolved
            // above - dereferencing the stale one here would read freed memory
            $slotCount = ReflectionClass::fromCData($resolved[$index])->getDefaultPropertiesCount();
            $slots     = new StructArray(ObjectEntry::fromCData($objectPointer)->getPropertyTablePointer(), $slotCount);
            for ($slot = 0; $slot < $slotCount; $slot++) {
                $value  = ReflectionValue::fromValueEntry(Core::addr($slots[$slot]));
                $intact = match ($value->getBaseType()) {
                    ReflectionValue::IS_STRING => isset($stringAddresses[Core::addressOf($value->getRawString())]),
                    ReflectionValue::IS_ARRAY  => isset($arrayAddresses[Core::addressOf($value->getRawArray())]),
                    ReflectionValue::IS_OBJECT => isset($objectAddresses[Core::addressOf($value->getRawObject())]),
                    ReflectionValue::IS_RESOURCE,
                    ReflectionValue::IS_REFERENCE => false,
                    default                       => true,
                };
                if (!$intact) {
                    throw GraphCorruptedException::forSlot($key, $classNames[$index], $slot);
                }
            }
        }

        // Pass 3: writes - current class entries, dropped stale caches, fresh handles
        $store = Core::$executor->objectStore;
        foreach ($objects as $index => $objectPointer) {
            $entry = ObjectEntry::fromCData($objectPointer);
            // Rebinding to the class entry of the CURRENT request; the names were resolved
            // (and their layout verified) in pass 1, so the lookup cannot fail here
            $entry->setClass($classNames[$index]);
            // A properties cache materialized in an EARLIER request (var_dump, foreach,
            // get_object_vars) died with that request's allocator; only the pointer is
            // cleared here - it is never dereferenced
            $entry->setDynamicPropertiesPointer(null);

            // Register at most once per request: a still-valid bucket that points at this
            // very object (another wrapper attached it) must not be duplicated - a second
            // put() would orphan the first bucket
            if ($this->currentValidHandle($store, $objectPointer) === null) {
                $this->registeredHandles[$key][] = $store->put($objectPointer);
            }
        }

        $this->attachedKeys[$key] = true;
    }

    /**
     * Frees one graph completely; see remove() for the userland contract
     */
    private function evict(string $key, PersistentHashTable $descriptor): void
    {
        $objectsTable = $this->tableSlot($descriptor, DescriptorSlot::Objects);
        $classesTable = $this->tableSlot($descriptor, DescriptorSlot::ObjectClasses);
        $sizesTable   = $this->tableSlot($descriptor, DescriptorSlot::ObjectSizes);
        $stringsTable = $this->tableSlot($descriptor, DescriptorSlot::Strings);
        $arraysTable  = $this->tableSlot($descriptor, DescriptorSlot::Arrays);

        $objectCount = $objectsTable->count();
        $objects     = [];
        for ($index = 0; $index < $objectCount; $index++) {
            $objects[$index] = Core::cast('zend_object *', $this->requireEntry($objectsTable, $index)->getRawPointer());
        }

        // Materialized property caches hold references on child objects: release them
        // first so the live-alias guard below sees only genuine userland references
        foreach ($objects as $objectPointer) {
            $this->releasePropertiesCache($objectPointer);
        }

        foreach ($objects as $objectPointer) {
            if (ObjectEntry::fromCData($objectPointer)->getReferenceCount() !== PersistentObjectFactory::PIN_BASELINE) {
                throw HeapInUseException::forKey($key);
            }
        }

        // Return the store slots of every object about to be freed. The object's own
        // handle field is the source of truth (verified against the bucket), so slots
        // registered by ANY wrapper of this request are found - a bucket left pointing
        // at freed memory would crash the store shutdown passes at request end
        $store = Core::$executor->objectStore;
        foreach ($objects as $objectPointer) {
            $handle = $this->currentValidHandle($store, $objectPointer);
            if ($handle !== null) {
                $store->recycle($handle);
            }
        }
        unset($this->registeredHandles[$key], $this->attachedKeys[$key]);

        // Snapshot the payload block pointers before their inventory tables go away
        $stringPointers = $this->pointerList($stringsTable, 'zend_string *');
        $arrayPointers  = $this->pointerList($arraysTable, 'HashTable *');

        // Drop the registry bucket while its interned key block is still alive
        $this->registry->delete($key);

        // Dismantle every table first (interned key blocks are still dereferenced by
        // zend_hash_destroy), then free the raw malloc blocks
        foreach ($arrayPointers as $arrayPointer) {
            PersistentHashTable::fromCData($arrayPointer)->destroy();
        }
        $objectsTable->destroy();
        $classesTable->destroy();
        $sizesTable->destroy();
        $stringsTable->destroy();
        $arraysTable->destroy();
        $descriptor->destroy();

        foreach ($objects as $objectPointer) {
            Core::untrack($objectPointer);
            Core::persistentFree($objectPointer);
        }
        foreach ($stringPointers as $stringPointer) {
            Core::persistentFree($stringPointer);
        }
    }

    /**
     * Releases materialized per-request property caches of all attached graphs
     */
    private function releaseMaterializedCaches(): void
    {
        foreach (array_keys($this->attachedKeys) as $key) {
            $descriptor = $this->findDescriptor($key);
            if ($descriptor === null) {
                continue;
            }
            $objectsTable = $this->tableSlot($descriptor, DescriptorSlot::Objects);
            $objectCount  = $objectsTable->count();
            for ($index = 0; $index < $objectCount; $index++) {
                $this->releasePropertiesCache(
                    Core::cast('zend_object *', $this->requireEntry($objectsTable, $index)->getRawPointer()),
                );
            }
        }
    }

    /**
     * Releases the request-lifetime properties cache of one persistent object, if any
     *
     * The cache is a plain request-allocated HashTable the engine builds lazily for
     * var_dump/foreach/get_object_vars; the object owns exactly one reference on it.
     *
     * @param \FFI\CData $objectPointer
     */
    private function releasePropertiesCache(object $objectPointer): void
    {
        $entry      = ObjectEntry::fromCData($objectPointer);
        $properties = $entry->getDynamicPropertiesPointer();
        if ($properties === null) {
            return;
        }
        HashTable::fromCData($properties)->releaseReference();
        $entry->setDynamicPropertiesPointer(null);
    }

    /**
     * Recycles every object-store handle the heap registered in the current request
     */
    private function recycleRegisteredHandles(): void
    {
        $store = Core::$executor->objectStore;
        foreach ($this->registeredHandles as $handles) {
            foreach ($handles as $handle) {
                $store->recycle($handle);
            }
        }
        $this->registeredHandles = [];
    }

    /**
     * Returns the store handle currently bound to this object, or null if none
     *
     * The object's handle field alone is not trusted: it may be a recycled number from
     * an earlier request. The handle counts only when the store bucket it names is valid
     * AND points back at this very object.
     *
     * @param \FFI\CData $objectPointer
     */
    private function currentValidHandle(ObjectStore $store, object $objectPointer): ?int
    {
        $handle = ObjectEntry::fromCData($objectPointer)->getHandle();
        // Valid handles are 1..top-1 == 1..count($store); anything else is out of range
        if ($handle < 1 || $handle > count($store)) {
            return null;
        }
        $bucket = $store[$handle];
        if ($bucket === null || Core::addressOf($bucket->getRawValue()) !== Core::addressOf($objectPointer)) {
            return null;
        }

        return $handle;
    }

    private function assertOperational(): void
    {
        if ($this->destroyed) {
            throw PersistentHeapException::heapDestroyed();
        }
        if ($this->inert || Core::isShutdown()) {
            throw HeapInertException::create();
        }
    }

    private function findDescriptor(string $key): ?PersistentHashTable
    {
        $value = $this->registry->find($key);
        if ($value === null) {
            return null;
        }

        return PersistentHashTable::fromCData(Core::cast('HashTable *', $value->getRawPointer()));
    }

    /**
     * Stores an IS_PTR entry under an integer key (engine copies the zval bytes)
     *
     * @param CData|object $pointer Inventory block pointer; the runtime value is always the
     *                              raw CData handle, statically stub-typed views are accepted
     */
    private function addPointerEntry(PersistentHashTable $table, int $index, object $pointer): void
    {
        // newEntry() stores the ADDRESS of the passed (dereferenced) struct view
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, StructArray::at($pointer));
        $table->addIndex($index, $entry);
        $entry->release();
    }

    /**
     * Stores an IS_LONG entry under an integer key
     */
    private function addLongEntry(PersistentHashTable $table, int $index, int $value): void
    {
        $entry = new ReflectionValue($value);
        $table->addIndex($index, $entry);
        $entry->release();
    }

    /**
     * Reads a mandatory inventory entry as a ReflectionValue (borrowed bucket view)
     *
     * All typed extraction happens through the ReflectionValue getters at the call
     * sites (getRawPointer() for IS_PTR entries, getNativeValue() for scalars).
     */
    private function requireEntry(PersistentHashTable $table, int $index): ReflectionValue
    {
        return $table->findIndex($index)
            ?? throw PersistentHeapException::corruptMetadata($index);
    }

    /**
     * Reads the recorded payload byte count of one descriptor
     */
    private function byteCount(PersistentHashTable $descriptor): int
    {
        $this->requireEntry($descriptor, DescriptorSlot::Bytes->value)->getNativeValue($bytes);
        assert(is_int($bytes));

        return $bytes;
    }

    /**
     * Resolves a descriptor slot holding a nested inventory table
     */
    private function tableSlot(PersistentHashTable $descriptor, DescriptorSlot $slot): PersistentHashTable
    {
        return PersistentHashTable::fromCData(
            Core::cast('HashTable *', $this->requireEntry($descriptor, $slot->value)->getRawPointer()),
        );
    }

    /**
     * Collects all pointers of an integer-keyed inventory table
     *
     * @return list<CData>
     */
    private function pointerList(PersistentHashTable $table, string $type): array
    {
        $pointers = [];
        $count    = $table->count();
        for ($index = 0; $index < $count; $index++) {
            $pointers[] = Core::cast($type, $this->requireEntry($table, $index)->getRawPointer());
        }

        return $pointers;
    }

    /**
     * Builds an address set over an integer-keyed inventory table
     *
     * @return array<int, true>
     */
    private function addressSet(PersistentHashTable $table): array
    {
        $addresses = [];
        $count     = $table->count();
        for ($index = 0; $index < $count; $index++) {
            // A TYPED pointer view is required: addressOf() over a bare void* CData
            // reinterprets the pointee bytes instead of the pointer value
            $addresses[Core::addressOf(Core::cast('char *', $this->requireEntry($table, $index)->getRawPointer()))] = true;
        }

        return $addresses;
    }

}
