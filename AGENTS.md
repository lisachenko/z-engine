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

Regenerate them with:

```bash
composer gen-headers          # all targets for this branch (needs Docker)
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

### Regenerating without Docker (sandboxed/proxied environments)

When Docker or the Debian package mirrors are unreachable (as in restricted CI
sandboxes), the generator can run directly on the host — the Docker image is a
convenience, not a requirement. `emit.php` derives everything from the
*running* PHP build (`php-config --includes`, clang over the real headers, a C
probe compiled with `cc`); the php-src tree is only needed to slice
`Zend/zend_closures.c`. Verified recipe:

1. Host needs: `clang`, `cc`, `php-config` + dev headers for the exact running
   PHP version, ext-ffi. Fetch the matching `Zend/zend_closures.c` (and nothing
   else) from `raw.githubusercontent.com/php/php-src/php-<version>/`.
2. First run against the **committed** `symbols.php` into a scratch directory
   and `diff` against `include/<minor>/<platform>/` — the output must be
   byte-identical (it was, verified on Ubuntu clang-18 vs the trixie image:
   the emitter normalizes declarations from the clang AST, so compiler
   version does not leak into the artifacts). If the diff is clean, host
   regeneration is equivalent to the Docker/CI pipeline for this host.
3. Only then apply the `symbols.php` change and regenerate into `include/`
   for real: `php -d memory_limit=2G tools/generator/emit.php
   --php-src=<dir> [--out=...]`.

The byte-identical pre-check is what makes this safe: the `header-drift` CI
job re-runs the Docker pipeline and fails on any divergence, so never skip
step 2.

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

## Quality gates (all enforced in CI)

```bash
composer phpstan     # PHPStan at level max
composer cs:check    # php-cs-fixer (@PER-CS2.0); composer cs:fix to apply
```

FFI `CData` access is dynamically typed and cannot be statically resolved;
those violations are captured in `phpstan-baseline.neon`. New code must be
clean at level max — do not add to the baseline without good reason.

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

## Engine structs are owned by their reflection/type class, never poked from call sites

Every raw engine struct is reached through the ONE reflection/type class that owns it,
never from a caller reaching into a `CData`. The owning class exposes typed accessors -
`ReflectionMethod::equals()`, `ReflectionClassConstant::getAccessFlags()`,
`ReflectionProperty::getOffset()/getFlags()/getSurface()`,
`ReflectionValue::getBaseType()/equals()/replaceWith()`, `ReflectionClass::getFlags()/
getParentAddress()/getInterfaceAddresses()/isImmutable()` - and the field pokes
(`$this->pointer->...`) plus their single-hop narrowing `assert()`s live INSIDE those
methods. Consumers (`HotSwap`, `ClassDelta`, `FunctionBodySwap`) operate on
`Reflection*` objects and pass/return those, not structs. The escape hatch is a single
`getRawValue()` / `getRawData()` returning the bare `CData` for the low-level machinery
that genuinely needs it (the body-swap surgery); prefer a typed accessor over calling it.

Two conventions back this up:

- **Named shapes for the two big function structs.** `zend_function.common` and its
  `op_array` are described once as PHPStan object shapes (`ZendFunctionCommonShape`,
  `ZendOpArrayShape`) via `parameters.typeAliases` in `phpstan.dist.neon`, surfaced by
  `FunctionLikeTrait::getCommonPointer()/getOpArrayPointer()` (a nested-field read the
  analyser infers statically). `FFI\CData` is `final`, so a shape can NOT be attached to
  a plain `: CData` return and there is no runtime `asStructView()` wrapper - the shape is
  a return annotation only. If a field is missing from a shape, extend the alias in the
  config; never spell a shape out inline.
- **Contiguous struct arrays go through `Type\StructArray`.** zval tables (op_array
  literals, class default property/static tables), pointer lists (resolved interfaces)
  and single-struct dereferences use the `ArrayAccess`/`Countable` `StructArray` view
  (`replace()` for in-place slot overwrite) instead of hand-rolled pointer arithmetic.
  `newEntry(IS_PTR, ...)` stores the ADDRESS of the CData it is handed, so it needs the
  dereferenced struct (`StructArray(..., 1)->rawAt(0)`), never the 8-byte pointer.

Runtime guards that check actual engine invariants (refcount floors, table consistency)
are not static noise and stay.

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
src/                 the library (Core, Reflection\*, Type\*, System\*, ClassExtension\*, EngineExtension\*, AbstractSyntaxTree\*)
include/<key>/       generated FFI definitions per platform (do not edit)
tools/generator/     the header generator (symbols.php is the manifest)
tools/docker/        debug PHP image used by CI
tests/               PHPUnit 12 suite; EngineLayoutTest/EngineConstantsTest guard against ABI drift
.github/             CI, merge-up automation, branch-flow.json, issue templates
```
