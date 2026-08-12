<!--
Ready-to-post GitHub issue for php/php-src proposing native FFI struct->class
mapping. This file is the issue BODY; the title is the first heading below.
Posting is a separate step (see docs/upstream/README once opened).
-->

# FFI: opt-in mapping of C struct types to userland PHP classes (typed CData handles)

### Feature request

PHP FFI represents every C value — a `struct zend_string*`, a `zval*`, a
`char*`, an `int` — as one and the same final class, `FFI\CData`. That single
opaque type is what makes FFI so flexible, but it also means **no C struct a
binding works with can ever be described to static analysis or an IDE**. There
is no way to say "this handle is a `zend_string`, these are its fields", and no
way to make `$handle instanceof ZendString` true. `FFI\CData` being `final`
closes off every userland workaround.

This proposes an **opt-in, per-scope class map**: when you create an FFI scope
you may declare that C type `X` should be minted as instances of your class
`\My\X` (a `final` class extending `FFI\CData`), so that `FFI::new('X')`,
`FFI::cast('X', …)`, struct-field reads and function returns all produce
`\My\X` instances. Nothing changes for anyone who does not ask for it.

### Motivation — a concrete, load-bearing case study

[z-engine](https://github.com/lisachenko/z-engine) drives the Zend Engine's own
internals through FFI (reflection that mutates, hot-swapping function bodies,
opcache file-cache surgery). It dereferences dozens of engine structs —
`zend_string`, `zend_function`, `zend_class_entry`, `zval`, `zend_op_array`, …
— and every one of them is `FFI\CData`. To recover *any* static typing and IDE
autocompletion the project currently has to ship **all of the following**:

1. a code generator that slices each struct out of the PHP headers via clang and
   emits one analysis-only PHP stub class per struct (with `@property`/typed
   properties mirroring the C fields),
2. a `.phpstorm.meta.php` map so PhpStorm resolves the FFI entry points,
3. a PHPStan dynamic-return extension so the analyser resolves them too,
4. a hand-maintained convention that every one of those stub classes is
   *never loaded at runtime* (they exist only for the analyser), because they
   cannot actually back the `CData` handles.

That is four moving parts, per project, to emulate one feature the runtime
could provide directly — and it is strictly weaker than the real thing: the
stub classes can never make `instanceof` work, can never enforce a parameter
type, and drift from the real ABI unless regenerated. Every FFI binding
generator (SWIG-style wrappers, `FFIMe`, hand-written bindings over libgit2,
libsodium, SDL, …) hits the same wall. A native class map solves it once, for
everyone, in ~the same amount of C code these projects spend working around it.

### Proposal

An optional class map attached to an FFI scope, mapping C struct/union **type
names** to userland classes:

The scope takes an optional **`array $options`** configuration, in the spirit of
`SoapServer`/`SoapClient` (which accept a `classmap`, and `SoapClient` also a
`typemap`). Two keys are recognised — `classmap` (C type → userland class) and
`typemap` (C type → conversion callbacks):

```php
$ffi = FFI::cdef($cCode, $lib, options: [
    'classmap' => [
        'zend_string' => \My\Engine\ZendString::class,
        'zend_value'  => \My\Engine\ZendValue::class,
    ],
    'typemap' => [
        // C type name => how to marshal it to/from PHP (for types that should
        // surface as something other than a raw CData handle)
        'zend_bool' => [
            'from_cdata' => fn(FFI\CData $c): bool => $c->cdata !== 0,
            'to_cdata'   => fn(bool $v, FFI\CData $c): void => $c->cdata = $v ? 1 : 0,
        ],
    ],
]);

final class ZendString extends \FFI\CData
{
    // Fields may be exposed as typed property hooks over the raw CData, and the
    // class may carry ordinary methods.
    public int $len { get => $this->readUint32('len'); }

    public function toPhpString(): string { /* ... */ }
}
```

Rules for a `classmap` class:

- it **must extend `FFI\CData`** (the one place `CData`'s finality is relaxed: a
  class may extend it only while it is registered in a scope's `classmap`),
- it **may declare typed property hooks** whose bodies read/write the underlying
  C fields through the raw CData, and it **may declare methods** — there is no
  "no properties, no constructor" restriction; the object's storage stays ext/ffi's
  `zend_ffi_cdata`, so a hook body operates on the raw structure rather than on a
  real backing store.

Given the map, **every handle ext/ffi mints for a mapped C type** — from
`FFI::new()`, `FFI::cast()`, `FFI::addr()`, a struct-field read that yields a
nested struct/pointer, or a function return value — is created as an instance of
the mapped class instead of the bare `FFI\CData`. `get_class()` is truthful,
`instanceof` works, and native parameter/return type declarations
(`function f(ZendString $s)`) are enforced by the engine. Field access, casting,
`FFI::sizeof()`, garbage collection and every other behaviour are **byte-for-byte
identical to today** — the object still *is* a `zend_ffi_cdata`, only its `ce`
differs.

### Implementation sketch

The change is localized to ext/ffi and is zero-overhead when unused:

- **Registry.** Each `zend_ffi` scope gains a `HashTable *class_map` keyed on the
  resolved `zend_ffi_type *` (populated from `options['classmap']` at `cdef`/`load`
  time by resolving each declared type name to its `zend_ffi_type`, and validating
  the target class extends `zend_ffi_cdata_ce`), plus an optional parallel
  `typemap` table of conversion callbacks. Both are `NULL`/empty for every
  existing user.
- **Minting.** Today every cdata is created with
  `object_init_ex(&zv, zend_ffi_cdata_ce)` (in `zend_ffi_cdata_to_zval()` and
  the `FFI::new`/`FFI::cast` method handlers). Wrap that single choice: when the
  active scope's `class_map` is non-empty, look up the value's
  `zend_ffi_type *`, and if a class is registered use it instead of
  `zend_ffi_cdata_ce`. One hash lookup, guarded by `class_map != NULL`, so the
  common path is unchanged.
- **Layout & lifetime.** The allocated object stays `zend_ffi_cdata`; only the
  `std.ce` pointer changes. All `zend_ffi_cdata_handlers` are shared, so GC,
  free, clone, and the read/write paths need no changes — this is what keeps the
  patch small and safe.
- **Preloading.** For `opcache.preload`ed scopes the map must be re-resolved per
  request (the `zend_ffi_type *` pointers are request/persistent-scoped); the
  natural place is alongside the existing per-request scope materialization.
- **Struct classes carrying methods / property hooks.** Because the mapped class
  is an ordinary `ce` (only the object storage is `zend_ffi_cdata`), methods and
  typed property hooks work with no extra machinery — a hook body just reads or
  writes the underlying C field through the raw CData.
- **Unchanged:** serialization stays forbidden (as for any cdata).

### Backward compatibility

Fully opt-in and additive. No existing FFI program changes behaviour; the new
`options` array (with its `classmap`/`typemap` keys) is the only surface, and it
defaults to "no mapping". The only relaxation is that `FFI\CData` becomes
extendable *for registered classes only* — a normal `class X extends FFI\CData`
without registration can stay an error (or be allowed as an inert never-minted
class, whichever the RFC prefers).

### Target & offer

I'd like to target **PHP 8.6, ahead of feature freeze**, and I'm volunteering to
write the implementation PR (I have a working userland polyfill of the exact
semantics in z-engine and a strong real-world test bed for it). I'd welcome
feedback on the exact shape of the `options` array — the `classmap`/`typemap`
keys mirror SOAP, but the `typemap` callback contract (`from_cdata`/`to_cdata`,
and when they fire) is the main open design point.
