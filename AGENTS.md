# Working on z-engine

z-engine reaches into the Zend Engine's own memory through PHP FFI. That makes
it uniquely powerful and uniquely fragile: a wrong struct offset or a call
against the wrong PHP version does not throw — it corrupts memory and segfaults
the interpreter. These rules exist to keep that from happening. They apply to
human contributors and automated agents alike.

## The one rule that is non-negotiable: version matching

**Never run z-engine code or tests against a PHP minor version other than the
one the current branch targets.** The engine's C structures change between
every minor version (`zend_class_entry` alone changed size in 8.1, 8.3 and
8.4). z-engine reads those structures by offset. Run it on a mismatched
version and you are reading and writing the wrong memory — the result is a
crash, or worse, silent corruption.

- `master` targets the newest supported PHP minor (currently **8.5**).
- Branch `8.4` targets **PHP 8.4**.
- Branch `8.0` is the frozen legacy line for PHP 8.0.

`Core::init()` enforces this at runtime and refuses to boot on the wrong minor.
Do not try to defeat that guard.

## Branch model

Fixes land on the **minimum affected version branch** and are merged *upward*,
never cherry-picked downward. The succession is declared in
`.github/branch-flow.json` and automated by `.github/workflows/merge-up.yml`,
which opens a merge-up PR when a version branch is pushed.

```
8.0 (frozen)      8.4  ──►  master (8.5)
```

So a bug that exists in both 8.4 and 8.5 is fixed on `8.4`, and the cascade
carries it into `master`. A bug that only exists on 8.5 is fixed on `master`
directly. When resolving a merge-up conflict inside `include/`, do **not**
merge the generated headers textually — regenerate them on the target branch
(`composer gen-headers`) instead.

## Generated engine definitions — never hand-edit

Everything under `include/<minor>/<os>-<arch>-<ts>/` is generated:

| File | What it is |
|------|-----------|
| `engine.h` | FFI header (structs, functions, globals) sliced from the PHP source |
| `constants.php` | `#define`/enum/opcode values, the ground truth for the PHP class constants |
| `layouts.json` | `sizeof`/`offsetof` of every dereferenced struct, from the C compiler |
| `probe.c` | the generated C probe (kept so a probe-only run can reuse it) |

Two more generated artifacts live at the branch level (not per-platform) and come
out of the same pipeline — the canonical `linux-x64-nts` target publishes them and
every other target byte-compares against them (see the struct-stub note below):

| File | What it is |
|------|-----------|
| `stubs/zend-engine-structs.php` | one analysis-only PHP class per engine struct (`ZEngine\Generated\*`), never loaded — see "Engine structs are typed by generated stub classes" |
| `.phpstorm.meta.php` | PhpStorm type map for the `Core::new()/cast()` legacy string literals |

This branch maintains **two thread-safety targets**: `linux-x64-nts` and
`linux-x64-zts` (the manifest in `tools/generator/symbols.php` is
thread-safety-aware — on ZTS the per-thread EG/CG are reached through the TSRM
offsets instead of the plain extern symbols, see issue #60). Regenerate them
with:

```bash
composer gen-headers          # all targets for this branch (needs Docker on Linux)
```

The generator (`tools/generator/`) runs inside the official `php:<minor>` Docker
image so the artifacts always match a real build. Regenerate whenever you:

- bump the branch to a new PHP minor,
- add or remove an engine symbol in `tools/generator/symbols.php`,
- or CI's `header-drift` job goes red.

If you touch a struct the PHP code dereferences, add it to `layout_structs` in
`symbols.php` so its layout is verified. The generator's own validation stage
FFI-loads the header and asserts every offset against the C compiler, so a
wrong header cannot be produced.

### Regenerating without Docker (native mode)

`generate.php --native` runs the pipeline directly on the host — no Docker.
It is auto-selected on non-Linux hosts (a Docker container is Linux by
construction, so it can never produce e.g. darwin artifacts) and is also the
escape hatch for sandboxed/proxied Linux environments where Docker or the
Debian package mirrors are unreachable. `emit.php` derives everything from the
*running* PHP build (`php-config --includes`, clang over the real headers, a C
probe compiled with `cc`); the php-src tree is only needed to slice the private
structs, and native mode fetches exactly those three files
(`Zend/zend_closures.c`, `ext/opcache/ZendAccelerator.h`,
`ext/opcache/zend_file_cache.c`) from
`raw.githubusercontent.com/php/php-src/php-<version>/` automatically (or use
`--php-src=DIR` to point at a matching tree).

```bash
php tools/generator/generate.php --native   # generates for the running interpreter
```

Native mode generates **for the interpreter that runs it only**: host needs
`clang`, `cc`, `php-config` matching the exact running PHP version, and
ext-ffi (on Windows there is no `php-config`/`cc` — see the Windows section
below for what replaces them). For a `zts` target the running PHP must itself
be a matching `--enable-zts` **release** build of the same minor (emit.php
derives the thread-safety mode, the TSRM symbols and the layouts from the
interpreter it runs under).

When changing `symbols.php`, first run native mode against the **committed**
manifest and confirm `git diff` on `include/<minor>/<platform>/` is clean —
the output must be byte-identical to the Docker pipeline's (verified on
Ubuntu clang-18 vs the trixie image: the emitter normalizes declarations from
the clang AST, so compiler version does not leak into the artifacts). Only
then apply the manifest change and regenerate for real. The `header-drift`
CI jobs re-run the pipeline (Docker for linux, native for darwin and windows)
and fail on any divergence, so never skip the pre-check.

### macOS (darwin) artifacts

The `darwin-{x64,arm64}-{nts,zts}` artifacts (issue #58) can only be
generated on real macOS machines. The **"Generate darwin headers"** workflow
(`.github/workflows/generate-darwin-headers.yml`) is the canonical way to
create or refresh them: it runs `generate.php --native` on both macOS runner
architectures, validates the header via FFI against the C probe, and commits
both directories back to the branch in a single commit. It triggers
automatically on pull requests that touch `tools/generator/**` (same-repo
PRs), or manually via `workflow_dispatch` against any branch.

Darwin covers **NTS and ZTS**: the workflow's matrix crosses both
architectures with both thread-safety modes (setup-php builds the ZTS PHP via
`phpts: ts`). As on Linux, the ZTS artifacts reach EG/CG through the TSRM
offsets, and the opcache file-cache relocator stays unsupported on ZTS
(issue #118). The 8.4 artifacts are maintained on the `8.4` branch;
`include/8.5/darwin-*` is maintained here - after changing the generator,
refresh it with one `workflow_dispatch` run of the workflow on `master`.
A leg whose thread-safety mode setup-php cannot provide (currently ZTS
PHP 8.5 on Intel) skips cleanly and self-heals on a later run; the CI
presence guards keep the gap visible as warnings.

### Windows artifacts

The `windows-x64-{nts,zts}` artifacts (issue #59) can only be generated on
real Windows machines; the **"Generate windows headers"** workflow
(`.github/workflows/generate-windows-headers.yml`) is the canonical way to
create or refresh them, with the same trigger and commit semantics as the
darwin workflow. x64 only — windows.php.net ships no arm64 builds.

Windows differs from the POSIX platforms in four load-bearing ways:

- **Symbol resolution needs the engine DLL.** There is no process-image
  lookup (no `RTLD_DEFAULT`), so the generated `engine.h` carries
  `#define FFI_LIB "php8.dll"` (NTS) / `"php8ts.dll"` (ZTS) for the
  `FFI::load()` path, and `Core::init()` passes the same DLL name explicitly
  to `FFI::cdef()` (which ignores the `FFI_LIB` define). The bare name binds
  to the module `php.exe` has already loaded.
- **`ZEND_FASTCALL` is `__vectorcall`.** MSVC decorates such x64 exports as
  `name@@N` and PHP's FFI mangles its lookups to match — but only for the
  `__vectorcall` *keyword*, which the emitter writes into the function
  declarations. The `__attribute__((vectorcall))` spelling is silently
  ignored by FFI and must never be emitted. Zero-argument `__vectorcall`
  declarations crash PHP's FFI (upstream bug), so the emitter refuses them.
- **Dev headers come from the devel pack.** There is no `php-config`;
  `generate.php` resolves the matching `php-devel-pack-*.zip` via
  `windows.php.net/downloads/releases/releases.json`, verifies its sha256 and
  extracts it into the temp dir (override with `--php-dev=DIR`). clang (from
  PATH) needs the MSVC/SDK `INCLUDE` environment — CI exports it via
  `ilammy/msvc-dev-cmd`; locally run from a `vcvars64` shell.
- **The PHP DLL does not export libc `free`.** The Windows manifest excludes
  it; `Core::persistentFree()` binds `ucrtbase.dll` (the same process-wide
  UCRT heap `pemalloc` draws from) instead.

Not supported on Windows: the opcache file-cache relocator (issue #119, its
tests self-skip and the CI legs carry no opcache non-skip gate) and
`opcache.preload` (does not exist on Windows). As with darwin, the 8.4
artifacts are maintained on the `8.4` branch; `include/8.5/windows-*` is
maintained here - after changing the generator, refresh it with one
`workflow_dispatch` run of the workflow on `master`.

## Running tests safely

```bash
composer test            # default suite — safe on a release PHP build
composer test:internal   # destructive/segfault-prone group, process-isolated
```

- The `internal` group mutates engine state and can crash a release build; it
  is excluded from `composer test` and should be run against a **debug PHP
  build** (`tools/docker/php-debug.Dockerfile`, which CI builds inline and runs
  the group in). Process isolation keeps one crash from taking down the whole
  run.
- FFI must be enabled (`ffi.enable=1`) and the JIT disabled (`opcache.jit=off`)
  — the JIT rewrites the executor internals z-engine hooks into. The PHPUnit
  config sets what it can; `ffi.enable` and `zend.assertions` must come from
  `php.ini` or `php -d` because they cannot be changed at runtime.
- `ZENGINE_STRICT_LAYOUT_CHECK=1` (set in the test bootstrap) makes
  `Core::init()` verify every struct layout against `layouts.json` before
  touching engine memory — the anti-segfault airbag. Keep it on in development.
- On **ZTS** builds the file-cache relocator tests (`opcache-relocator` group)
  self-skip — ZTS payloads are not supported yet (issue #118). The non-skip
  gate for the remaining opcache/SHM coverage is `composer test:opcache-zts`;
  CI runs both release and debug test legs on NTS **and** ZTS.

## Quality gates (all enforced in CI)

```bash
composer phpstan     # PHPStan at level max
composer cs:check    # php-cs-fixer (@PER-CS2.0); composer cs:fix to apply
```

FFI `CData` access is dynamically typed and cannot be statically resolved;
those violations are captured in `phpstan-baseline.neon`. New code must be
clean at level max — do not add to the baseline without good reason.

### A docker cache key must name the base image, not only the recipe

Two CI jobs build docker images from a `php:<minor>-*` tag — `tests-internal-debug`
(a full `--enable-debug` PHP compile) and `header-drift` (the generator toolchain) —
and both wrap the buildx layer cache in `actions/cache`. Those tags **move**: every
PHP patch release and every Debian security rebuild republishes them under a new
digest, and buildx correctly invalidates every layer built on top.

`actions/cache` entries are **immutable**. So a key derived only from the Dockerfile
cannot express that change: the restore reports a hit, the save is refused (`Cache
hit occurred on the primary key ..., not saving cache`), and the layers the run just
rebuilt are discarded when it ends. Every later run then repeats the rebuild —
minutes of PHP compile for the debug image — and can never repair itself until
somebody happens to edit the Dockerfile. `header-drift` sat in exactly that state,
paying ~16s to reinstall clang plus ~15s to export a cache that was never stored,
on every run.

So the key resolves the base image digest first (`docker buildx imagetools inspect
--raw`, a registry read — do not `docker pull` for this) and carries it, alongside
OS, architecture, thread safety and the PHP minor. The entry then expires exactly
when a rebuild would produce something different, and only then. Two rules follow:

- **Never reduce that key to the Dockerfile hash again**, and never "fix" a stale
  image by bumping a version suffix by hand — the digest is the version suffix.
- The `--cache-to` export is skipped on an exact hit (`Z_ENGINE_BUILDX_CACHE_READONLY`
  for the generator), because an exact hit means every restored layer is still valid
  and there is nowhere to store a new copy. Keep the restore/save split that makes
  this possible instead of collapsing it back into `actions/cache`.

## Public APIs never leak CData

Only the z-engine core layer (`Core`, the `Type\*`/`Reflection\*` wrappers) deals in
`FFI\CData` and raw zval pointers. Modules and extensions (`EngineExtension\*` and
anything built on it) must expose **pure PHP-native interfaces**: no public method of a
module may return `CData` or require callers to handle engine structures. Internal
engine knowledge — anchor slots, registry recovery, struct layouts — stays encapsulated
inside the module; consumers see plain PHP values and framework wrapper objects
(`ReflectionValue`, `PersistentHeap`, …). When a value crosses a public boundary, wrap
it or convert it. This is what keeps the FFI blast radius confined to code that is
audited for it.

The same line holds for packages built **on** z-engine. What is off-limits to them is
every method marked `@internal` and anything handing out a raw `FFI\CData`/`FFI\CType`
(`Core::type()`, the `getRaw*()` escape hatches) — plus the engine-global wrappers
`Core::$executor` / `Core::$compiler` / `Core::$modules`, which are core-layer state and
not a consumer API. When a dependant needs an operation that only exists behind that
line, the fix is a named public method here, not a reach-through there: class-table
eviction became `ClassSpecializer::evict()` and `sizeof(type(...))` became
`Core::sizeOfType()` for exactly that reason.

## Engine structs are owned by their reflection/type class, never poked from call sites

This applies to EVERY class: if a class is responsible for a structure, then all external
manipulation of that structure goes only through that class's interface API - callers
never reach into a raw `CData`. The owning class exposes typed accessors -
`ReflectionMethod::equals()`, `ReflectionClassConstant::getAccessFlags()`,
`ReflectionProperty::getOffset()/getFlags()/getSurface()/getDeclaringClass()`,
`ReflectionValue::getBaseType()/equals()/replaceWith()`, `ReflectionClass::getFlags()/
getParentClass()/getInterfaces()/getMethod()/hasMethod()/isImmutable()` - and the field
pokes (`$this->pointer->...`) live INSIDE those methods. Prefer overriding the native
reflection method (`getMethod()`, `getInterfaces()`, `getParentClass()`,
`getDeclaringClass()`) so the result is drop-in compatible with native reflection; a
consumer that needs the raw pointer calls `getAddress()` on the returned object. Consumers
(`HotSwap`, `ClassDelta`, `FunctionBodySwap`) operate on `Reflection*` objects and
pass/return those, not structs. The escape hatch is a single `getRawValue()` / `getRawData()`
returning the bare `CData` for the low-level machinery that genuinely needs it (the
body-swap surgery); prefer a typed accessor over calling it.

Two conventions back this up:

- **Engine structs are typed by generated stub classes.** Every engine struct/union has one
  analysis-only PHP class in `stubs/zend-engine-structs.php` (namespace `ZEngine\Generated`,
  whose short names ARE the raw C type names), each C field declared as a real typed public
  property - scalars as `int`/`float`/`bool`/`string`, pointers as `?otherStub`, embedded
  records as the nested stub, arrays/opaque pointers as `\FFI\CData`. The file is **generated**
  from the same clang AST as `engine.h` (`tools/generator/lib/StructStubEmitter.php`); never
  hand-edit it, and if a field is missing, regenerate with `composer gen-headers`. The classes
  are **never loadable** (`stubs/` is outside the PSR-4 roots), never instantiated and never
  `instanceof`'d - the only legal runtime use is the `::class` constant, which does not trigger
  autoloading. An owning class stores its `$pointer` as `private object` typed to the stub via
  a `@var` docblock, and narrows once at the boundary (`fromCData()` and friends accept
  `CData|Stub` and carry a `/** @var Stub */` at the assignment) - that single narrowing point
  is unchanged from the old shape convention, only the mechanism (stub class, not neon alias)
  changed. `Core::new()/cast()/type()/trackedNew()/sizeOfType()` accept a stub `::class` as
  well as the legacy C type-name string: `new(zval::class)` allocates a `zval`,
  `cast(zend_string::class, $p)` is a **pointer** cast to `zend_string*`. The raw string form of
  `cast()` stays the escape hatch for pointer arithmetic (`'char *'`) and the pointer-to-pointer /
  array / primitive forms (`'zend_ast **'`, `'char[N]'`, `'uintptr_t'`) that no stub models.
  The stubs are a **branch-level analysis artifact derived from one canonical build**
  (`linux-x64-nts`), not a per-platform ABI record (that is `layouts.json`, which stays
  per-target): a field's C spelling can genuinely differ across platforms (e.g.
  `zend_atomic_bool.value` is `_Atomic(_Bool)` on linux/darwin but `volatile char` on
  windows) without changing what the platform-agnostic PHP code may read. So only the
  canonical build publishes `stubs/` and `.phpstorm.meta.php`; the darwin/windows generators
  neither publish nor diff them. Staleness against the canonical engine is caught by the
  linux `header-drift` job, which diffs `stubs/` and `.phpstorm.meta.php` alongside `include/`.
  Fields that must never appear in a stub - platform-`#ifdef`'d ones, or ones with a
  platform-divergent spelling the PHP code never reads - are listed in `stub_platform_fields`
  in `symbols.php` and omitted everywhere.
- **Contiguous struct arrays go through `Type\StructArray`.** zval tables (op_array
  literals, class default property/static tables) and pointer lists (resolved interfaces)
  use the generic `ArrayAccess`/`Countable` `StructArray<T>` view - callers reach elements
  with `$structArray[$i]` (typed as the element stub `T`, defaulting to `CData`) and
  `replace()` for an in-place slot overwrite, never hand-rolled pointer arithmetic. A single
  element read that must be wrapped for typed access goes straight through the owning
  reflection object (eg `ReflectionValue::fromValueEntry($table[$i])`) rather than being
  poked out by index at the call site.

Runtime guards that check actual engine invariants (refcount floors, table consistency,
non-null pointer fields the engine guarantees) are not static noise and stay - the stub
migration replaces weak `assert($x instanceof CData)` scaffolding with these, it does not
delete real checks.

## Exceptions are raised through static named constructors

Domain exceptions (`HotSwapException`, `SharedMemoryException`, ...) are never thrown with
a hand-written message at the call site. Each failure mode is a `public static` factory on
the exception class (`HotSwapException::inheritedMethodOverride($class, $method)`,
`SharedMemoryException::immutableClassMutation($operation)`) that owns its message text, so
wording stays in one place and call sites read as intent. Add a new factory rather than a
new inline `throw new SomeException("...")`.

## Conventional commits

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(core): resolve engine header by platform key
fix: pass zend_string filename to the scanner on 8.4+
chore(gen): regenerate 8.4 headers after adding zend_reference
test: cover packed-array iteration
docs: rewrite the README support matrix
```

Common scopes: `core`, `gen` (generator), `reflection`, `ast`, `ci`, `docs`.

## Repository map

```
src/                 the library (Core, Reflection\*, Type\*, System\*, ClassExtension\*, EngineExtension\*, AbstractSyntaxTree\*, HotSwap\*, OpCache\*)
include/<key>/       generated FFI definitions per platform (do not edit)
tools/generator/     the header generator (symbols.php is the manifest)
tools/docker/        debug PHP image used by CI
tests/               PHPUnit 12 suite; EngineLayoutTest/EngineConstantsTest guard against ABI drift
.github/             CI, merge-up automation, branch-flow.json, issue templates
```
