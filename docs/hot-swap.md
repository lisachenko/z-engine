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
  and sized for one specific body, so every swap installs a fresh zeroed
  `ZEND_ACC_HEAP_RT_CACHE` cache that the engine (or the next swap) releases.
- **Static variables are unshared.** A closure destroys its own static table
  when it dies, so the entry duplicates the defaults (`zend_array_dup`) and
  lets the live per-entry table materialize lazily on the first
  `ZEND_BIND_STATIC`, exactly like a plain compiled function. The class entry
  is flagged with `ZEND_HAS_STATIC_IN_METHODS` so the engine's shutdown walk
  releases the live table.
- **Declaration identity is preserved.** The entry keeps its name, scope,
  prototype and declaration-level flags (visibility, static, final);
  body-level flags (variadic, generator, return type, strict types) follow the
  new body.

The internal-function branch is unchanged: an internal function's `handler` is
replaced with a trampoline that calls the closure.

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
| Immutable **global function** + `redefine()` | Supported via copy-out: the per-process function-table bucket is repointed at a writable `zend_function` copy; the SHM original stays untouched and allocated. The first swap does not destroy the previous (SHM) body; later swaps behave normally. |
| Immutable **class**: method `redefine()`, `addMethod()`, `removeMethods()`, trait configuration, `HotSwap::prepare()` | Supported via class copy-out: the class entry is deep-copied into request memory with the [class-specialization](class-specialization.md) copy model (own tables and property/constant blocks, method entries duplicated at the `zend_op_array` level with the compiled bodies still shared with SHM), the class-table bucket and the engine's fast class-name cache are repointed at the copy, and the mutation is applied to it. The copy is an ordinary userland class the engine dismantles at request end. |
| Immutable **preloaded** class (`ZEND_ACC_PRELOADED`) | Rejected with `SharedMemoryException`: a preloaded class keeps its class-table bucket across the requests of a worker, while the copy lives in request memory - repointing the bucket would leave it dangling for the next request. |
| Immutable class the copy machinery does not support (enum/interface/trait, property hooks, internal ancestor or internal methods) | Rejected with `SharedMemoryException` carrying the refusal reason. |
| Class observed **mid-linking** on an opcache lazy-linking temporary (`ZEND_ACC_CACHED` set, `ZEND_ACC_LINKED` clear - the only state an `interface_gets_implemented` hook ever sees for a cached implementor) | Handler installation (`setXxxHandler()`, `installExtensionHandlers()`) rejected with `SharedMemoryException`: handlers are keyed by class-entry address and the temporary is discarded when opcache's inheritance cache persists the linked class, so they would be silently lost ([#238](https://github.com/lisachenko/z-engine/issues/238)). Probe with `ReflectionClass::isLazyLinkingCopy()`; install after linking completes, or run the bootstrap with opcache off. [#241](https://github.com/lisachenko/z-engine/issues/241) tracks making the installation stick by declining the inheritance cache for hooked classes. |
| Runtime-declared functions/classes (never in SHM, even with opcache enabled) | Full mutation surface. |
| Static variables of an immutable function | Readable: `getStaticVariables()` follows the map-ptr offset slot opcache stores into shared op_arrays and returns the live per-process table once the first call materialized it (the declaration defaults before that). |

`SharedMemoryException` and `HotSwapException` both extend
`ReflectionException`, so existing catch blocks keep working while the failure
modes stay distinguishable.

### Copy-out caveats

A copy-out changes which `zend_class_entry` the class name resolves to, and
only *resolution* is redirected - structures that captured the shared entry
earlier keep it. Copy out (or mutate) at bootstrap, before such state exists:

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
- **Per-request only**: the copy dies with the request, and the next request of
  the same worker starts from the shared-memory class again - apply the
  mutation on every request (bootstrap), exactly like for a runtime-declared
  class.

## Memory footprint

- `redefine()` and `ClassDelta` **body swaps are memory-flat**: each swap
  releases the previous body, its heap run-time cache and its static tables.
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
  caches of *other* functions that call the swapped one are untouched but safe,
  because the entry pointer (what those caches store) is preserved.
- **`Closure::fromCallable()` over a later-swapped method**: fake closures
  share the old body and keep it alive through its refcount; they continue to
  execute the old body (and its static variables) until released.
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
  buckets that copied the pointer at link time.
