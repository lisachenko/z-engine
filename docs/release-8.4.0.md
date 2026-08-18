# ⚡ Z-Engine 8.4.0 — *"Write PHP extensions in pure PHP"*

> **Five years. 66 commits. 306 files. ~43,000 lines.**
> The library that pointed PHP's FFI back at PHP itself is awake again — and this time it brought a memory model, a JIT-free fast path, a persistent heap, hot-swap, and the ability to rewrite opcache's own binary files. 🔥

Z-Engine `0.9.1` shipped in **June 2021** against PHP 8.0. Today, **`8.4.0`** lands against **PHP 8.4** — and it is not a maintenance bump. It's a different library wearing the same name. 🎉

```bash
composer require lisachenko/z-engine:^8.4
```

> 🔢 **New versioning:** the major version now tracks the **PHP minor** it targets. Engine struct layouts change every PHP release, so `8.4.x` means "byte-exact for PHP 8.4", full stop. PHP 8.5 lives on `master`.

---

## 🌟 What's new

### 🧬 Reified generics — for real, in the engine

`ClassSpecializer` deep-clones a linked userland `zend_class_entry` under a new runtime name, runs a controlled **type-substitution pass** over the copy, and registers the result in `CG(class_table)` as a first-class, instantiable class. Not a decorator. Not a docblock. A class the compiler itself could have produced — with the substituted types **enforced by the engine**.

```php
$specialized = (new ReflectionClass(Box::class))->specialize('Box_Int', new TypeSubstitutionMap([
    'T' => 'int',
]));
```

Property types, parameter types and the cached `ZEND_RECV` type masks are all patched, so a wrong argument throws a real `TypeError` from the VM — at VM speed. 📐

### 🚀 Generated functions — zero FFI at call time

A closure installed into an engine handler is called back through a **libffi trampoline** — correct, but slow. `ReflectionFunction::addFunction()` and `ReflectionClass::addMethod()` now publish a genuine function straight into the engine's function table. Afterwards it dispatches through the ordinary Zend VM with **no FFI on the hot path at all**:

```php
ReflectionFunction::addFunction('twice', fn (int $x): int => $x * 2);
twice(21); // 42 — a real global function, at native PHP speed
```

The full map of where each layer lives — Zend heap, FFI views, native C — is in [`docs/memory-model.md`](memory-model.md). 🗺️

### 🧠 The persistent heap — object graphs that outlive the request

`ZEngine\Memory\PersistentHeap` is a **named, in-process registry** that deep-clones whole object graphs into malloc memory, where they survive request shutdown inside an NTS worker and can be re-attached by a later request. Routing trees, container maps, compiled configs — built once, reused forever. ♻️

```php
ExtensionManager::register(new ZEngineModule());
$heap = PersistentHeap::global();
$heap->put('routing-tree', $rootObject);   // persistent clone of the reachable graph
```

Backed by `persistentClone`, `PersistentHashTable` and `StringEntry::persistent()`, and — crucially — **dismantled correctly**, not leaked. See [`docs/persistent-heap.md`](persistent-heap.md).

### 🔁 Hot-swap — replace a method body in place

`redefine()` now swaps a function or method body **without moving the `zend_function` pointer**. Warmed-up inline caches, subclass method buckets and prototype links all stay valid and immediately dispatch the new body. On top of it, `HotSwap::prepare()` / `ClassDelta::apply()` computes and applies a **whole-class delta** from freshly written source. 🪄

Live code reloading in a worker loop, without restarting the worker. Details and the exact memory contract: [`docs/hot-swap.md`](hot-swap.md).

### 💾 OpCache binary files — read, patch, rewrite

Brand-new `ZEngine\OpCache` namespace. Read the `.bin` files opcache writes for `opcache.file_cache`, walk the compiled script through the *same* framework wrappers you already use, mutate literals/opcodes/flags, then write a **valid binary back** — and the engine loads your patched code on the next request:

```php
$file = BinaryCacheFile::compile(__DIR__ . '/Service.php', $cacheDir);
$reflection = $file->getReflection();
// ... mutate through the usual wrappers ...
$file->refresh();   // re-serialize the binary + invalidate the source
```

The payload is **re-serialized from the mutated graph**, not byte-poked, so size-changing edits are written correctly. This is the foundation for AOP, transpiling and source-code protection built on the file cache. 🏗️ ([`docs/opcache-binary.md`](opcache-binary.md))

Plus: class mutations now work on **opcache-shared (immutable/SHM) classes** — the specialization is copied out of shared memory first, so `setFinal()`, `addMethod()` and friends behave the same whether or not opcache interned your class. 🧊

### 🛡️ A real memory-ownership model (and long-running PHP)

The single biggest reason 0.9.x could not be trusted in a worker loop is fixed. Every value wrapper now carries two explicit ownership bits, and **the engine's refcount is the single source of truth**:

- **Owning constructors** (`new StringEntry()`, `new ObjectEntry()`, `new ResourceEntry()`) take their own reference and keep the target alive for the wrapper's lifetime.
- **`fromCData()` factories** stay borrowed — no surprise frees.
- Releases go through the engine's own primitives (`zval_ptr_dtor` / `rc_dtor_func`), **never** the FFI allocator.
- Engine hooks have a full `install()` / `uninstall()` / `reinstall()` lifecycle backed by a registry, and an auto-registered `Core::shutdown()` restores every hooked pointer *before* the engine could call a freed trampoline.

Two wrappers aliasing the same pointer can no longer double-free. `Compiler::parseString()` trees free themselves. `ClosureEntry::setThis()` no longer requires the object to outlive the closure. **Worker loops and FPM + opcache preload are now viable.** 💪 ([`docs/long-running.md`](long-running.md))

### 🪝 More engine surface

- 🎯 **`get_method` object handler** exposed as a hook — intercept method resolution itself
- 🧵 **Traits op_array introspection** and **lazy-object iterator** support
- ➕ Several new **PHP 8 opcodes**, `TryCatchElement`, and a fully generic `StructArray<T>`
- 🧯 A clear, actionable error when the **FFI extension is missing or disabled** — no more cryptic boot failure

### 🏅 Engineering quality

**PHPStan at level `max`**, PHP-CS-Fixer, PHPUnit 12, 56 test suites, a dedicated segfault-prone `internal` group run under a **debug PHP build with process isolation**, and an opcache group that *fails* rather than silently skips. Engine definitions are still generated from the PHP source by `tools/generator/` and never hand-edited — that byte-exactness is what turns "insanely dangerous" into "dangerous but disciplined." 🎓

---

## 🧪 See it in action — the ecosystem is real now

Z-Engine is no longer a lone proof-of-concept. There are **real PHP extensions written in pure PHP** running on it today:

| Project | What it does |
|---|---|
| 🐛 **[lisachenko/zdebug](https://github.com/lisachenko/zdebug)** | An **Xdebug-compatible step debugger with no C extension**. Your IDE attaches over DBGp; pure PHP drives the Zend VM through FFI. Breakpoints, stepping, stack and variable inspection in PhpStorm or VS Code — nothing compiled, nothing installed but Composer packages. |
| 🧮 **[lisachenko/native-php-matrix](https://github.com/lisachenko/native-php-matrix)** | A `Matrix` type with **fully overloaded arithmetic operators** — `+`, `-`, `*`, `/`, `**`, `==` — via the engine's own `do_operation` and `compare` handlers. |
| 🔒 **[lisachenko/immutable-object](https://github.com/lisachenko/immutable-object)** | Mark a class immutable with a single interface; property writes outside the constructor throw. Enforced by `write_property`, not by convention. |
| 📦 **[lisachenko/userland-php-generics](https://github.com/lisachenko/userland-php-generics)** | **Reified generics for PHP.** `Box::of('int')` monomorphizes a template into a real class entry whose `int` the engine enforces — and measures exactly what that costs. |

A step debugger. Operator overloading. Immutability. Generics. **All in userland PHP.** That's the point. ✨

---

## ⚠️ Still experimental

Z-Engine operates on raw engine memory. Segfaults are a feature of the territory, not a bug in your code. **Do not ship it in production until 1.0.0.** Pin your PHP version, match the branch to your interpreter, and develop against a debug build.

| PHP | OS / Arch / TS | Branch | Status |
|-----|----------------|--------|--------|
| 8.5 | linux-x64-nts | `master` | 🚧 in progress |
| 8.4 | linux-x64-nts | `8.4` | ✅ **supported — this release** |
| 8.0 | linux-x64-nts | `8.0` | 🧊 frozen (legacy) |

Requires **FFI** enabled and an **x64 NTS** build. `Core::init()` enforces the version match and aborts with a clear message rather than letting you corrupt memory. 🚦

---

## 💙 Thank you

This release exists because of **[Anthropic](https://www.anthropic.com/)** and their **Claude open-source subscription** for maintainers of open-source projects.

Z-Engine sat quiet for five years. Not for lack of ideas — the backlog was full of them — but because the work in front of them was brutal: hand-verifying C struct offsets, reasoning about refcounts across FFI boundaries, chasing segfaults with no stack trace, re-deriving the engine's memory model for every new PHP minor. That is *exactly* the kind of deep, unglamorous, high-context work that used to make a solo maintainer put the project down.

With **Claude Code** in the loop, it became possible again. The memory-ownership overhaul, the opcache binary format work, the class-specialization pass, the hot-swap contract — these were designed, implemented, reviewed and hardened together, at a pace a single pair of hands could never have sustained. **Thank you to the Anthropic team** for building tools that don't just autocomplete, but genuinely help carry the hardest parts of a project — and for backing open source with them. 🙏

Five years later, Z-Engine is alive, documented, statically analysed at level max, and running a real debugger. That's what made the difference. ⚡

---

## 🔗 Links

- 📦 [Packagist](https://packagist.org/packages/lisachenko/z-engine)
- 📖 [README](../README.md) · [Memory model](memory-model.md) · [Long-running PHP](long-running.md) · [Hot-swap](hot-swap.md) · [Persistent heap](persistent-heap.md) · [Class specialization](class-specialization.md) · [OpCache binary](opcache-binary.md)
- 🐞 [Issues](https://github.com/lisachenko/z-engine/issues) · 🤝 [Contributing](../CONTRIBUTING.md)

**Go break something interesting.** 🧨
