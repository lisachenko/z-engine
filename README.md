<div align="center">

# ⚡ Z-Engine

### Write PHP extensions in pure PHP.

**Z-Engine** reaches straight into the heart of the PHP runtime — the Zend Engine — and hands you its internals as ordinary PHP objects. Overload operators, make classes immutable, register real engine modules, rewrite the AST, redefine methods at runtime. No C, no compiler, no recompiling PHP. Just FFI and a lot of nerve.

[![CI](https://img.shields.io/github/actions/workflow/status/lisachenko/z-engine/ci.yml?branch=master&label=CI)](https://github.com/lisachenko/z-engine/actions/workflows/ci.yml)
[![GitHub release](https://img.shields.io/github/release/lisachenko/z-engine.svg)](https://github.com/lisachenko/z-engine/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)

</div>

---

> **⚠️ Experimental — not for production.** Z-Engine operates on raw engine memory. Segfaults are a feature of the territory, not a bug in your code. Pin your PHP version, run it behind a debug build while developing, and never ship it in an app until 1.0.0.

## Why this is different

Every other "runtime magic" library for PHP stops at the boundary of userland. Z-Engine walks straight through it. Using [PHP FFI](https://www.php.net/manual/en/book.ffi.php), it loads the exact C struct definitions of the running engine — `zend_class_entry`, `zval`, `zend_object_handlers`, `zend_module_entry` — and manipulates them the same way a compiled C extension would. The result is a set of capabilities that simply do not exist anywhere else in pure PHP:

| Capability | What you can do |
|---|---|
| 🧮 **Operator overloading** | Give your objects real `+`, `-`, `*`, `/`, `**`, `==` semantics via the engine's `do_operation` and `compare` handlers |
| 🔒 **Custom object handlers** | Hook `create_object`, `read`/`write`/`unset_property`, `cast_object`, `get_property_ptr_ptr` — build truly immutable objects, copy-on-write types, proxies |
| 🧩 **Runtime engine modules** | Register a genuine `zend_module_entry` at runtime, with persistent globals shared across requests — an extension written entirely in PHP |
| 🌳 **Abstract Syntax Tree access** | Parse source to the engine's own AST, inspect it, and rewrite it through the `zend_ast_process` hook |
| 🪞 **Reflection on steroids** | Make a `final` class non-final, add interfaces and methods at runtime, redefine method bodies, change a method's declaring class |
| ⚙️ **Opcode handlers** | Install your own handler for any VM opcode |

## How it works

FFI lets PHP load shared libraries, call C functions, and read C structures without a compiler or a third intermediate language. Z-Engine points that power *back at PHP itself*. It ships **generated, version-exact** FFI definitions of the engine's structures for each supported PHP version, and a runtime that refuses to boot unless the definitions match your interpreter down to the byte. That byte-exactness is what turns "insanely dangerous" into "dangerous but disciplined."

## Requirements & support matrix

- PHP with the **FFI** extension enabled
- **x64, non-thread-safe (NTS)** builds

Engine memory layouts change between every PHP minor version, so each PHP minor has its own generated definitions and its own branch.

| PHP | OS / Arch / TS | Branch | Status |
|-----|----------------|--------|--------|
| 8.5 | linux-x64-nts | `master` | 🚧 in progress |
| 8.4 | linux-x64-nts | `8.4` | ✅ supported |
| 8.0 | linux-x64-nts | `8.0` | 🧊 frozen (legacy) |
| macOS / Windows / ZTS | — | — | 📋 [tracked in issues](https://github.com/lisachenko/z-engine/issues) |

> **Version matching is not optional.** Running Z-Engine against a PHP minor it was not built for corrupts memory. `Core::init()` enforces the match and aborts with a clear message rather than letting you crash.

## Installation

```bash
composer require lisachenko/z-engine
```

Initialize the library once, early in your bootstrap:

```php
use ZEngine\Core;

require __DIR__ . '/vendor/autoload.php';

Core::init();
```

For web (non-CLI) usage, enable FFI preloading by calling `Core::preload()` from the script named in your `opcache.preload` — this loads the engine definitions once at server start instead of per request.

### Hello, impossible

```php
<?php
declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

require __DIR__ . '/vendor/autoload.php';

Core::init();

final class Sealed {}

$reflection = new ReflectionClass(Sealed::class);
$reflection->setFinal(false);

eval('class Extended extends Sealed {}'); // ...it just works.
```

## A tour of the API

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
```

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
$ast = Core::$compiler->parseString('<?php echo 2 + 2;');
echo $ast->dump();
```

You can also install a `zend_ast_process` hook to rewrite the AST of *every* file as it compiles.

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

## See it in action

These libraries are built entirely on Z-Engine and make good, real-world reading:

- **[lisachenko/immutable-object](https://github.com/lisachenko/immutable-object)** — mark a class immutable with a single interface; property writes outside the constructor throw
- **[lisachenko/native-php-matrix](https://github.com/lisachenko/native-php-matrix)** — a `Matrix` type with fully overloaded arithmetic operators

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
