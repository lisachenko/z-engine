# The persistent heap: named object graphs that survive request shutdown

`ZEngine\Memory\PersistentHeap` promotes the low-level persistence primitives
(`PersistentObjectFactory::persistentClone`, `PersistentHashTable`,
`StringEntry::persistent()/persistentInterned()`, introduced with
[#97](https://github.com/lisachenko/z-engine/pull/97)) into a documented subsystem: a named
in-process registry that stores whole object graphs in malloc memory, where they survive
request shutdown in an NTS worker process and can be re-attached by a later request.

```php
use ZEngine\EngineExtension\ExtensionManager;
use ZEngine\EngineExtension\ZEngineModule;
use ZEngine\Memory\PersistentHeap;

// Explicit bootstrap, once per request, after Core::init(): the framework module is
// constructed by YOU and registered in the typed extension registry - the heap never
// boots anything behind your back (ExtensionNotRegisteredException otherwise)
ExtensionManager::register(new ZEngineModule());

$heap = PersistentHeap::global();        // = ExtensionManager::get(ZEngineModule::class)->heap()

$heap->put('routing-tree', $rootObject); // deep persistent clone of the reachable graph
$root = $heap->get('routing-tree');      // this or a later request: live, usable alias
$heap->remove('routing-tree');           // exact eviction of every block of the graph
$stats = $heap->stats();                 // objects / strings / arrays / bytes, per key
$heap->destroy();                        // evict everything and drop the registry itself
```

This document covers the design; the memory-ownership ground rules it builds on live in
[`memory-model.md`](memory-model.md) and [`long-running.md`](long-running.md).

## What put() stores

`put()` performs a **deep persistent clone** of the graph reachable from the root object.
The source graph is never mutated, never referenced afterwards, and remains an ordinary
request-lifetime graph. The clone is built in two strictly separated phases
(`PersistentGraphCloner`):

1. **Validation** walks the whole reachable graph and enforces the supported-type matrix
   (below) with typed exceptions. Nothing persistent is allocated yet, so a rejected
   `put()` leaves no trace — corruption at read time is never an acceptable failure mode,
   so every unsupported value is refused up front.
2. **Cloning** walks the raw engine values and mints the persistent copy:
   - **objects** become refcount-pinned persistent `zend_object` clones
     (`PersistentObjectFactory::persistentClone`);
   - **strings** become persistent interned blocks (`StringEntry::persistentInterned`),
     deduplicated by content, stored in zvals without refcounting;
   - **arrays** become sealed `PersistentHashTable` copies (immutable, so userland writes
     copy-on-write into request memory); string and integer keys are both preserved;
   - **scalars, null and uninitialized (IS_UNDEF) slots** are self-contained byte copies
     and stay untouched.

### Cycles and DAGs

Cloning is identity-preserving over arbitrary graphs, extending the DAG-aware work of
[#97](https://github.com/lisachenko/z-engine/pull/97): identity maps keyed by source
address guarantee every source object (and array) is cloned exactly **once**. A shared
sub-object referenced from two places stays one shared clone, and a back-edge resolves to
the already-recorded clone pointer instead of recursing — the map entry is recorded
*before* the node's own edges are followed, so cyclic graphs terminate by construction.

## Supported-type matrix (enforced at put() time)

| Value | Verdict | Why |
|-------|---------|-----|
| `int`, `float`, `bool`, `null`, uninitialized typed property | supported | self-contained zval bytes |
| `string` | supported | replaced by a persistent interned block |
| `array` (any key shape, nested) | supported | replaced by a sealed persistent table |
| plain userland object (std handlers, fixed property table) | supported | byte clone + recursive slot rewrite |
| userland object with `__destruct` | supported | but destructors **never run** for persistent clones, by design |
| `Closure` (root or reachable) | rejected | captures request state: scope, bound object, compiled op_array |
| resource | rejected | wraps engine-managed request state (descriptors, streams) |
| reference (`&`) in a property or array element | rejected | aliases a request-lifetime `zend_reference` container |
| object of an **internal** class | rejected | custom handlers and engine-owned storage a byte clone cannot preserve |
| object with a non-standard handlers block (hooked classes) | rejected | the handlers pointer would dangle in the next request |
| enum case | rejected | engine-managed singleton; clone would break `===` identity. Re-attachment by case name (restoring identity instead of cloning) is possible future work |
| lazy ghost / lazy proxy | rejected | references request-lifetime initializers |
| object with dynamic properties | rejected | they live outside the fixed inline property table |
| object of a class with magic property accessors (`__get`/`__set`/…) | rejected | `ZEND_ACC_USE_GUARDS` classes carry a request-lifetime guard slot |

Every rejection throws `UnsupportedGraphElementException` with the path of the offending
value (e.g. `$root(App\Node)->slot#3[stream]`), before any allocation.

## Immortalization rules

Each cloned object follows the persistent-clone contract of
`PersistentObjectFactory` (see its class docblock for the full list):

- refcount pinned at `PIN_BASELINE` (2^29): request-time addref/delref churn from aliases
  lands on the saturated counter and can never reach zero, so no engine release path ever
  destroys the object;
- `GC_NOT_COLLECTABLE` + `GC_PERSISTENT` in the GC header: **the cycle collector never
  buffers a persistent object as a possible root and never scans into it**, so the
  collector cannot traverse the persistent region. Installing `get_gc` handlers for
  persistent objects remains a declared non-goal (#79) — the region is invisible to GC by
  construction instead;
- `IS_OBJ_DESTRUCTOR_CALLED` and `IS_OBJ_FREE_CALLED` preset: both object-store shutdown
  passes skip persistent clones even if a store bucket still references one at request
  end;
- handlers rewired to the engine's `std_object_handlers`, the only handlers block whose
  address is stable for the whole process lifetime.

Persistent strings are interned-style (immutable, non-refcounted), persistent arrays are
sealed immutable tables — both are copied into zvals without refcounting and any userland
mutation copy-on-writes into request memory, leaving the persistent block untouched.

## Where the persistent bytes come from (the allocator seam)

Every persistent block the framework mints — an object clone, an interned string block, a
hashtable struct — is allocated through a `ZEngine\Memory\Allocator`. The default is
`EngineAllocator`, which reproduces the three shapes z-engine used to hardcode (tracked
malloc, tracked request memory, untracked malloc), so a caller that passes nothing sees no
change at all.

Passing one is what lets a graph live somewhere other than the process heap — a
fork-shared mmap arena, a shared-memory segment:

```php
$graph = (new PersistentGraphCloner($arena))->persist($root);          // objects + strings + tables
$clone = PersistentObjectFactory::persistentClone($rawObject, $arena); // one object
$block = StringEntry::persistentInterned('key', $arena);               // one string
$table = new PersistentHashTable($arena);                              // one table struct
```

The interface speaks in **addresses**, never `FFI\CData` (AGENTS.md): an implementation in
a consumer package binds its own `mmap`/`shm` primitives with `FFI::cdef` and returns the
integer it computed. It also reports whether it keeps ownership of what it hands out —
`ownsAllocations()`. A structure built on such memory refuses `destroy()`: both frees
assume z-engine's own allocator, and the arena owner releases the region as a whole.

A table's struct is only half its memory; the buckets are the other half, and the engine
would `pemalloc` them on the first insert. `PersistentHashTable::installExternalStorage()`
takes that half too:

```php
$capacity = 1024;                                                  // a power of two
$address  = $arena->allocate(PersistentHashTable::externalStorageSize($capacity));
$table    = PersistentHashTable::withExternalStorage($address, $capacity, $arena);
```

The installed block carries the engine's own `HT_SIZE_EX` layout (two `uint32_t` hash slots
per bucket, reset to `HT_INVALID_IDX`, followed by the `Bucket` area), and installation is
only allowed **before the first insert**, so the engine never allocates storage of its own.
Growth is the hazard afterwards — the engine grows a full table by `perealloc`ing exactly
that block — and is guarded from both sides: inserts through the wrapper refuse the write
that would trigger the resize (`getRemainingCapacity()` reports the headroom), and
`assertNoGrowth()` diagnoses a relocation caused by engine paths the wrapper cannot
intercept.

## Storage layout and the anchor

Everything the heap needs across requests lives in engine-visible persistent memory —
PHP statics die with the request and are only used for per-request state:

- the **root registry** is a `PersistentHashTable` mapping heap key → descriptor table;
- one **descriptor** per key stores the root object pointer, the byte count and five
  integer-keyed **inventory tables**: cloned objects, their class-name strings, their
  recorded object sizes, all minted strings and all minted array tables. The inventory
  lists every malloc block of the graph exactly once (shared DAG nodes appear once),
  which is what makes eviction exact and re-attachment verifiable;
- the registry address is anchored in the globals slot of **`zengine`**
  (`ZEngineModule`), the single framework-wide engine module of the process. Since
  PHP 8.4 the module registry stores the registered `zend_module_entry` directly, so
  the entry and its globals block live for the whole process — the one address a later
  request can always find again. The module also declares a required dependency on
  `ext/ffi` and renders the live `stats()` figures into its `phpinfo()` section.

Storage is always **injected**: `ZEngineModule::heap()` recovers (or mints) the registry
from its anchor and hands it to the `PersistentHeap` constructor; tests and embedders
that manage their own anchor construct `new PersistentHeap($registryTable)` directly and
then own discovery of the registry address. The heap never creates storage silently.

A planned follow-up (tracked by the maintainer) is a Composer-level mechanism for
userland extensions to declare their modules so `ExtensionManager` registration happens
automatically at bootstrap, including user-supplied module configuration.

## Re-attachment semantics (first get() of a request)

A stored graph carries pointers minted in an earlier request; two of them are only
*conditionally* stable and are therefore verified and rewritten on the first `get()` of a
key per request:

1. **Class entries.** With `opcache.preload` (or an early-bound worker), a class entry
   keeps its address across requests; a re-compiled class gets a fresh `zend_class_entry`
   and the stored `ce` pointer would dangle. The heap therefore records each object's
   class *name* and resolves it in the current class table on every re-attachment:
   - class missing → `MissingClassException` (load the class and retry, or `remove()`);
   - `ReflectionClass::getObjectSize()` differs from the recorded size →
     `ClassLayoutChangedException` (the stored byte layout would be misread; `remove()`
     is the only safe operation left). Note the limit of this check: a layout change that
     keeps the object size identical (e.g. two same-size properties swapped) is not
     detectable and yields type-confused property values.
   Only after every class checks out are the `ce` pointers rewritten.
2. **Object-store handles.** The byte-copied handle belongs to the source's request.
   Each re-attachment registers every graph object in `EG(objects_store)`
   (`ObjectStore::put`), so aliases get fresh, collision-free `spl_object_id`s.
   Registration is verified against the store (never duplicated), and eviction returns
   the slots.

Additionally, every stored property slot is verified **by address** against the graph's
own inventory before any write: a refcounted payload written into a persistent object
during an earlier request (see "Mutation rules") points outside the inventory and is
detected as `GraphCorruptedException` — the stale pointer is never dereferenced and the
graph is refused instead of returning corrupted data. All verification passes are
read-only and run to completion before the first write, so a failed re-attachment leaves
the graph exactly as it was: still evictable, never half-attached.

Within one request, re-attachment happens once; every `get()` of the same key returns an
alias of the same `zend_object`, so `$heap->get($k) === $heap->get($k)`.

## Mutation rules

Aliases are live views of the persistent objects, so writes go into persistent memory:

- **scalar properties** (`int`, `float`, `bool`, `null`) are self-contained bytes — they
  may be mutated freely and the mutation legitimately survives into later requests;
- **refcounted overwrites** (assigning a new string/array/object to a property of a
  persistent object) store a *request-lifetime* pointer into persistent memory. Within
  the current request everything works; at the next re-attachment the slot is detected
  and refused with `GraphCorruptedException`. Treat stored graphs as read-mostly: to
  change their shape, build the new graph and `put()` it again;
- mutating an **array property** (`$root->items[] = …`) copy-on-writes into a request
  array and then falls under the previous rule when the separated array is written back
  to the slot.

## Lifecycle and ordering vs. Core::shutdown()

The heap is wired through the module lifecycle hooks (`RequestStartupHook` /
`RequestShutdownHook`); the delivery guarantees are documented in
[`long-running.md`](long-running.md#module-lifecycle-callbacks). The ordering at real
request end is:

1. `Core::shutdown()` runs first (it was registered as the first user shutdown function):
   every hooked engine pointer is restored and **all engine writes stop**;
2. the module's `requestShutdown()` is delivered right after it: the heap turns **inert**
   — every operation throws `HeapInertException` until the next request startup, because
   no engine memory may be written anymore. Only PHP-side per-request state is dropped;
3. the engine's own store shutdown passes then run; persistent clones are skipped via the
   preset `IS_OBJ_*_CALLED` flags even where a store bucket still references one.

Worker managers that cycle handled requests inside one live process-level request
(simulated RSHUTDOWN/RINIT, engine still writable) get the richer path: on
`requestShutdown()` the heap additionally releases materialized property caches and
returns its object-store slots, keeping the store and the request heap flat across
cycles.

**The invariant behind the split:** after a *real* engine shutdown (`Core::isShutdown()`
is true) nothing engine-owned may be touched anymore — the heap drops only PHP-side
state. In a *simulated* cycle the engine is still live and writable, so the heap may
(and does) recycle store handles and release request-owned caches. The heap
distinguishes the two cases by `Core::isShutdown()` at delivery time.

`get()`/`put()`/`remove()` may be called at any point of a live request — including
before the first hook delivery of a request; the heap re-attaches lazily per key.

## Eviction and teardown

`remove($key)` frees every block of the graph exactly once, driven by the inventory:
cloned array tables are dismantled with `PersistentHashTable::destroy()`, object and
string blocks with `Core::persistentFree()` (see the
[dismantling table](long-running.md#dismantling-persistent-data-cross-request-free)).
Guards:

- `HeapKeyNotFoundException` when the key does not exist;
- `HeapInUseException` when any object of the graph still has live userland aliases
  (refcount above the pin baseline) — freeing under a live alias would dangle it, so the
  eviction refuses; release the aliases first. `put()` on an existing key evicts the old
  graph first and follows the same rule.

`destroy()` evicts every key and dismantles the registry table itself; the module
clears its anchor, so the next `heap()`/`global()` call mints a fresh registry. The
module entry stays registered — module entries are immortal by design.

### What stays immortal

The heap's graphs are *droppable*, not immortal: `remove()` returns their memory to the
allocator (verified by the leak-plateau test — repeated put/get/remove cycles keep
process RSS flat). The immortal remainder is bounded and listed in the
[immortal-allocations table](long-running.md#immortal-by-design-allocations): the
`zengine` module entry with its globals anchor, the registry table while the heap
exists, and the process-wide `uninitialized_bucket` sentinel.

One debug-build caveat: reading operations that materialize an object's property cache
(`var_dump`, `foreach`, `get_object_vars`, `(array)` casts on aliases) allocate a
request-lifetime cache table the engine attaches to the persistent object. Eviction and
simulated request cycles release it; at a *real* request end the heap is already inert
when its shutdown hook runs, so the cache is reclaimed wholesale by the request allocator
— on debug builds `report_memleaks` will report it for graphs that were read but neither
removed nor cycled. Release builds are unaffected.

## Statistics

`stats()` returns totals and a per-key breakdown:

```php
[
    'keys'    => 2,
    'objects' => 7,       // cloned zend_objects
    'strings' => 19,      // minted persistent interned strings (incl. key + class names)
    'arrays'  => 3,       // minted persistent tables
    'bytes'   => 4312,    // directly allocated payload bytes recorded at put() time
    'perKey'  => ['routing-tree' => ['objects' => …, 'strings' => …, 'arrays' => …, 'bytes' => …], …],
]
```

`bytes` counts the blocks the heap allocates directly (objects, string blocks, table
structs); data blocks the engine grows for persistent tables are malloc-backed as well
but not included in the figure.

## Error taxonomy

All heap failures extend `ZEngine\Memory\PersistentHeapException`:

| Exception | Raised by | Meaning |
|-----------|-----------|---------|
| `UnsupportedGraphElementException` | `put()` | a reachable value is outside the supported-type matrix |
| `HeapKeyNotFoundException` | `remove()` | the key does not exist |
| `HeapInUseException` | `remove()`, overwriting `put()`, `destroy()` | live aliases still reference the graph |
| `HeapInertException` | any operation | request is shutting down; engine writes are forbidden |
| `MissingClassException` | `get()` re-attachment | a recorded class is not defined in this request |
| `ClassLayoutChangedException` | `get()` re-attachment | a recorded class changed its object size |
| `GraphCorruptedException` | `get()` re-attachment | a stored slot points outside the graph inventory (refcounted mutation in an earlier request) |
