# Memory model: PHP, FFI trampolines and native C

z-engine sits on a seam between three different kinds of code, each living in its own part
of the process. Understanding where a value lives — and which layer executes it — is what
separates a fast, safe extension from a segfault. This document maps that terrain, and
explains why a **generated function** (`ReflectionFunction::addFunction()` /
`ReflectionClass::addMethod()`) is dramatically faster than a **hooked** one
(`AbstractHook`, the internal branch of `redefine()`).

## The three layers

| Layer | What lives there | How z-engine touches it |
|-------|------------------|-------------------------|
| **PHP / Zend heap** | `zval`s, objects, `zend_string`s, `HashTable`s, op_arrays — everything the Zend VM allocates and refcounts | Wrapped by `ReflectionValue`, `StringEntry`, `HashTable`, `ObjectEntry`, … |
| **FFI views** | `CData` handles that *point at* engine structs — they are borrowed pointers, **not copies** | `Core::cast()`, `Core::new()`, `Core::addr()`; the whole `src/` wrapper layer |
| **Native C** | Compiled machine code: the Zend VM itself, and every `zif_handler` in the PHP binary and its extensions | `Core::call()` to invoke imported engine functions; `zif_handler` pointers read from `zend_internal_function.handler` |

An FFI `CData` is a *window* onto engine memory. Writing through it writes the engine's own
bytes — there is no marshalling and no copy. That is why a wrong struct offset corrupts the
interpreter instead of throwing (see `AGENTS.md`).

## Path A — the FFI trampoline (what we escape)

When userland wants a *PHP closure* to run inside an engine function pointer — an object
handler (`do_operation`, `get_method`, …) or an internal function's `handler` — z-engine
assigns the closure straight to the C field:

```php
// AbstractHook::install(), src/Hook/AbstractHook.php:82
$this->rawStructure->{static::HOOK_FIELD} = Closure::fromCallable([$this, 'handle']);

// FunctionLikeTrait::redefine() internal branch, src/Reflection/FunctionLikeTrait.php:170
$this->pointer->handler = function (CData $executeData, CData $returnValue) use ($newCode) { ... };
```

ext/ffi reacts by minting a **libffi trampoline**: a small block of executable memory that
adapts the C calling convention to a PHP call. Every invocation then looks like this:

```
engine C code
    │  calls the function pointer
    ▼
libffi trampoline            ← executable thunk minted by ext/ffi
    │  marshals args C → PHP
    ▼
PHP Closure (::handle)       ← your code, back on the Zend VM
    │  result marshalled PHP → C
    ▼
engine C code resumes
```

Two boundary crossings **per call**, plus argument marshalling each way. `AbstractHook`'s
own docblock calls it *"not efficient"*. It is also lifetime-sensitive: the trampoline is
kept alive only while the `Core` hook registry holds the hook, and `Core::shutdown()`
restores every hooked pointer *before* ext/ffi frees the trampolines — otherwise an engine
struct that outlives the request would point at freed executable memory.

Path A is the right tool when you genuinely need PHP logic in the middle of an engine
operation (operator overloading, custom object handlers). But for a plain function or
method body, it pays the trampoline tax on every single call.

## Path B — op_array grafting (what generated functions use)

A PHP closure is already compiled to a `zend_op_array` — real Zend bytecode the VM knows
how to run. Instead of pointing an engine field at the closure, we **publish the closure's
embedded `zend_function` directly into an engine function table**:

```php
ReflectionFunction::addFunction('twice', fn (int $x) => $x * 2);  // → EG(function_table)
$class->addMethod('scale', fn (float $k) => ...);                 // → class function_table
```

After publishing, a call is just an ordinary VM dispatch — **the FFI layer is not involved
at call time at all**:

```
engine VM
    │  ZEND_DO_FCALL → op_array
    ▼
your bytecode runs on zvals        ← no trampoline, no marshalling, no boundary
    ▼
engine VM resumes
```

This is exactly as fast as any hand-written PHP function, because it *is* one. The cost
that Path A paid per call is paid **once**, at registration.

### The immortality requirement

The published `zend_function` is **embedded inside the closure object** (`zend_closure.func`,
see `src/Type/ClosureEntry.php`). The table bucket stores a pointer *into* that object, so
the closure must outlive the table entry. Both `addFunction()` and `addMethod()` therefore
bump the closure object's refcount so it is never collected:

```php
$closureEntry->getClosureObjectEntry()->incrementReferenceCount(); // immortal-by-design
```

This is a deliberate, bounded allocation that lives until request end — the same
"immortal-by-design" category documented in [`long-running.md`](long-running.md). Debug
builds' `report_memleaks` will flag it; that is expected, not a bug.

### The name string

The function's display name is a `zend_string` set through
`FunctionLikeTrait::setFunctionName()`, which releases any previous name and takes one owned
reference on the new one — standard engine assignment semantics, so no double-free and no
leak of the name itself. The table *key* is the lowercased name (functions are
case-insensitive); the `function_name` field keeps the original case for display.

### Shutdown contract for global functions

A method added to a user class rides that class's normal teardown. A **global** function
lives in `EG(function_table)`, whose destructor (`zend_function_dtor`) would try to destroy
our entry at request end — but our entry points into an immortalized closure, not a
standalone arena allocation. So `Core::shutdown()` **unpublishes** every generated global
function first, deleting the bucket with the table destructor temporarily disabled
(`pDestructor = null`), exactly the technique `ReflectionMethod::fromHookCData()` uses. The
engine then never walks a dangling entry or double-frees the payload. See
`Core::registerGeneratedFunction()` and `Core::shutdown()`.

## Path C — native C (`zif_handler`), for contrast

A genuine internal function (`strlen`, `count`, an extension function) stores a
`zif_handler` — `void (*)(zend_execute_data*, zval* return_value)` — in
`zend_internal_function.handler`. Calling it dispatches straight into compiled machine code:
no VM opcode loop, no FFI, true C speed.

z-engine **cannot synthesise new such handlers from pure PHP** — that would require emitting
machine code (a JIT), which is out of scope. So the fastest path available to *generated
logic* is Path B: real bytecode on the native VM, with the trampoline removed. The practical
takeaway:

- **Custom logic** → Path B (`addFunction`/`addMethod`): VM speed, no trampoline.
- **PHP logic inside an engine operation** → Path A (hooks): correct, but pays the
  trampoline tax per call.
- **C speed for new logic** → not reachable in pure PHP (no JIT).

## Ownership & lifetime cheat-sheet

| Concern | Rule | Where |
|---------|------|-------|
| `CData` views | Borrowed pointers into engine memory; never free engine memory through the FFI allocator | `Core` docblock, `long-running.md` |
| z-engine's own buffers | Allocate with `Core::new()`/`trackedNew()`; free with `untrackAndFree()` | `src/Core.php` |
| Allocator class | Persistent (malloc) only for structures the engine frees persistently (internal classes); request memory everywhere else | `ReflectionClass::isPersistentAllocation()` |
| Generated function body | Immortalize the closure object (refcount bump) — the op_array lives inside it | `addFunction`/`addMethod` |
| Generated function name | Owned `zend_string`, assigned with release-old/own-new semantics | `FunctionLikeTrait::setFunctionName()` |
| Generated global function | Unpublished at `Core::shutdown()` with the table destructor disabled | `Core::shutdown()` |
| Trampolines (Path A) | Kept alive by the `Core` hook registry; restored at shutdown before ext/ffi frees them | `AbstractHook`, `Core::shutdown()` |
| Object graphs that must outlive the request | Store them in the persistent heap: deep malloc clone at `put()`, verified re-attachment at `get()`, exact eviction at `remove()` | `PersistentHeap`, [`persistent-heap.md`](persistent-heap.md) |

## Speed model

The cost of Path A is **per call** (boundary crossing + marshalling, ×2). The cost of Path B
is **per registration** (one closure compile + one table publish); calls afterward are free
of FFI entirely. So the speed-up of a generated function over a trampoline-backed one grows
linearly with call count — which is precisely why hot paths belong on Path B.

To measure it, compare a function generated with `addFunction()` against the same body
installed through the internal branch of `redefine()` (Path A) over a tight call loop; the
benchmark lives in the `performance` PHPUnit group (excluded from the default suite).
