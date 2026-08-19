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

Loading a patched binary directly into shared memory (and wiring it to the
function/method hot-swap API) is future work; see [hot-swap.md](hot-swap.md).

## Scope and limits (v1)

- **Platform.** The relocator targets the bundled 64-bit non-Windows build; it
  asserts `PHP_INT_SIZE === 8` and a `/` path separator and throws
  `OpCacheException::unsupportedPayload` otherwise. **Windows opcache support
  is an intentional non-goal**, not pending work: the relocator (and
  `opcache.preload`-based features) keep rejecting Windows loudly, and the
  Windows half of the original platform ticket was retired when
  [#119](https://github.com/lisachenko/z-engine/issues/119) was rescoped to
  macOS/arm64. ZTS payloads stay tracked in
  [#118](https://github.com/lisachenko/z-engine/issues/118).
- **Strict, never silent.** Anything the port cannot handle raises
  `unsupportedPayload` rather than writing a subtly corrupt binary; with every
  payload shape of the 8.4 walker now ported, that guard covers the platform
  predicates above (Windows/32-bit, and ZTS until #118). Global functions,
  classes with constants, typed properties (union/intersection/DNF type lists
  included), trait-using classes (aliases and insteadof precedences included),
  closures and arrow functions (nested dynamic_func_defs included),
  Iterator/IteratorAggregate/ArrayAccess classes (including the linked-class
  iterator_funcs_ptr / arrayaccess_funcs_ptr structs), property hooks,
  attributes (including constant-expression arguments), static variables,
  compile warnings, try/catch and enums are supported and round-trip
  byte-for-byte.
- **Deferred.** Loading patched binaries into shared memory (ZCSG), and applying
  a patched image to already-loaded classes via `redefine()` / `ClassDelta`.

## Failure modes

Everything the API rejects is a static factory on `OpCacheException`
(`invalidMagic`, `truncatedFile`, `systemIdMismatch`, `checksumMismatch`,
`binFileNotFound`, `compilationFailed`, `unsupportedPayload`, …), so call sites
read as intent and the wording lives in one place.
