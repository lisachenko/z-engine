# OpCache binary files

`ZEngine\OpCache` reads, inspects, patches and rewrites the binary files opcache
writes when `opcache.file_cache` is enabled (`<dir>/<system_id><realpath>.bin`).
It turns a cache binary into the same engine-struct wrappers the rest of
z-engine uses, lets you mutate the compiled script through them, and writes a
valid binary back — so the engine loads and executes your patched code on the
next request. This is the foundation for AOP, transpiling and source-code
protection built on top of the file cache.

```php
use ZEngine\OpCache\BinaryCacheFile;
use ZEngine\Reflection\ReflectionValue;

// Generate a cache binary for the current build (opcache compiles it in a child)
$file = BinaryCacheFile::compile(__DIR__ . '/Service.php', $cacheDir);

// Walk the compiled script through the framework wrappers
$reflection = $file->getReflection();
foreach ($reflection->getFunctions() as $name => $function) {
    foreach ($function->getLiterals() as $literal) {
        // ... inspect or mutate literals, opcodes, flags ...
    }
}

// Write the patched binary and invalidate the in-memory copy
$file->refresh();
```

## The file format

A file-cache binary is a `zend_file_cache_metainfo` header followed by the
serialized script payload and an interned-string section:

| Region | Contents |
|--------|----------|
| Header (`CacheMetaInfo`) | `magic` (`"OPCACHE"`), `system_id`, `mem_size`, `str_size`, `script_offset`, `timestamp`, `checksum` |
| Payload (`mem_size` bytes) | the `zend_persistent_script` and everything it owns, with every interior pointer stored as a byte **offset** from the payload start |
| String section (`str_size` bytes) | interned strings, referenced from the payload by a tagged offset |

The payload is position-independent by design, which is what makes it portable
between processes — and what the API has to undo before the structures can be
walked.

## Build matching

A binary is loadable only by the exact engine build that produced it. Three
things are checked on load, and the API mirrors each:

- **System id** — `SystemId::current()` is the running build's 32-hex-char
  `zend_system_id`. `BinaryCacheFile::read()` parses the header of a binary
  from *any* build, but `getReflection()` throws `OpCacheException::systemIdMismatch`
  unless the build matches, because a foreign payload cannot be interpreted.
- **Checksum** — an adler32 over the payload and string section
  (`opcache.file_cache_consistency_checks=1`). `save()` always recomputes it,
  so a patched binary stays loadable. `verifyChecksum()` performs the same
  check the loader does.
- **Timestamp** — compared for **equality** with the source mtime when
  `opcache.validate_timestamps=1`. `refresh()` stamps the source's current
  mtime; `save($path, $timestamp)` lets you set it explicitly.

## Reading, patching and writing

`BinaryCacheFile::getReflection()` materializes the payload into a live
in-memory image (`PayloadRelocator`, a faithful port of opcache's own
`zend_file_cache_unserialize`) and returns a `ReflectionOpcacheFile` — a handle
shaped like the native `ReflectionExtension`. It hands out ordinary z-engine
wrappers — `ReflectionFunction`, `ReflectionClass`, `HashTable`,
`ReflectionValue` — so every existing mutation API works on the loaded script:

```php
$reflection->getFileName();        // the cached source path
$reflection->getScriptFunction();  // file-level op_array as a ReflectionFunction
$reflection->getFunctions();       // array<string, ReflectionFunction>, name-keyed
$reflection->getClasses();         // array<string, ReflectionClass>, name-keyed
```

`save()` re-serializes the (possibly mutated) image back to a valid binary and
rebuilds the interned-string section, so **size-changing edits** — a longer
string literal, a new constant value — are written correctly, not just in-place
byte pokes. `refresh()` is `save()` plus `opcache_invalidate()` on the source
script, so the next include picks up the patched binary.

## Growing the graph: added functions and methods

In-place edits go out through `PayloadRelocator::derelocate()` — the exact
inverse of the read-time relocation. Mutations that outgrow the original
buffer take a different writer
([#117](https://github.com/lisachenko/z-engine/issues/117)):
`ScriptSerializer`, a two-pass port of `zend_persist_calc` → `zend_persist`
(pass 1 walks the graph, deduplicating every reachable allocation unit
through an xlat table and summing aligned sizes; pass 2 emits a fresh
contiguous region and rewrites every pointer), which then delegates the
on-disk offset encoding to the same `PayloadRelocator` serialize stage — one
implementation for the offset format. `save()` picks the writer
automatically: it re-emits from scratch once the reflection view reports the
graph as grown, and keeps the byte-exact derelocate path otherwise.

New code enters the image as **grafts from donor binaries**:

```php
$file  = BinaryCacheFile::read($binPath, $scriptPath);
$donor = BinaryCacheFile::compile($donorScript, $donorCacheDir);

$view = $file->getReflection();
$view->addFunctionFrom($donor->getReflection(), 'my_new_function');
$view->addMethodFrom($donor->getReflection(), 'DonorClass', 'newMethod', 'CachedClass');
$file->save();   // a fresh worker now executes the added function and method
```

Donors are compiled by a real opcache child, so their op_arrays are already
in file form (opline handlers are handler-table indexes, IS_CONST operands
are literal-table indexes — neither is derivable in-process without engine
helpers that are not exported); the serializer copies those units verbatim.
Grafting regrows the target hashtable outside the buffer — persisted tables
must never be touched by `zend_hash_add`, their data block is not an
emalloc'd allocation — and the donor image stays referenced (and, for
methods, mutated: the op_array's scope is re-pointed at the adopting class)
until `save()` re-emits everything into one fresh region.

## Refresh and shared memory

Under `opcache.file_cache_only=1` there is no shared-memory copy, so writing the
binary is enough for the next worker to load it (`opcache_invalidate()` is a
no-op in that mode). When opcache also uses shared memory, a script already
resident in SHM is **not** re-read until it is invalidated — which is exactly
what `refresh()` does.

Two shared-memory subtleties `refresh()` accounts for:

- **Invalidate before write.** In a process running SHM *with*
  `opcache.file_cache`, `opcache_invalidate()` also unlinks the script's cache
  binary (`zend_file_cache_invalidate`). `refresh()` therefore invalidates
  first and writes second, so the unlink hits the stale binary — the worst
  case if the write then fails is a cache miss and a recompile of the original
  source, never a silently lost patch.
- **Same-process pickup needs `opcache.revalidate_path=1`.** After an
  in-process invalidation, opcache's default key lookup finds the invalidated
  hash entry without resolving the script path and never consults the file
  cache again, so a re-include in the *same* process recompiles the source.
  With `opcache.revalidate_path=1` the path is resolved, the patched binary is
  loaded from the file cache back into shared memory, and the re-include
  executes the patched body. A **fresh** worker (an empty SHM — e.g. a pool
  worker after restart) picks the patched binary up with default settings.

Publishing a patched binary directly into shared memory (bypassing the file
cache) is **not planned** — the write-path opcache symbols are hidden from FFI
and the segment is protected against out-of-band writes
([#121](https://github.com/lisachenko/z-engine/issues/121), closed with the
feasibility analysis). `refresh()`'s file-cache→SHM reload is the supported SHM
publication mechanism. Applying a patched image to code **already loaded in the
current process** is a different loop, closed by `CacheImageSync` — see the next
section and [hot-swap.md](hot-swap.md).

## Applying a patched image to the live process (`CacheImageSync`)

`refresh()` only affects the *next* include. `ZEngine\HotSwap\CacheImageSync`
closes the other half of the loop (issue #122): it diffs a (patched) image
against the functions and classes **already loaded** in this process and swaps
the changed compiled bodies in place, through the same runtime machinery
`redefine()`/`ClassDelta` use — no re-include, warmed-up call sites keep
dispatching the same entry pointers.

```php
$image = $file->getReflection();
// ... patch literals/opcodes through the wrappers ...
$sync = CacheImageSync::prepare($image);   // read-only diff
$sync->getChangedFunctions();              // introspect the plan
$report = $sync->apply();                  // swap the changed bodies, loudly
$report->appliedMethods;                   // what actually happened, per entry
```

- **Diff basis.** `prepare()` compares each image body with its live
  counterpart: body metrics (opcode/literal/CV/temporary/argument counts),
  fn_flags without the storage-only bits, CV names, every opline in
  canonicalized form (IS_CONST operands by literal index — the image stores
  the serialized index form, the live side the runtime offset form — with
  handlers and the garbage `op1.num` of implicit-`$this` receivers ignored),
  every literal and static-variable default by value. The comparison is
  conservative where value equality cannot be proven: array and
  constant-expression literals always count as changed (a safe re-apply, like
  `ReflectionMethod::equals()`); declaration-surface-only edits (arg_info
  types/names, doc comments) are not part of the basis and do not trigger a
  swap on their own.
- **Execution normalization.** Donor bodies are materialized per entry
  (`ImageFunctionDonor`): opcodes + literals are copied into one co-allocated
  process block, IS_CONST operands are rewritten to the runtime form and the
  handlers restored with the engine's own `zend_deserialize_opcode_handler()`.
  The image buffer itself is never written, so `save()`/`refresh()` keep
  producing valid binaries after an apply.
- **Ordering and atomicity.** `apply()` validates refusals first (nothing is
  touched if the plan contains one), then copies every opcache-shared target
  out of SHM, then stages all swaps — functions before classes, alphabetically
  within each group — and commits only when every swap staged; a failure rolls
  all staged bodies back (completed copy-outs stay, they are
  behavior-preserving).
- **Scope.** Bodies of named global functions and of methods the live class
  itself declares. Image-only entries (script never included here, methods or
  functions only the patch added) are *reported* as not loaded — the next
  include picks them up. The script's main op_array, class constants, property
  defaults and attributes are out of scope.
- **Refusals (throw-or-work, never silent).** Changed methods of an
  enum/interface/trait throw `HotSwapException::unsupportedKind`; an image
  entry colliding with an internal function/class throws; opcache-shared
  targets follow the [hot-swap.md](hot-swap.md) copy-out matrix, so preloaded
  classes and copy-unsupported shapes (property hooks, internal ancestors)
  throw `SharedMemoryException`. Unchanged entries of a refused kind are not
  operations and pass.
- **Lifetime.** Swapped-in bodies execute out of the materialized blocks and
  the relocated image buffer: the sync retains both (and the view retains the
  buffer), all are request-lifetime allocations the engine provably never
  frees through table teardown (the bodies carry no refcount, exactly like
  shared-memory bodies). Apply per request, like every other runtime mutation.
- **Apply-target seam.** `prepare()` is application-agnostic: the prepared
  diff (`getChangedFunctions()`/`getChangedMethods()` plus the image handle) is
  independent of where the swapped bodies land. Today `apply()` writes the
  per-process tables; a different consumer could reuse the same diff against
  another target. Direct SHM publication is not one of those targets
  ([#121](https://github.com/lisachenko/z-engine/issues/121) — infeasible vs
  stock opcache); the file-cache→SHM reload of `refresh()` covers that need.

## Scope and limits (v1)

- **Platform.** The relocator targets 64-bit POSIX builds - linux and macOS
  (x64 and arm64) alike; it asserts `PHP_INT_SIZE === 8` and a `/` path
  separator and throws `OpCacheException::unsupportedPayload` otherwise.
  Darwin needs no per-opline walking of its own
  ([#119](https://github.com/lisachenko/z-engine/issues/119)): the
  absolute-address opline branches of zend_file_cache.c
  (`ZEND_USE_ABS_CONST_ADDR`/`ZEND_USE_ABS_JMP_ADDR`) are compiled in only
  when `SIZEOF_SIZE_T == 4` (zend_compile.h), so every 64-bit build - darwin
  included - stores IS_CONST operands as literal-table indexes and jumps as
  opline-relative byte offsets, both position-independent and preserved
  verbatim. `OpcodeAddressingModelTest` proves that on a real payload and
  fails loudly if a build ever diverges; the 32-bit builds that do use
  absolute addressing are refused by the `PHP_INT_SIZE` predicate.
  **Windows opcache support
  is an intentional non-goal**, not pending work: the relocator (and
  `opcache.preload`-based features) keep rejecting Windows loudly, and the
  Windows half of the original platform ticket was retired when
  [#119](https://github.com/lisachenko/z-engine/issues/119) was rescoped to
  macOS/arm64. ZTS payloads are supported since
  [#118](https://github.com/lisachenko/z-engine/issues/118): the file-cache
  binary layout is thread-safety-agnostic (zend_file_cache.c has no ZTS
  conditionals, and every struct the walker dereferences is layout-identical
  across the modes — only EG/CG/module_entry differ, none of which appear in
  a payload).
- **Strict, never silent.** Anything the port cannot handle raises
  `unsupportedPayload` rather than writing a subtly corrupt binary; with every
  payload shape of the 8.4 walker now ported, that guard covers the platform
  predicates above (Windows/32-bit). Global functions,
  classes with constants, typed properties (union/intersection/DNF type lists
  included), trait-using classes (aliases and insteadof precedences included),
  closures and arrow functions (nested dynamic_func_defs included),
  Iterator/IteratorAggregate/ArrayAccess classes (including the linked-class
  iterator_funcs_ptr / arrayaccess_funcs_ptr structs), property hooks,
  attributes (including constant-expression arguments), static variables,
  compile warnings, try/catch and enums are supported and round-trip
  byte-for-byte.
- **Graph growth.** Added functions and methods are supported through donor
  grafts and the from-scratch `ScriptSerializer` (see "Growing the graph"
  above, issue #117); whole added classes and freshly in-process compiled
  op_arrays (no file-form oplines) remain out of scope and are refused loudly.
- **Deferred.** Loading patched binaries into shared memory (ZCSG,
  [#121](https://github.com/lisachenko/z-engine/issues/121)). Applying a
  patched image to already-loaded functions and classes landed as
  `CacheImageSync` (see above).

## Failure modes

Everything the API rejects is a static factory on `OpCacheException`
(`invalidMagic`, `truncatedFile`, `systemIdMismatch`, `checksumMismatch`,
`binFileNotFound`, `compilationFailed`, `unsupportedPayload`, …), so call sites
read as intent and the wording lives in one place.
