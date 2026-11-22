<div align="center">

# ⚡ Z-Engine

### Write PHP extensions in pure PHP.

**Z-Engine** reaches straight into the heart of the PHP runtime — the Zend Engine — and hands you its internals as ordinary PHP objects. Overload operators, make classes immutable, register real engine modules, rewrite the AST, redefine methods at runtime. No C, no compiler, no recompiling PHP. Just FFI and a lot of nerve.

[![CI](https://img.shields.io/github/actions/workflow/status/lisachenko/z-engine/ci.yml?branch=master&label=CI)](https://github.com/lisachenko/z-engine/actions/workflows/ci.yml)
[![GitHub release](https://img.shields.io/github/release/lisachenko/z-engine.svg)](https://github.com/lisachenko/z-engine/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5%20%7C%208.6-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)

</div>

---

> **⚠️ Experimental — not for production.** Z-Engine operates on raw engine memory. Segfaults are a feature of the territory, not a bug in your code.

## Why would you use it?

Ordinary PHP lets you write programs **on top of** the language. Z-Engine lets you change **the runtime those programs run on**, from a Composer package and in plain PHP.

You hit this limit sooner or later when you write a framework, a test tool, a profiler or a long-running worker. You need behaviour that only a C extension can give you, so you are left with four choices: write and maintain C, give up the feature, depend on a PECL extension your users cannot install, or fake it with code generation, proxies and stream-wrapper tricks. Z-Engine adds a fifth: do what the C extension would do, from PHP, at runtime, on code that is **already loaded**.

**It is a tool for people who build tools:** framework and library authors, testing and tooling authors, people who run long-lived workers (RoadRunner, Swoole, FrankenPHP, ReactPHP, AMPHP), and anyone prototyping a language idea before writing an RFC. It is not meant for the business logic of a typical application.

## Real-world use cases

Each case below starts with the conventional PHP answer and its limit, then shows the Z-Engine way.

### 1. Hot-reload code in a long-running worker

**Usually:** a worker that keeps the framework booted between requests must be **restarted** to pick up a code change, which throws away warm caches, open connections and in-memory state. Dev servers do it on every file save.

**With Z-Engine:** swap the new class definition into the running process. Live objects run the new method bodies straight away. The swap is atomic: all of it applies or none of it does.

```php
use ZEngine\HotSwap\HotSwap;

// Called by your file watcher when src/Pricing/PriceCalculator.php changes
$source = preg_replace('/^<\?php\s*/', '', file_get_contents($changedFile));
$delta  = HotSwap::prepare(PriceCalculator::class, $source);

$delta->getChangedMethods();  // ['calculate', ...]: log or confirm before applying
$delta->apply();              // every existing PriceCalculator now runs the new code
```

Changed or added methods, constants and default values are supported. Changes that would break live instances (a different property layout or class hierarchy) are refused with a `HotSwapException` before anything is touched. [→ docs/hot-swap.md](docs/hot-swap.md)

### 2. Mock `final` classes and patch vendor code in tests

**Usually:** PHPUnit cannot mock a `final` class. Workarounds such as `dg/bypass-finals` rewrite the source in a stream wrapper, which only works for classes **not loaded yet** and conflicts with opcache. `uopz` works, but it is a C extension you have to install on every CI image.

**With Z-Engine:** change the loaded class itself.

```php
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionMethod;

// A final SDK client from vendor/ that would otherwise hit a real payment API
(new ReflectionClass(PaymentClient::class))->setFinal(false); // createMock() works now

// ...or patch one method, keeping the original callable
(new ReflectionMethod(PaymentClient::class, 'charge'))->redefine(
    fn (Money $amount): Receipt => Receipt::fake($amount),
    preserveAs: 'chargeForReal',
);
```

### 3. Trace or profile any method without proxies or code generation

**Usually:** to time or log calls on a class you do not own, you generate a decorator or proxy class, wire it into the container, and hope nobody calls `new` directly. Or you install a C-level APM agent.

**With Z-Engine:** wrap the method body in place. Every caller is covered, `new` and static calls included, because the engine's own function entry is changed.

```php
(new ReflectionMethod(OrderRepository::class, 'find'))->redefine(
    function (int $id): ?Order {
        $start = hrtime(true);
        try {
            return $this->findUntraced($id);       // the original body
        } finally {
            Tracer::record('OrderRepository::find', hrtime(true) - $start);
        }
    },
    preserveAs: 'findUntraced',
);
```

For compile-time rewriting, a `zend_ast_process` hook (`Core::setASTProcessHandler()`) lets you change the AST of **every file as it is compiled**. You can also patch opcache's file-cache binaries so the cached code is transformed before it runs ([→ docs/opcache-binary.md](docs/opcache-binary.md)).

### 4. Value objects that behave like numbers

**Usually:** money, decimals, vectors and matrices end up as `$total->add($tax)->multiply($qty)`, and `==` compares properties instead of values.

**With Z-Engine:** give the class real operators through the engine's `do_operation` and `compare` handlers, the same mechanism `GMP` and `BcMath\Number` use in C.

```php
$total = $price * $quantity + $shipping;   // Money objects, not floats
if ($total > $creditLimit) { /* ... */ }   // compared by amount
```

The full example is under [Operator overloading](#operator-overloading). [native-php-matrix](https://github.com/lisachenko/native-php-matrix) is a complete `Matrix` type built this way.

### 5. Reified generics, enforced by the engine

**Usually:** `Collection<User>` exists only in docblocks. PHPStan and Psalm check it, but at runtime anything can be pushed in.

**With Z-Engine:** turn a template class into a real, separate class whose type declarations are rewritten, so the engine's own type checks enforce them.

```php
$users = (new ReflectionClass(TypedList::class))->specialize(
    'App\TypedList_User',
    new TypeSubstitutionMap(['App\T' => User::class]),
)->newInstance();

$users->add(new User());  // fine
$users->add('bob');       // TypeError, raised by the engine
```

[userland-php-generics](https://github.com/lisachenko/userland-php-generics) builds `Box::of('int')`-style generics on top of this. [→ docs/class-specialization.md](docs/class-specialization.md)

### 6. Keep object graphs warm across requests, without `unserialize()`

**Usually:** a routing tree, compiled container or parsed config is cached in APCu or a file and **copied back with `unserialize()` on every request**, and that cost grows with the size of the graph.

**With Z-Engine:** store the graph once in persistent memory. Later requests in the same worker process attach to it directly.

```php
use ZEngine\EngineExtension\ExtensionManager;
use ZEngine\EngineExtension\ZEngineModule;
use ZEngine\Memory\PersistentHeap;

ExtensionManager::register(new ZEngineModule());
$heap = PersistentHeap::global();

if (($routes = $heap->get('routes')) === null) {
    $heap->put('routes', RouteCompiler::compile());  // deep persistent clone, done once
    $routes = $heap->get('routes');
}
```

Unsupported values (closures, resources, internal objects) are rejected up front, before anything is allocated. [→ docs/persistent-heap.md](docs/persistent-heap.md)

### 7. Step-debug where Xdebug cannot be installed

**Usually:** step debugging means installing Xdebug, which you cannot do on locked-down hosts, some managed containers or a colleague's laptop on short notice.

**With Z-Engine:** statement-level hooks and access to live frame variables are enough to build a debugger in PHP. **[ZDebug](https://github.com/lisachenko/zdebug)** is one: a DBGp debugger your IDE attaches to, with no C extension. [→ docs/self-debugging.md](docs/self-debugging.md)

### 8. Prototype a language feature before writing the RFC

**Usually:** trying out new syntax or semantics means patching php-src in C, rebuilding PHP, and asking reviewers to do the same.

**With Z-Engine:** rewrite the AST at compile time, install your own handler for any VM opcode, add functions to the engine's function table, or register a full engine module with persistent globals. A prototype becomes a `composer require` that anyone can try.

## Strengths

- **No C, no compiler, no PECL.** `composer require` works anywhere ext-ffi is enabled: Linux, macOS and Windows, NTS and ZTS.
- **Works on code that is already loaded**, including vendor classes and opcache-cached code. Source-rewriting tools only see a class before it is loaded; Z-Engine changes the live engine structures, so preloaded and cached code is in scope too.
- **Real engine semantics, not emulation.** A specialized class is a real class entry, a substituted type is checked by the engine, and a generated function (`ReflectionFunction::addFunction()`) runs through the normal VM with no FFI overhead per call.
- **Atomic, reversible changes.** Hot swaps commit completely or roll back byte for byte. Hooks have `install()`/`uninstall()`, and all of them are restored at shutdown.
- **Byte-exact by construction.** Engine definitions are generated from the real PHP build for each version, OS, architecture and thread-safety mode. `Core::init()` refuses to run on a PHP minor it was not built for, and can check every struct layout before it touches memory.
- **Built for long-running processes.** A clear ownership model with deterministic releases (see [Memory safety](#memory-safety--long-running-php)) keeps worker loops and FPM with preload stable.
- **Typed, analysable API.** Consumers get PHP objects (`ReflectionClass`, `ReflectionValue`, `PersistentHeap`, …), not raw pointers. The codebase passes PHPStan at level max, and generated stubs type every engine struct.

## When not to use it

- **When PHP already has the feature.** Use `readonly`, property hooks, asymmetric visibility, enums or first-class callables where they are enough.
- **In production request paths, for now.** The project is experimental, and a wrong move crashes the process instead of throwing an exception.
- **When the JIT is required.** The JIT rewrites the executor internals Z-Engine hooks into, so it must be off (`opcache.jit=off`).
- **When FFI is disabled.** Hosts that set `ffi.enable=0`, and cannot set `ffi.enable=preload` together with opcache preload, cannot run it.

## How it works

FFI lets PHP load shared libraries, call C functions, and read C structures without a compiler or a third intermediate language. Z-Engine points that power *back at PHP itself*. It ships **generated, version-exact** FFI definitions of the engine's structures for each supported PHP version, and a runtime that refuses to boot unless the definitions match your interpreter down to the byte. That byte-exactness is what turns "insanely dangerous" into "dangerous but disciplined."

## Requirements & support matrix

- PHP with the **FFI** extension enabled

Engine memory layouts change between every PHP minor version, so each PHP minor has its own generated definitions and its own branch.

| PHP | OS / Arch / TS | Branch | Status |
|-----|----------------|--------|--------|
| 8.6 | linux-x64 (nts, zts), darwin-x64 (nts, zts), darwin-arm64 (nts, zts), windows-x64 (nts, zts) | `master` | ✅ supported¹ |
| 8.5 | linux-x64 (nts, zts), darwin-x64 (nts, zts), darwin-arm64 (nts, zts), windows-x64 (nts, zts) | `8.5` | ✅ supported |
| 8.4 | linux-x64 (nts, zts), darwin-x64 (nts, zts), darwin-arm64 (nts, zts), windows-x64 (nts, zts) | `8.4` | ✅ supported |
| 8.0 | linux-x64-nts | `8.0` | 🧊 frozen (legacy) |

¹ Supported since the PHP 8.6.0 release: the committed linux definitions regenerate byte for byte from the 8.6.0 GA build, and CI re-verifies the darwin ones on macOS runners. The windows-x64 definitions come from the "Generate windows headers" workflow, run against the windows.php.net 8.6 builds.

> **Version matching is not optional.** Running Z-Engine against a PHP minor it was not built for corrupts memory. `Core::init()` enforces the match and aborts with a clear message rather than letting you crash.

## Installation

```bash
composer require lisachenko/z-engine
```

There is nothing to initialize: the engine bridge is booted from Composer's autoloader, so
`require __DIR__ . '/vendor/autoload.php'` is all a consumer needs. That includes the
`opcache.preload` stage, which the bootstrap recognises and serves by publishing the engine
definitions for the life of the server rather than for the preload request alone — pointing
`opcache.preload` at a script that only requires the autoloader is enough to get the
per-request cost down.

`opcache.preload` does not exist on Windows, so there the definitions cannot be published once
at server start: every process binds them for itself. That needs no change on your side either —
it is the same autoload bootstrap, taking the other branch.

### Web SAPIs and `ffi.enable=preload`

PHP's default `ffi.enable=preload` allows the FFI API on the CLI, but at request time in FPM,
Apache or the built-in server only from code that `opcache.preload` compiled at server start.
So a web SAPI needs z-engine to be *part of the preload stage* — nothing else can bind the
engine there, not even a header published through `ffi.preload`, because the restriction is on
the code that calls FFI, not on the definitions. The whole configuration is (php.ini or an FPM
`php_admin_value`, never `.user.ini` or `ini_set()`; restart the server afterwards):

```ini
opcache.enable=1
opcache.preload=/path/to/vendor/lisachenko/z-engine/preload.php
opcache.preload_user=www-data   ; FPM only, when the master process runs as root
```

Any preload script that requires your Composer autoloader does the same job as the bundled one.
When the bridge cannot bind under preload mode, `Core::init()` throws a `RuntimeException`
whose message is this recipe with the paths resolved for the failing build, plus a diagnosis
of the settings already in place; `Core::preloadConfigurationHint()` returns the same text on
demand. Two more directives are worth knowing:

- **`ffi.preload`** publishes the C definitions once at module startup, so no request parses
  the header. Its value is the generated header for the exact build — minor, OS, architecture
  and thread-safety mode — which `Core::engineHeaderPath()` resolves; run it under the PHP the
  server uses, since a CLI binary can be a different build than php-fpm:

  ```bash
  php -r 'require "vendor/autoload.php"; echo ZEngine\Core::engineHeaderPath();'
  ```

  It is optional next to `opcache.preload` (z-engine takes a scope that is already published
  instead of loading the header again), and it is the way to bind once under `ffi.enable=1`.
- **`ffi.enable=1`** allows FFI for every caller; z-engine then binds the definitions per
  request, or once at startup with `ffi.preload`. On Windows, where `opcache.preload` does not
  exist, this is the only way to run a web SAPI.

`Core::init()` remains public, idempotent and re-invocable, for the cases that want it: booting
explicitly at a chosen point, re-booting after `Core::shutdown()` inside a live worker, and
turning "the engine is not available here" into its explanation. On a host that cannot run the
engine at all — no ext-ffi, `ffi.enable=0`, an unsupported PHP minor or platform — autoloading
stays silent and leaves `Core` uninitialized, so static analysis and test suites still load the
package; ask `Core::isInitialized()` for the state, or call `Core::init()` to get the reason.
Set `ZENGINE_AUTOBOOT=0` to skip the automatic boot entirely.

### Hello, impossible

```php
<?php
declare(strict_types=1);

use ZEngine\Reflection\ReflectionClass;

require __DIR__ . '/vendor/autoload.php';

final class Sealed {}

$reflection = new ReflectionClass(Sealed::class);
$reflection->setFinal(false);

eval('class Extended extends Sealed {}'); // ...it just works.
```

## A tour of the API

Using [PHP FFI](https://www.php.net/manual/en/book.ffi.php), Z-Engine loads the exact C struct definitions of the running engine (`zend_class_entry`, `zval`, `zend_object_handlers`, `zend_module_entry`) and changes them the way a compiled C extension would:

| Capability | What you can do |
|---|---|
| 🪞 **Reflection on steroids** | Make a `final` class non-final, add interfaces and methods at runtime, redefine method bodies, hot-swap whole classes |
| 🧮 **Operator overloading** | Give your objects real `+`, `-`, `*`, `/`, `**`, `==` semantics via the engine's `do_operation` and `compare` handlers |
| 🔒 **Custom object handlers** | Hook `create_object`, `read`/`write`/`unset_property`, `cast_object`, `get_property_ptr_ptr` to build truly immutable objects, copy-on-write types and proxies |
| 🧬 **Class specialization** | Clone a class under a new name with its types rewritten (the basis for reified generics) |
| 🧩 **Runtime engine modules** | Register a genuine `zend_module_entry` at runtime, with persistent globals shared across requests: an extension written entirely in PHP |
| 💾 **Persistent heap** | Keep whole object graphs alive across requests in a worker process |
| 🌳 **Abstract Syntax Tree access** | Parse source to the engine's own AST, inspect it, and rewrite it through the `zend_ast_process` hook |
| ⚙️ **Opcode handlers** | Install your own handler for any VM opcode |

### Reflection, extended

`ZEngine\Reflection\ReflectionClass` and `ReflectionMethod` extend the native reflection classes with write access to the engine:

```php
$class = new ReflectionClass(Sealed::class);
$class->setFinal(false);                 // un-final a class
$class->setAbstract(true);               // make it abstract
$class->addInterfaces(Countable::class); // graft on an interface at runtime
$class->addMethod('count', fn() => 42);  // add a method from a closure

$method = new ReflectionMethod(Service::class, 'handle');
$method->setPublic();
$method->redefine(fn() => 'patched');    // swap the method body
$method->redefine(fn() => 'wrapped:' . $this->handleOriginal(), preserveAs: 'handleOriginal'); // ...keeping the previous body callable
$method->redefine($donor->getMethod('handle'));                   // or adopt the body of another class's method
```

### Generated functions — no FFI trampoline

Turn a closure into a genuine engine function or method. Unlike a closure installed into an
engine handler field (which ext/ffi calls back into through a slow libffi **trampoline**),
a generated function is published straight into the engine's function table and afterwards
dispatches through the normal Zend VM with **zero FFI at call time** — exactly as fast as
any ordinary PHP function:

```php
use ZEngine\Reflection\ReflectionFunction;

ReflectionFunction::addFunction('twice', fn (int $x): int => $x * 2);
twice(21); // 42 — a real global function, no trampoline

$class->addMethod('scale', fn (float $k) => ...); // same, as a method
```

See [docs/memory-model.md](docs/memory-model.md) for how PHP zvals, FFI trampolines and
native C handlers map to memory, and why this path is fast.

### Operator overloading

Give your value objects native arithmetic. Implement the extension interfaces and install the handlers with one call:

```php
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\Reflection\ReflectionClass;

class Matrix implements ObjectCreateInterface, ObjectDoOperationInterface, ObjectCompareValuesInterface
{
    use ObjectCreateTrait;

    public static function __doOperation(DoOperationHook $hook): self { /* ... */ }
    // public static function __compare(CompareValuesHook $hook): int { ... }
}

(new ReflectionClass(Matrix::class))->installExtensionHandlers();

$c = new Matrix([10, 20, 30]) + new Matrix([1, 2, 3]); // Matrix([11, 22, 33])
$c *= 2;                                                //   → Matrix([22, 44, 66])
```

No access to the class source (e.g. it lives in `vendor/`)? Install the handlers imperatively instead:

```php
$class = new ReflectionClass(Matrix::class);
$class->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$class->setWritePropertyHandler(fn ($hook) => /* ... */);
```

The available object hooks are `create_object`, `cast_object`, `do_operation`, `compare`, `read_property`, `write_property`, `has_property`, `unset_property`, `get_property_ptr_ptr`, `get_properties_for`, and `interface_gets_implemented`.

> Install the `create_object` handler **first** — the other hooks live in memory that it allocates. Internal classes can't receive a `create_object` handler.

### The object store

Look up any live object by its handle — an API PHP itself doesn't expose:

```php
$instance = new stdClass();
$entry    = Core::$executor->objectStore[spl_object_id($instance)];
```

### Abstract Syntax Tree

Parse PHP source to the engine's own AST and walk it:

```php
$ast = Core::$compiler->parseString('echo 2 + 2;');
echo $ast->dump();
```

You can also install a `zend_ast_process` hook to rewrite the AST of *every* file as it compiles.

### OpCache binary files

Read the binary files opcache writes for `opcache.file_cache`, patch the compiled script through the framework wrappers, and write a valid binary back — so the engine loads and executes your patched code on the next request:

```php
use ZEngine\OpCache\BinaryCacheFile;

$file       = BinaryCacheFile::compile(__DIR__ . '/Service.php', $cacheDir);
$reflection = $file->getReflection();   // ReflectionExtension-shaped handle over the cached script
// ... mutate literals, opcodes, flags through the usual wrappers ...
$file->refresh();                       // rewrite the binary + invalidate the source
```

The payload is re-serialized from the mutated graph (not just byte-poked), so size-changing edits are written correctly. See **[docs/opcache-binary.md](docs/opcache-binary.md)** for the format, build-matching rules and current limits — this is the foundation for AOP, transpiling and source-code protection on top of the file cache.

### Debugger-grade introspection

Statement-level interception with named live frame variables — the raw material for
a pure-PHP step debugger:

```php
use ZEngine\System\Compiler;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

Core::$compiler->setOptions(Core::$compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);
OpCode::setHandler(OpCode::EXT_STMT, function (ExecutionData $frame): int {
    $line   = $frame->getOpline()->getLine();
    $locals = $frame->getLocalVariables();     // ['n' => ReflectionValue, ...]
    return Core::ZEND_USER_OPCODE_DISPATCH;
});
```

Engine error and VM interrupt hooks (`Core::setErrorCallbackHandler()`,
`Core::setInterruptHandler()` + `Core::$executor->requestInterrupt()`) round out the
breakpoint/pause primitives. See **[docs/self-debugging.md](docs/self-debugging.md)**
for the full feasibility research: what an Xdebug-equivalent can and cannot do from
pure PHP, and why — and **[ZDebug](https://github.com/lisachenko/zdebug)** for the
debugger built on it: a DBGp step debugger your IDE attaches to, with no C extension.

### Extensions written in PHP

Register a real engine module at runtime, complete with persistent globals shared across requests:

```php
use ZEngine\EngineExtension\AbstractModule;

final class Counter extends AbstractModule
{
    protected static function globalType(): ?string { return 'unsigned int[10]'; }
}

$module = new Counter('counter');
$module->register();
$module->startup();
$globals = $module->getGlobals(); // FFI-backed, survives across requests
```

## Memory safety & long-running PHP

Every value wrapper follows an explicit ownership model: owning constructors take their own
engine reference and release it deterministically (`release()`/destruction), `fromCData()`
factories stay borrowed, and all releases go through the engine's own primitives
(`zval_ptr_dtor`/`rc_dtor_func`) — never through the FFI allocator. Engine hooks have a full
lifecycle (`install()`/`uninstall()`/`reinstall()`) backed by a registry, and `Core::shutdown()`
(registered automatically) restores every hooked engine pointer before the engine could ever
call a freed trampoline — which is what makes worker loops and FPM + opcache preload viable.

Notable behaviour changes compared to older releases:

- `new StringEntry()` / `new ObjectEntry()` / `new ResourceEntry()` addref and keep their
  target alive for the wrapper lifetime; `ClosureEntry::setThis()` releases the old bound
  `$this` and references the new one (no more "object must outlive the closure").
- `Compiler::parseString()` trees free themselves when the last node wrapper is collected.
- `AbstractHook::__destruct()` no longer force-restores pointers at arbitrary GC moments.

See [docs/long-running.md](docs/long-running.md) for the ownership tables, the hook
lifecycle, runtime models (worker vs FPM), and the short list of immortal-by-design
allocations.

## Built on Z-Engine

These projects are built entirely on Z-Engine and are good, real-world reading:

- **[lisachenko/immutable-object](https://github.com/lisachenko/immutable-object)** — mark a class immutable with a single interface; property writes outside the constructor throw
- **[lisachenko/native-php-matrix](https://github.com/lisachenko/native-php-matrix)** — a `Matrix` type with fully overloaded arithmetic operators
- **[lisachenko/userland-php-generics](https://github.com/lisachenko/userland-php-generics)** — reified generics: `Box::of('int')` monomorphizes a template into a real class entry whose `int` the engine enforces, and measures what that costs

## Contributing

Z-Engine has a couple of unusual rules — most importantly, **match your PHP version to the branch** and develop against a debug build. See **[CONTRIBUTING.md](CONTRIBUTING.md)** and **[AGENTS.md](AGENTS.md)** (the full contract for humans and automated tools). Engine definitions are generated from the PHP source by `tools/generator/` and never hand-edited.

```bash
composer test        # safe suite
composer test:internal   # destructive tests, on a debug PHP build
composer phpstan     # static analysis at level max
composer cs:check    # coding standards
```

## License

Released under the [MIT License](LICENSE).
