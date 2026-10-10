# Runtime function and class hot-swap

z-engine can replace the body of a loaded function or method in place
(`redefine()`) and apply a whole-class delta computed from freshly written
source (`HotSwap::prepare()` / `ClassDelta::apply()`). This page is the
contract: what each operation does to engine memory, what is supported under
opcache, and which allocations stay behind by design.

## Function/method body swap (`redefine()`)

`ReflectionFunction::redefine()` and `ReflectionMethod::redefine()` replace a
user function's body with the compiled body of a closure, **in place**: the
published `zend_function` pointer never changes, so warmed-up inline caches,
subclass method buckets and prototype links all stay valid and immediately
dispatch the new body. The implementation is centralized in
`FunctionBodySwap` and follows these rules:

- **The entry owns its share of the new body.** The closure body op_array is
  refcounted; the entry takes one reference per published bucket that points at
  the entry structure (its own bucket plus every inherited-method bucket of a
  subclass, mirroring `zend_duplicate_function`). The donor closure may be
  garbage-collected at any time afterwards.
- **The previous body is destroyed** with engine semantics
  (`destroy_op_array`): opcodes, literals, compiled variables, `arg_info`,
  static-variable tables and the heap run-time cache of the old body are
  released the moment the swap completes. The entry keeps its owned reference
  on the function name; a previous body still shared with someone else (a live
  closure template, a fake closure) survives through its own refcount and is
  freed by its last holder. Repeated `redefine()` cycles on the same entry are
  **memory-flat** - the leak-plateau test drives 1000 cycles and measures zero
  overhead beyond the engine's own per-`eval` compile cost.
- **The run-time cache is reset per swap.** Inline caches are scope-dependent
  and sized for one specific body, so every swap installs a fresh zeroed cache
  that the next swap releases - after detaching the engine copies of the entry
  that still point at it (fake closures, interface clones), see "Run-time
  caches of swapped and published entries" below.
- **Static variables are unshared - the live table only.** The entry's
  `ZEND_MAP_PTR` slot is dropped so the live per-entry table materializes
  lazily on the first `ZEND_BIND_STATIC`, exactly like a plain compiled
  function. The defaults table behind it stays shared and is guarded by the
  body refcount alone: on PHP 8.5 no donor kind owns it
  (`zend_create_closure_ex` duplicates the prototype defaults into the map
  slot only), so the last body holder frees it exactly once - see
  `FunctionBodySwap::unshareStaticVariables()` for the ownership rules. The
  class entry is flagged with `ZEND_HAS_STATIC_IN_METHODS` so the engine's
  shutdown walk releases the live table. The previous body's materialized live
  table is destroyed with the swap unless a fake closure or an interface clone
  still borrows it; a preserved alias takes it over instead (see `preserveAs`).
- **Declaration identity is preserved.** The entry keeps its name, scope,
  prototype and declaration-level flags (visibility, static, final);
  body-level flags (variadic, generator, return type, strict types) follow the
  new body.

The internal-function branch is unchanged: an internal function's `handler` is
replaced with a trampoline that calls the closure.

### Method donors

`redefine()` also accepts another **user method or function** as the donor
(`\Closure|FunctionLikeInterface`): the body of a method compiled in a separate
"donor" class is adopted the same way a closure body is.

```php
$live  = new ReflectionClass(Service::class);
$donor = new ReflectionClass(ServiceDonor::class);
$live->getMethod('handle')->redefine($donor->getMethod('handle'));
```

- The donor must carry the entry's **exact signature** (compared as the engine
  resolved it: parameter and return types, by-reference passing, variadics) and
  its static-ness, and may not be abstract or internal.
- `self`/`parent` types are resolved to the **donor's** declaring class by the
  compiler and keep enforcing it after the swap, so a donor spells the entry's
  class explicitly where the entry says `self`; only `static` is late-bound.
- `self::`, `parent::`, `static::` and `$this` inside the donor body resolve
  against the entry's class at run time (the body runs in the entry's scope);
  `self::class` and `__CLASS__` are compile-time literals of the donor class.
- The signature (`arg_info`, including parameter defaults), the attributes, the
  doc comment, the file name and the line numbers all travel with the donor body.

### Preserving the previous body (`preserveAs`)

`redefine($newBody, preserveAs: 'name')` keeps the previous body callable: it is
published under that name **before** the swap, as a private method of the
declaring class (or as a second global function for a free function). The alias
is a regular entry with the previous body, signature and static-ness - a backup
the new body can call, and that can be swapped back in:

```php
$method = new ReflectionMethod(Service::class, 'handle');
$method->redefine($donor->getMethod('handle'), preserveAs: 'handleOriginal'); // swap in
// ... the new body may call $this->handleOriginal(...) ...
$method->redefine($live->getMethod('handleOriginal'));                        // swap back
$live->removeMethods('handleOriginal');                                        // drop the backup
```

What the alias publication does, in engine terms:

- The alias is a new `zend_function` container allocated from **request
  memory** (the engine never frees user function containers itself, exactly
  like its own arena-allocated op_arrays), adopting the previous body with its
  own body share and name - so the previous body survives the swap that
  destroys the entry's share. Per-request re-publication (an FPM worker
  redefining on every request) therefore stays memory-flat across requests.
- It is **private**, never a constructor (`ZEND_ACC_CTOR` cleared) and never
  final. When an ancestor already declares a private method of the alias name,
  the alias carries `ZEND_ACC_CHANGED` exactly like a redeclared private method,
  so the ancestor's own body still resolves the ancestor's alias on instances
  of the subclass (`zend_get_parent_private_method`).
- **The alias takes over the live static variables** of the body it preserves:
  the `static` state the previous body accumulated keeps counting when the alias
  is called, while the entry's new body starts afresh (and so does the previous
  body once it is swapped back in from the alias, as a method donor).
- **Every subclass linked before** receives the alias in its method table, with
  the ownership an inherited bucket holds (`zend_duplicate_function` semantics)
  - private methods are inherited into child tables at link time, and the
  preserved body runs on instances of every subclass: through the shared entry
  pointer, through an interface clone (see below) or through `parent::` from an
  overriding subclass, and it would otherwise call an alias the subclass lacks.
  A subclass that declares a private method of the alias name itself keeps it
  and gets `ZEND_ACC_CHANGED`, the engine's marker for a redeclared
  parent-private method.
- `removeMethods()` of the alias (the "drop the backup" step) releases what the
  engine's bucket destructor does not reach: the alias' materialized live
  statics and its minted run-time cache - unless a fake closure made over the
  alias still shares them. The container itself stays request memory (the
  documented baseline per publication).
- Refused with `RedefinitionException` (nothing modified): an alias name the
  class already declares (a second preserving `redefine()` of the same method
  needs another name, or the first alias restored/removed first), an alias name
  colliding with an inherited **non-private** method, an invalid name, a global
  function name that already exists, and an internal entry (no op_array to
  publish).
- Under opcache the declaring class is copied out of shared memory first (see
  below); publishing is refused with `SharedMemoryException` while **any**
  subclass linked against the shared class exists - whether it inherits the
  entry pointer, holds a clone of it or overrides the method - because that
  subclass keeps the shared parent and could never see the alias. Redefine
  before subclasses link.

### Overriding an inherited method (`addMethod()` with a donor)

`ReflectionClass::addMethod()` accepts a donor method as well. For a name the
class only **inherits**, the donor body is published as an own entry that
overrides the inherited one with the engine's inheritance rules: the prototype
is kept (reflection and `parent::` keep working), a final method cannot be
overridden, visibility cannot be narrowed, static-ness must agree, and a method
that is private in the ancestor is merely redeclared (`ZEND_ACC_CHANGED`) - a
bit the override also inherits from an inherited method that carries it, so an
ancestor's own code keeps resolving its own private method of that name on
instances of the class, as it does for compiled overrides. The class's magic
slots that referenced the inherited entry (constructor, `__get`, ...) follow
the override, and so do the method tables and magic slots of subclasses linked
before that still dispatch the ancestor's declaration - through the inherited
entry pointer or through an interface clone of it. A name the class already
declares itself is refused - that is `redefine()`'s job.

```php
// Child inherits handle() from Base; the donor reaches the base body through parent::
$childDonor = new ReflectionClass(ChildDonor::class); // class ChildDonor extends Base { ... parent::handle() ... }
(new ReflectionClass(Child::class))->addMethod('handle', $childDonor->getMethod('handle'));
```

### Run-time caches of swapped and published entries

Every swapped entry and every published container gets a fresh, zeroed run-time
cache allocated from request memory and referenced **without**
`ZEND_ACC_HEAP_RT_CACHE`. A heap-flagged cache is `efree()`d by
`destroy_op_array` before its refcount check - once per method table that
publishes the structure - so an entry shared by a parent and a subclass table
(every subclass linked after a swap, every alias inherited by a subclass) would
have the same block freed twice at a destructor-path shutdown. The cache is
therefore never left to the engine: the next swap of the same entry releases it
(repeated swaps stay memory-flat) and the request end reclaims the rest. It is
never left `NULL` either: a call site whose inline cache already resolved the
entry skips the lazy initialization.

The engine copies the entry structure - cache pointer included - wherever it
needs a `zend_function` of its own, so before a block is released its copies
are found and detached (`FunctionBodySwap::releaseRetiredRunTimeCache()`):

- a **fake closure** made over the entry while the block was installed
  (`Closure::fromCallable()`, a first-class callable `$obj->m(...)`,
  `ReflectionMethod::getClosure()`) shares a scope-matching non-heap cache by
  engine rule; it receives a private heap copy of its warmed slots, flagged
  `ZEND_ACC_HEAP_RT_CACHE`, which `destroy_op_array` frees together with the
  closure - exactly what the engine gives a closure over a heap-cached function;
- an **interface clone** of the entry (see "Subclass tables" below) gets a
  fresh minted cache of its own;
- a **frame on the current stack** executing on the block (a fake closure of
  the entry redefining it, or its caller chain) keeps the block allocated,
  bounded to one block per such in-flight redefinition, like the previous body
  itself.

Finding those copies walks `EG(objects_store)` (`ObjectStore::instancesOf()`)
and the class table once per released block.

## Class hot-swap (`HotSwap` / `ClassDelta`)

```php
use ZEngine\HotSwap\HotSwap;

$delta = HotSwap::prepare(Service::class, $newClassSource);
$delta->getChangedMethods();   // introspect before applying
$delta->apply();               // atomic: all of it or none of it
```

`prepare()` validates the source with the engine's own parser
(`Compiler::parseString()`), then compiles it into a hidden **donor class
entry**: the live class is unpublished from the class table around one
`eval()` of the source (so the engine accepts the redeclaration), the donor is
unpublished again and the live entry republished - nothing is destroyed by the
shuffle. The delta between the live entry and the donor is computed
conservatively (anything not provably identical counts as changed).

`apply()` is a stage-then-commit protocol. Every operation records an undo
action; nothing owned by the previous class state is released before all
operations succeed. On any failure the undo actions run in reverse order and
the class is restored byte-exact - **no half-swapped class is ever
observable**. On success the previous bodies/values are released and the donor
entry is destroyed (`destroy_zend_class`), so repeated prepare/apply cycles do
not accumulate donor classes.

Installed z-engine object handlers and hooks are untouched by design: the
class entry pointer never changes and no handler field is written.

### Delta operations

| Operation | Semantics |
|-----------|-----------|
| Changed method body | In-place swap (see above). The new source's declaration is authoritative: signature, visibility and other declaration flags follow the donor. Propagates to subclasses that inherited the method (shared structure). |
| Added method | A writable immortal container adopting the donor body is published in the method table. Already-linked subclasses do not see it (inheritance is materialized at link time). Magic methods and constructors cannot be added. |
| Removed method | The bucket is unpublished; the structure and body stay allocated for the rest of the request (warmed-up inline caches and subclass buckets may still reference them). New lookups fail with the ordinary "undefined method" error. If an ancestor declares the method, the ancestor entry becomes visible again. Magic methods and constructors cannot be removed. |
| Changed constant | The constant's value zval is replaced in place (access flags follow the donor value). Constant expressions re-evaluate lazily; `ZEND_ACC_CONSTANTS_UPDATED` is cleared so the engine re-runs its update pass. |
| Added constant | An immortal `zend_class_constant` container adopting the donor constant is published. |
| Changed default property value | The slot in `default_properties_table` (or `default_static_members_table` for static declarations) is replaced. Live objects keep their current property values; statics that were already materialized keep their runtime values. |

### Validation (rejected with `HotSwapException`)

- unknown/internal/unlinked classes; interfaces, traits and enums;
- a source that does not parse or does not declare the target class
  (fully-qualified name must match; add the `namespace` statement for
  namespaced classes);
- hierarchy changes: different parent class or interface set;
- property surface changes: added, removed, reordered or re-typed property
  declarations (the slot layout of live instances cannot change);
- constant removal;
- adding an override of an inherited method;
- adding or removing magic methods/constructors.

The source is `eval()`ed once during `prepare()`: it must contain the class
declaration (plus its `namespace` statement when needed) and nothing that
collides with loaded code. A class that cannot link (for example a missing
abstract method of an implemented interface) aborts compilation with the
engine's fatal error, which is not catchable - validate such sources upstream.

## Opcache shared memory (support matrix)

Opcache publishes cached scripts from shared memory and marks their class
entries and functions `ZEND_ACC_IMMUTABLE`. SHM is visible to every worker
process, so z-engine never writes it and never frees it. Every mutation API
instead **copies the target out of shared memory** into a writable per-process
structure and repoints the per-process bucket that publishes it; the shared
original is left byte-for-byte untouched and simply stops being published in
this process (opcache republishes it into a fresh class/function table on the
next request). The wrappers expose the detection as
`ReflectionClass::isImmutable()` and
`ReflectionFunction`/`ReflectionMethod::isImmutable()`, and the copy-out itself
as `ReflectionClass::copyOutOfSharedMemory()` (functions:
`FunctionLikeTrait::copyOutOfSharedMemory()`, run from inside `redefine()`).
Generating, reading and patching the on-disk cache binaries themselves is
covered in [opcache-binary.md](opcache-binary.md).

| Target | Behaviour |
|--------|-----------|
| Immutable **global function** + `redefine()` | Supported via copy-out: the per-process function-table bucket is repointed at a writable `zend_function` copy; the SHM original stays untouched and allocated. The first swap does not destroy the previous (SHM) body; later swaps behave normally. Only *name resolution* is redirected - call sites that already resolved the function, and call sites the optimizer inlined at cache time, keep the original body (see the copy-out caveats). |
| Immutable **class**: method `redefine()` (with or without `preserveAs`), `addMethod()` (closure or donor, including inherited-method overrides), `removeMethods()`, trait configuration, `HotSwap::prepare()` | Supported via class copy-out: the class entry is deep-copied into request memory with the [class-specialization](class-specialization.md) copy model (own tables and property/constant blocks, method entries duplicated at the `zend_op_array` level with the compiled bodies still shared with SHM), the class-table bucket and the engine's fast class-name cache are repointed at the copy, and the mutation is applied to it. The copy is an ordinary userland class the engine dismantles at request end. |
| Immutable **preloaded** class (`ZEND_ACC_PRELOADED`) | Rejected with `SharedMemoryException`: a preloaded class keeps its class-table bucket across the requests of a worker, while the copy lives in request memory - repointing the bucket would leave it dangling for the next request. |
| Immutable class the copy machinery does not support (enum/interface/trait, property hooks, internal ancestor or internal methods) | Rejected with `SharedMemoryException` carrying the refusal reason. |
| Class observed **mid-linking** on an opcache lazy-linking temporary (`ZEND_ACC_CACHED` set, `ZEND_ACC_LINKED` clear - the only state an `interface_gets_implemented` hook ever sees for a cached implementor) | Supported via **inheritance-cache decline** ([#241](https://github.com/lisachenko/z-engine/issues/241)): handlers are keyed by class-entry address, and without intervention the temporary would be discarded as soon as opcache's inheritance cache persists the linked class, silently losing them ([#238](https://github.com/lisachenko/z-engine/issues/238)). So handler installation (`setXxxHandler()`, `installExtensionHandlers()`) records the entry, and z-engine's interceptor over `zend_inheritance_cache_add` answers NULL for it when linking completes - the engine's ordinary "not cached" outcome (opcache itself returns it when SHM is full). The temporary then stays in the class table as a process-local, request-lifetime class: the handlers keep firing, and the class simply pays re-linking per process/request instead of being reused from the cache. Unhooked classes delegate to opcache unchanged and keep full cache reuse. FPM-safety: because the hooked class is never published, no per-process trampoline or handlers-block address ever reaches shared memory (publishing the handlers through the class entry instead was rejected for exactly that hazard). Probe with `ReflectionClass::isLazyLinkingCopy()`. Fallback: on a platform whose generated engine definitions predate the `zend_inheritance_cache_add` export, the installation still throws `SharedMemoryException` instead of being silently lost - regenerate with `composer gen-headers`. |
| Runtime-declared functions/classes (never in SHM, even with opcache enabled) | Full mutation surface. |
| Static variables of an immutable function | Readable: `getStaticVariables()` follows the map-ptr offset slot opcache stores into shared op_arrays and returns the live per-process table once the first call materialized it (the declaration defaults before that). |

`SharedMemoryException` and `HotSwapException` both extend
`ReflectionException`, so existing catch blocks keep working while the failure
modes stay distinguishable.

The file-cache bridge `CacheImageSync` (see
[opcache-binary.md](opcache-binary.md)) drives this same machinery from a
patched cache image instead of a closure/source donor: changed image bodies
are swapped into the already-loaded entries through `FunctionBodySwap`, with
opcache-shared targets copied out of SHM by the exact paths above — every
row of this matrix, including the refusals, applies to it unchanged.

### Copy-out caveats

A copy-out changes which structure (`zend_class_entry`, `zend_function`) the
*name* resolves to, and only resolution is redirected - structures that
captured the shared entry earlier keep it. Copy out (or mutate) at bootstrap,
before such state exists:

- **Instances created before the copy-out** keep the shared class entry in
  `obj->ce`: they dispatch the old method bodies and are not `instanceof` the
  copy. The same holds for subclasses that were already linked against the
  shared entry (they keep the shared parent and its inherited method entries)
  and for call sites whose run-time cache already resolved the class.
- **Static properties** materialized before the copy-out stay with the shared
  entry; the copy re-materializes its statics from the declared defaults.
- **Differently-cased references**: the engine memoizes "class name string →
  class entry" in a per-request cache slot on the interned name string. The
  copy-out refreshes the slot of the declared class name, which is what every
  call site spelling the class the way it was declared uses; a call site that
  already resolved the class through another spelling (`new foo\bar()` for
  `Foo\Bar`) in this request keeps the shared entry.
- **Call sites that already resolved the function**: the engine memoizes the
  resolved `zend_function*` in the caller's run-time cache the first time a
  call site executes. A caller that already called the function in this
  request keeps dispatching the shared-memory entry after a function copy-out
  (the function-side twin of the "instances created before the copy-out"
  rule). Redefine at bootstrap, before the call sites warm up.
- **Optimizer-inlined call sites**
  ([#242](https://github.com/lisachenko/z-engine/issues/242)): when opcache
  caches a script, its optimizer *inlines* a same-file call to a function
  whose body merely returns a literal - the call site is replaced by the
  constant (`zend_try_inline_call`, optimizer pass 4, part of the default
  `opcache.optimization_level`), and method calls can be folded the same way
  when the optimizer proves the receiver's class. Such call sites do not exist
  in the compiled code at all, so no `redefine()` - before or after they run -
  can ever affect them; only truly dynamic calls (`$name()`, a runtime
  callable) always resolve at runtime. Give a redefine target a body the
  optimizer cannot pre-evaluate (for example, return a runtime-defined
  constant), declare it in a different file than its callers, or mask the pass
  out (`opcache.optimization_level=0x7FFEBFF7`).
- **Per-request only**: the copy dies with the request, and the next request of
  the same worker starts from the shared-memory class again - apply the
  mutation on every request (bootstrap), exactly like for a runtime-declared
  class.

## Memory footprint

- `redefine()` and `ClassDelta` **body swaps are memory-flat**: each swap
  releases the previous body, its minted run-time cache and its static tables.
  This holds for donors declared in opcache-cached files too: their compiled
  arrays live in shared memory and are never freed, but the per-entry
  run-time cache minted by the swap is released. Two bounded exceptions keep
  memory of the previous body alive on purpose: a run-time cache a frame on
  the current stack still executes on (one block per in-flight redefinition),
  and a live statics table a fake closure still borrows (it would be freed from
  under the closure otherwise; it leaks when the closure dies without being
  the body's last holder).
- Each `HotSwap::prepare()` costs one class compilation. The engine allocates
  the op_array/class-entry **containers** from the request arena, which is
  only reclaimed at request end (~1 KiB per prepare for a small class, the
  same cost `eval`ing the class anywhere else would have). All the *bodies*
  are refcount-managed and freed by the swap machinery.
- Bounded immortal-by-design allocations (full table in
  [docs/long-running.md](long-running.md)): copied-out SHM function
  containers, added-method/added-constant containers, removed-method bodies.

## Interactions to be aware of

- **Run-time cache invalidation**: swapped entries always get a fresh cache;
  caches of *other* functions that call the swapped one are untouched but safe
  for in-place swaps, because the entry pointer (what those caches store) is
  preserved. The shared-memory copy-out path publishes a *new* pointer instead,
  so callers that already resolved the old one keep it - see the copy-out
  caveats above.
- **`Closure::fromCallable()` over a later-swapped method**: fake closures
  (also first-class callables and `ReflectionMethod::getClosure()`) share the
  old body and keep it alive through its refcount; they continue to execute the
  old body, with its static variables and a private copy of the inline caches
  they warmed, until released. A fake closure made over a preserved alias keeps
  the alias' live statics alive past `removeMethods()`.
- **In-flight frames of the swapped entry**: the swap machinery walks the
  current VM call stack before destroying a previous body. If the redefined
  function is still executing (it redefined itself, directly or through a
  callee), the previous body is kept allocated instead of freed - the running
  frame completes on the old opcodes and the next call dispatches the new body
  (bounded: one retained body per such in-flight redefinition).
- **Generators/fibers mid-flight**: suspended frames that are NOT part of the
  current call stack cannot be discovered by that walk. Do not swap a function
  that has suspended generator/fiber frames unless something else owns the old
  body (for example a fake closure) - the swap destroys the previous body when
  it is unshared.
- **Subclass tables**: body swaps propagate to subclasses (shared structures);
  added methods do not; removed methods stay reachable through subclass
  buckets that copied the pointer at link time. One inheritance shape does not
  share the structure: a subclass that implements an interface declaring an
  inherited method gets an **interface clone** - its own `zend_function` copy of
  the inherited entry (`do_inheritance_check_on_method`), with the ancestor's
  scope and name, the interface method as prototype and a share on the body of
  that moment. `FunctionBodySwap::cloneHolders()` finds those clones, and they
  are kept in step: a `redefine()` of the ancestor's method swaps every clone in
  place too, an `addMethod()` override replaces them like pointer-sharing
  buckets, a preserved alias reaches their classes, and the shared-memory
  guards count them. A clone inside a shared-memory subclass is never written -
  that subclass keeps the previous body, like every subclass linked against a
  shared entry.
