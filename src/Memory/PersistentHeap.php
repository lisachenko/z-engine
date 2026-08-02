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
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ObjectStore;
use ZEngine\Type\HashTable;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;

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
 *    its address is anchored in the PersistentHeapModule globals slot, which the
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
     * Descriptor slot indexes (integer keys inside a key's descriptor table)
     */
    private const SLOT_ROOT           = 0;
    private const SLOT_OBJECTS        = 1;
    private const SLOT_OBJECT_CLASSES = 2;
    private const SLOT_OBJECT_SIZES   = 3;
    private const SLOT_STRINGS        = 4;
    private const SLOT_ARRAYS         = 5;
    private const SLOT_BYTES          = 6;

    /**
     * The process-global heap bound to the module-globals anchor
     */
    private static ?self $instance = null;

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
     * @internal Use global() for the process heap. Direct construction binds the heap to
     *           a caller-provided registry table (used by tests and embedders that manage
     *           their own anchor); such a heap has no module anchor and is only
     *           discoverable through this wrapper.
     */
    public function __construct(
        private readonly PersistentHashTable $registry,
        private readonly ?PersistentHeapModule $module = null,
    ) {}

    /**
     * Returns the process-global heap, creating or re-attaching its anchor as needed
     *
     * The first call of a process registers the persistent heap module and mints the
     * root registry; later requests of the same process (fresh PHP state, same engine
     * registries) recover the registry from the module-globals anchor.
     */
    public static function global(): self
    {
        if (self::$instance !== null && !self::$instance->destroyed) {
            return self::$instance;
        }

        $module = new PersistentHeapModule();
        if (!$module->isModuleRegistered()) {
            $module->register();
            $module->startup();
        }

        $anchor = $module->anchorSlot();
        if ($anchor->u1->v->type === ReflectionValue::IS_PTR) {
            $registry = PersistentHashTable::fromCData(Core::cast('HashTable *', $anchor->value->ptr));
        } else {
            $registry = PersistentHashTable::create();

            // Store through the typed union member (zend_array*): void* CData round trips
            // are unreliable for address identity (see addressSet)
            $anchor->value->arr    = $registry->getRawValue();
            $anchor->u1->type_info = ReflectionValue::IS_PTR;
        }

        return self::$instance = new self($registry, $module);
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

        $classNamePool = [];
        foreach ($graph->classNames as $className) {
            if (!isset($classNamePool[$className])) {
                $entry    = StringEntry::persistentInterned($className);
                $rawValue = $entry->getRawValue();
                assert($rawValue instanceof CData);

                $classNamePool[$className] = $entry;
                $stringBlocks[]            = $rawValue;
            }
        }

        $keyEntry    = StringEntry::persistentInterned($key);
        $rawKeyValue = $keyEntry->getRawValue();
        assert($rawKeyValue instanceof CData);
        $stringBlocks[] = $rawKeyValue;

        // All inventory tables are integer-keyed on purpose: no hidden interned-string
        // keys are minted, so the strings inventory above stays the complete list
        $objectsTable = PersistentHashTable::create();
        $classesTable = PersistentHashTable::create();
        $sizesTable   = PersistentHashTable::create();
        $stringsTable = PersistentHashTable::create();
        $arraysTable  = PersistentHashTable::create();

        foreach ($graph->objects as $index => $objectPointer) {
            $classEntry = $classNamePool[$graph->classNames[$index]]->getRawValue();
            assert($classEntry instanceof CData);

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

        $descriptor = PersistentHashTable::create();
        $this->addPointerEntry($descriptor, self::SLOT_ROOT, $graph->root);
        $this->addPointerEntry($descriptor, self::SLOT_OBJECTS, $objectsTable->getRawValue());
        $this->addPointerEntry($descriptor, self::SLOT_OBJECT_CLASSES, $classesTable->getRawValue());
        $this->addPointerEntry($descriptor, self::SLOT_OBJECT_SIZES, $sizesTable->getRawValue());
        $this->addPointerEntry($descriptor, self::SLOT_STRINGS, $stringsTable->getRawValue());
        $this->addPointerEntry($descriptor, self::SLOT_ARRAYS, $arraysTable->getRawValue());
        $this->addLongEntry($descriptor, self::SLOT_BYTES, $graph->bytes);

        $descriptorValue = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $descriptor->getRawValue()[0]);
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

        $rootPointer = $this->pointerSlot($descriptor, self::SLOT_ROOT, 'zend_object *');

        $entry = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $rootPointer[0]);
        $entry->getNativeValue($alias);
        $entry->release();
        assert(is_object($alias));

        return $alias;
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
                'objects' => (int) $this->tableSlot($descriptor, self::SLOT_OBJECTS)->getRawValue()->nNumOfElements,
                'strings' => (int) $this->tableSlot($descriptor, self::SLOT_STRINGS)->getRawValue()->nNumOfElements,
                'arrays'  => (int) $this->tableSlot($descriptor, self::SLOT_ARRAYS)->getRawValue()->nNumOfElements,
                'bytes'   => $this->longSlot($descriptor, self::SLOT_BYTES),
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

        if ($this->module !== null) {
            // Reset the anchor so the next global() mints a fresh registry
            $this->module->anchorSlot()->u1->type_info = ReflectionValue::IS_UNDEF;
        }

        $this->destroyed = true;
        if (self::$instance === $this) {
            self::$instance = null;
        }
    }

    /**
     * Request-startup delivery (PersistentHeapModule::requestStartup): heap operational
     *
     * @internal
     */
    public static function onRequestStartup(): void
    {
        $heap = self::$instance;
        if ($heap === null) {
            return;
        }
        $heap->inert             = false;
        $heap->attachedKeys      = [];
        $heap->registeredHandles = [];
    }

    /**
     * Request-shutdown delivery (PersistentHeapModule::requestShutdown): heap goes inert
     *
     * Delivered right after Core::shutdown() at real request end - engine writes are
     * forbidden then, so only PHP state is dropped. In a simulated cycle (the hooks are
     * driven while the process-level request is still alive) engine writes are still
     * legal, so materialized property caches are released and store handles recycled to
     * keep the object store and the request heap flat across cycles.
     *
     * @internal
     */
    public static function onRequestShutdown(): void
    {
        $heap = self::$instance;
        if ($heap === null) {
            return;
        }
        if (!Core::isShutdown()) {
            $heap->releaseMaterializedCaches();
            $heap->recycleRegisteredHandles();
        }
        $heap->inert             = true;
        $heap->attachedKeys      = [];
        $heap->registeredHandles = [];

        // The next request must re-attach from the anchor instead of trusting PHP state
        self::$instance = null;
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

        $objectsTable = $this->tableSlot($descriptor, self::SLOT_OBJECTS);
        $classesTable = $this->tableSlot($descriptor, self::SLOT_OBJECT_CLASSES);
        $sizesTable   = $this->tableSlot($descriptor, self::SLOT_OBJECT_SIZES);

        $objectCount = (int) $objectsTable->getRawValue()->nNumOfElements;

        // Pass 1: resolve every recorded class in the current request and verify layout
        $objects    = [];
        $classNames = [];
        $resolved   = [];
        for ($index = 0; $index < $objectCount; $index++) {
            $objects[$index] = $this->pointerEntry($objectsTable, $index, 'zend_object *');

            $classNames[$index] = StringEntry::fromCData(
                $this->pointerEntry($classesTable, $index, 'zend_string *'),
            )->getStringValue();

            $classValue = Core::$executor->classTable->find($classNames[$index]);
            if ($classValue === null) {
                throw MissingClassException::forClass($key, $classNames[$index]);
            }
            $classEntry = $classValue->getRawClass();

            $expectedSize = $this->longSlot($sizesTable, $index);
            $actualSize   = ReflectionClass::getObjectSize($classEntry);
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
        $stringAddresses = $this->addressSet($this->tableSlot($descriptor, self::SLOT_STRINGS));
        $arrayAddresses  = $this->addressSet($this->tableSlot($descriptor, self::SLOT_ARRAYS));

        foreach ($objects as $index => $objectPointer) {
            $slotCount = $resolved[$index]->default_properties_count;
            $tableBase = Core::cast('zval *', Core::addr($objectPointer->properties_table[0]));
            for ($slot = 0; $slot < $slotCount; $slot++) {
                $zval = Core::addr($tableBase[$slot]);
                $type = $zval->u1->v->type;

                $intact = match ($type) {
                    ReflectionValue::IS_STRING => isset($stringAddresses[Core::addressOf($zval->value->str)]),
                    ReflectionValue::IS_ARRAY  => isset($arrayAddresses[Core::addressOf($zval->value->arr)]),
                    ReflectionValue::IS_OBJECT => isset($objectAddresses[Core::addressOf($zval->value->obj)]),
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
            $objectPointer->ce = $resolved[$index];
            // A properties cache materialized in an EARLIER request (var_dump, foreach,
            // get_object_vars) died with that request's allocator; only the pointer is
            // cleared here - it is never dereferenced
            $objectPointer->properties = null;

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
        $objectsTable = $this->tableSlot($descriptor, self::SLOT_OBJECTS);
        $classesTable = $this->tableSlot($descriptor, self::SLOT_OBJECT_CLASSES);
        $sizesTable   = $this->tableSlot($descriptor, self::SLOT_OBJECT_SIZES);
        $stringsTable = $this->tableSlot($descriptor, self::SLOT_STRINGS);
        $arraysTable  = $this->tableSlot($descriptor, self::SLOT_ARRAYS);

        $objectCount = (int) $objectsTable->getRawValue()->nNumOfElements;
        $objects     = [];
        for ($index = 0; $index < $objectCount; $index++) {
            $objects[$index] = $this->pointerEntry($objectsTable, $index, 'zend_object *');
        }

        // Materialized property caches hold references on child objects: release them
        // first so the live-alias guard below sees only genuine userland references
        foreach ($objects as $objectPointer) {
            $this->releasePropertiesCache($objectPointer);
        }

        foreach ($objects as $objectPointer) {
            if ($objectPointer->gc->refcount !== PersistentObjectFactory::PIN_BASELINE) {
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
            $objectsTable = $this->tableSlot($descriptor, self::SLOT_OBJECTS);
            $objectCount  = (int) $objectsTable->getRawValue()->nNumOfElements;
            for ($index = 0; $index < $objectCount; $index++) {
                $this->releasePropertiesCache($this->pointerEntry($objectsTable, $index, 'zend_object *'));
            }
        }
    }

    /**
     * Releases the request-lifetime properties cache of one persistent object, if any
     *
     * The cache is a plain request-allocated HashTable the engine builds lazily for
     * var_dump/foreach/get_object_vars; the object owns exactly one reference on it.
     */
    private function releasePropertiesCache(CData $objectPointer): void
    {
        if ($objectPointer->properties === null) {
            return;
        }
        (new HashTable($objectPointer->properties))->releaseReference();
        $objectPointer->properties = null;
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
     */
    private function currentValidHandle(ObjectStore $store, CData $objectPointer): ?int
    {
        $handle = $objectPointer->handle;
        if (!is_int($handle) || $handle < 1 || !isset($store[$handle])) {
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
            throw new PersistentHeapException('This persistent heap has been destroyed');
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
     */
    private function addPointerEntry(PersistentHashTable $table, int $index, CData $pointer): void
    {
        // newEntry() stores the ADDRESS of the passed (dereferenced) struct view
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $pointer[0]);
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
     * Reads a typed pointer stored as an IS_PTR entry under an integer key
     */
    private function pointerEntry(PersistentHashTable $table, int $index, string $type): CData
    {
        $value = $table->findIndex($index);
        if ($value === null) {
            throw new PersistentHeapException("Corrupt heap metadata: missing pointer entry #{$index}");
        }

        return Core::cast($type, $value->getRawPointer());
    }

    /**
     * Reads an IS_LONG entry under an integer key
     */
    private function longSlot(PersistentHashTable $table, int $index): int
    {
        $value = $table->findIndex($index);
        if ($value === null) {
            throw new PersistentHeapException("Corrupt heap metadata: missing long entry #{$index}");
        }
        $value->getNativeValue($native);
        assert(is_int($native));

        return $native;
    }

    /**
     * Resolves a descriptor slot holding a nested inventory table
     */
    private function tableSlot(PersistentHashTable $descriptor, int $slot): PersistentHashTable
    {
        return PersistentHashTable::fromCData($this->pointerEntry($descriptor, $slot, 'HashTable *'));
    }

    /**
     * Reads a typed pointer stored in a descriptor slot
     */
    private function pointerSlot(PersistentHashTable $descriptor, int $slot, string $type): CData
    {
        return $this->pointerEntry($descriptor, $slot, $type);
    }

    /**
     * Collects all pointers of an integer-keyed inventory table
     *
     * @return list<CData>
     */
    private function pointerList(PersistentHashTable $table, string $type): array
    {
        $pointers = [];
        $count    = (int) $table->getRawValue()->nNumOfElements;
        for ($index = 0; $index < $count; $index++) {
            $pointers[] = $this->pointerEntry($table, $index, $type);
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
        $count     = (int) $table->getRawValue()->nNumOfElements;
        for ($index = 0; $index < $count; $index++) {
            // A TYPED pointer view is required: addressOf() over a bare void* CData
            // reinterprets the pointee bytes instead of the pointer value
            $addresses[Core::addressOf($this->pointerEntry($table, $index, 'char *'))] = true;
        }

        return $addresses;
    }
}
