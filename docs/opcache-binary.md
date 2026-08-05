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
binary is enough for the next worker to load it. When opcache also uses shared
memory, a script already resident in SHM is **not** re-read until it is
invalidated — which is exactly what `refresh()` does. Loading a patched binary
directly into shared memory (and wiring it to the function/method hot-swap API)
is future work; see [hot-swap.md](hot-swap.md).

## Scope and limits (v1)

- **Platform.** The relocator targets the bundled 64-bit non-Windows build; it
  asserts `PHP_INT_SIZE === 8` and a `/` path separator and throws
  `OpCacheException::unsupportedPayload` otherwise.
- **Strict, never silent.** Structures the port does not yet handle
  (intersection/union type lists, property hooks, iterator/ArrayAccess funcs,
  trait-using classes, compile warnings) raise `unsupportedPayload` rather than
  writing a subtly corrupt binary. Global functions, classes with constants,
  typed properties, attributes (including constant-expression arguments), static
  variables, try/catch and enums are supported and round-trip byte-for-byte.
- **Deferred.** Loading patched binaries into shared memory (ZCSG), and applying
  a patched image to already-loaded classes via `redefine()` / `ClassDelta`.

## Failure modes

Everything the API rejects is a static factory on `OpCacheException`
(`invalidMagic`, `truncatedFile`, `systemIdMismatch`, `checksumMismatch`,
`binFileNotFound`, `compilationFailed`, `unsupportedPayload`, …), so call sites
read as intent and the wording lives in one place.
