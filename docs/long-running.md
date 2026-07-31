# Memory ownership and long-running PHP

z-engine writes into live engine structures, so it has to be explicit about who owns every
byte it touches. This document describes the ownership model introduced by the
memory-lifetime overhaul ([#62](https://github.com/lisachenko/z-engine/issues/62)) and how to
run z-engine safely in long-running processes (worker loops, FPM with opcache preload).

## The ownership model

Every value wrapper carries two orthogonal ownership bits:

| Bit | Meaning | Cleanup |
|-----|---------|---------|
| `ownsContainer` | z-engine allocated the container CData (eg the 16-byte zval box) | freed through the FFI allocator |
| `ownsReference` | the wrapper holds exactly one reference on the refcounted payload | dropped exactly once through the engine primitives (`zval_ptr_dtor` / `rc_dtor_func`) |

The engine refcount stays the single source of truth. Because every owning wrapper holds
*its own* reference, two wrappers aliasing the same pointer can never double-free.

Which constructor you use decides what you own:

| Construction | Ownership | Lifetime behaviour |
|--------------|-----------|--------------------|
| `new ReflectionValue($x)`, `new StringEntry($s)`, `new ObjectEntry($o)`, `new ResourceEntry($r)`, `new ReferenceEntry($ref)` | owning | addref on construction, automatic release on destruction (or explicit `release()`) |
| `StringEntry::fromString($s)` | owning | fresh refcount-1 emalloc string, never aliases caller memory |
| `StringEntry::persistent($s)` | owning | fresh refcount-1 malloc string for sinks inside persistent engine structures |
| `*::fromCData($ptr)`, `ReflectionValue::fromValueEntry($ptr)` | borrowed | no addref, `release()` is a no-op, the caller guarantees the pointer stays valid |
| `ObjectEntry::weakFor($o)` | borrowed + guarded | no addref, but every access after the object died throws instead of dereferencing a dangling pointer |

Rules of thumb:

- `release()` is idempotent; after it, any access to an owning wrapper throws.
- Handing a pointer to an engine structure that will release it later (a class entry field,
  an AST node, a hashtable) goes through `transferReferenceOwnership()` — the wrapper keeps
  the pointer readable but no longer drops the reference; the engine sink does.
- Manual refcount surgery on engine-owned references uses
  `releaseReference()` — full engine semantics: interned/immutable payloads untouched,
  destruction at refcount zero via `rc_dtor_func`, persistent blocks never freed with the
  request allocator.
- Setters with engine sinks (`ReflectionClass::setFileName()`, `ClosureEntry::setThis()`,
  `DeclarationNode::setName()/setDocComment()`, `ReflectionValue::setNativeValue()`) release
  the previous value and store an owned replacement — callers no longer need to keep source
  values alive.
- `ReflectionValue::initializeNativeValue()` exists for uninitialized engine output slots
  (`cast_object` retval, `do_operation` result), where there is no previous value to release.

### Parsed ASTs

`Compiler::parseString()` returns a tree whose arena and payload references are owned by an
`AstOwnership` handle that travels with every node materialized from the tree. The whole tree
is destroyed (`zend_ast_destroy` + arena free) when the last wrapper is collected. Two
consequences:

- keep a node (any node) of the tree alive for as long as you read from it;
- do **not** graft nodes from a parsed tree into the live compilation AST (`getAST()` /
  `AstProcessHook`) — the detached tree will be destroyed independently. Build fresh nodes
  inside the AST process handler instead.

## Hook lifecycle

`install()` replaces an engine function pointer with a libffi trampoline and registers the
hook in a Core-level registry that keeps it alive. `uninstall()` restores the original
pointer; only the most recently installed hook of a field may be uninstalled (out-of-order
uninstalls throw). `reinstall()` mints a fresh trampoline.

`Core::shutdown()` — registered automatically via `register_shutdown_function` in
`Core::init()` — unwinds all hook chains in reverse installation order. User shutdown
functions run before object destructors and before ext/ffi frees the callback trampolines,
so every hooked engine pointer is restored while writing it is still safe.

**Invariant: no trampoline pointer survives `Core::shutdown()` in any structure that
outlives the request.** This is what makes FPM + opcache preload safe: persistent engine
structures (class entries, handler blocks) never carry a pointer into the next request's
freed trampolines. After shutdown, z-engine performs no engine writes at all — hooks are
inactive during shutdown-phase object destructors, and installing a new hook throws.

### Runtime models

- **Worker loops** (RoadRunner, Swoole, ReactPHP, FrankenPHP worker mode): the whole worker
  is one PHP request. Install hooks once at boot; `Core::shutdown()` runs at worker exit.
- **Classic FPM + opcache preload**: call `Core::preload()` in the preload script, then
  `Core::init()` and hook installation happen per request; `Core::shutdown()` guarantees the
  per-request trampolines are gone from persistent structures before the request ends.
- **SAPIs that cycle FFI callback state between handled requests**: call
  `Core::reinstallHooks()` at the start of each handled request. Prefer a single hook per
  engine field in such setups — stacked chains keep intermediate `proceed()` targets from
  the previous cycle.

## Immortal-by-design allocations

The debug-build leak gate treats the following as expected, by design:

| Allocation | Why it stays |
|------------|--------------|
| Module entries, module name/globals buffers (`AbstractModule`) | the engine module registry references them for the process lifetime |
| One `zend_object_handlers` block per hooked class entry | objects still dereference `->handlers` after user shutdown functions ran, so freeing at shutdown would be a use-after-free; blocks are malloc-backed, keyed by class entry address, and bounded |
| One live libffi trampoline per installed hook | owned by ext/ffi, freed by its RSHUTDOWN |
| One `zend_object_iterator_funcs` vtable for the get-iterator bridge (`IteratorBridge`) | live engine iterators dereference `->funcs` for their whole lifetime; the block is a malloc-backed process-wide singleton filled with libffi trampolines (trampolines themselves owned by ext/ffi). The bridge registers in the Core hook registry, so `Core::shutdown()` neutralizes surviving iterators (drops their cached current-value reference, swaps their handlers to `std_object_handlers`) while trampolines are still alive, and `Core::reinstallHooks()` re-mints the vtable for cycling SAPIs |
| Closures immortalized by `ReflectionClass::addMethod()` | the method table references the closure body for the rest of the request |
| Previous function body after `redefine()` | the redefined entry keeps sharing the original `arg_info`/name, so the old opcodes/literals cannot be freed without exporting `destroy_op_array` and unsharing those pointers; bounded to one body per redefined function |
| Engine-chained arena blocks for >32 KiB parses | allocated by the engine; z-engine never frees memory it did not allocate |
| Refcount-0 persistent strings | never freed with the request allocator; bounded, reclaimed at process end |
| Engine-original interface/trait buffers replaced by z-engine | possibly shared or in opcache SHM, never freed by z-engine; at most one per touched class |
| Persistent interned strings (`StringEntry::persistentInterned`) | interned-style (immutable, non-refcounted) blocks referenced by persistent tables and object properties; bounded by the number of persisted keys/values, reclaimed at process end |
| Persistent hashtables and their engine-grown data blocks (`PersistentHashTable`) | registries that must outlive the request by design; the engine resizes their data with the persistent allocator, nothing may free them mid-process |
| The shared `uninitialized_bucket` sentinel block | one `uint32_t[2]` per process backing every uninitialized persistent table, mirroring the engine's static |
| Persistent object clones (`PersistentObjectFactory::persistentClone`) | refcount-pinned malloc objects designed to survive the request boundary; detached from the object store before teardown so no engine path ever frees them |

Everything else is a bug: the test suite runs with `report_memleaks=1` on a debug build and
fails on any leak report.
