<!--
wiki.php.net-style RFC draft for the FFI struct->class map feature. This is a
starting draft to move to the wiki once the internals discussion warrants it;
the companion GitHub issue (php-src-ffi-struct-class-map.md) is the informal
opener.
-->

# PHP RFC: Typed FFI CData via opt-in struct-to-class mapping

- **Version:** 0.1 (draft)
- **Date:** 2026-08-11
- **Author:** (proposed via z-engine, lisachenko/z-engine)
- **Status:** Draft
- **Target version:** PHP 8.6
- **Implementation:** (to be written; userland polyfill exists in z-engine)

## Introduction

`FFI\CData` is the single, `final` class that PHP FFI uses for every C value it
exposes — scalars, pointers, structs and arrays alike. Because it is one opaque
final type, a C `struct` handled through FFI cannot be described to static
analysis or IDEs, cannot participate in `instanceof`, and cannot carry methods.
This RFC proposes an **opt-in, per-scope mapping** from C struct/union type names
to user-declared `final` classes extending `FFI\CData`, so that handles of those
types are minted as instances of the user's class. It is fully backward
compatible and zero-overhead when unused.

## Proposal

When an FFI scope is created, the author may register a map from C type names to
classes:

```php
$ffi = FFI::cdef($code, $lib, classMap: [
    'point' => \Geometry\Point::class,
]);

final class Point extends \FFI\CData
{
    public function distanceTo(Point $other): float { /* reads $this->x, ... */ }
}
```

A registered class must be `final`, must extend `FFI\CData`, and must declare no
instance properties and no constructor. Given the map, every `CData` ext/ffi
mints for a mapped C type — `FFI::new()`, `FFI::cast()`, `FFI::addr()`,
struct-field reads yielding nested structs/pointers, and function return values —
is created as an instance of the mapped class. Its runtime representation is
unchanged (it *is* an ext/ffi `zend_ffi_cdata`); only its class entry differs, so
field access, casting, `sizeof`, GC and every existing behaviour are identical.
The payoff: `get_class()` is truthful, `instanceof` works, native
parameter/return types are enforced, and the class may carry methods.

Three candidate spellings are on the table (a named `classMap:` argument on
`cdef`/`load`, a `#[\FFI\CStruct('name')]` attribute on the class, or a fluent
`FFI::mapType()`); the final choice is an open question below.

## Backward Incompatible Changes

None. The feature is entirely opt-in: existing FFI code mints `FFI\CData` exactly
as before. The only relaxation is that `FFI\CData` becomes extendable by classes
that are registered in a scope's class map (an unregistered `extends FFI\CData`
may remain an error).

## Proposed PHP Version(s)

PHP 8.6.

## RFC Impact

- **To SAPIs / existing extensions:** none.
- **To Opcache / preloading:** a mapped scope's registry must be re-resolved per
  request (the `zend_ffi_type *` keys are request/persistent-scoped), handled
  alongside the existing per-request scope materialization.
- **To ext/ffi internals:** one `HashTable` per scope keyed on `zend_ffi_type *`,
  consulted only when non-empty at the single `object_init_ex(…,
  zend_ffi_cdata_ce)` mint site; registration-time validation of the target
  class. Object layout and handlers are unchanged.

## Open Issues

1. **API surface** — `classMap:` argument vs `#[\FFI\CStruct]` attribute vs
   `FFI::mapType()`; possibly support more than one.
2. **Mapping scalars / typedefs** — v1 restricts mapping to struct/union types;
   whether to later allow scalar typedefs (e.g. a `Handle` wrapper over an `int`)
   is deferred.
3. **Unregistered `extends FFI\CData`** — hard error vs inert never-minted class.
4. **`FFI::cast()` across scopes** — behaviour when casting a handle into a type
   mapped in a *different* scope.

## Future Scope

- Methods and, potentially, mapped scalar wrappers.
- A generator/attribute pathway so binding tools can emit mapped classes directly
  from C headers.

## Proposed Voting Choices

Single yes/no vote, requiring the usual 2/3 majority, on: "Add opt-in
struct-to-class mapping to FFI as described, targeting PHP 8.6."

## Patches and Tests

- Implementation PR: to be written (author volunteering).
- A working userland polyfill of the exact semantics, plus an extensive
  real-world test bed, exists in [z-engine](https://github.com/lisachenko/z-engine).

## References

- Companion GitHub issue: `docs/upstream/php-src-ffi-struct-class-map.md`.
- ext/ffi mint sites: `zend_ffi_cdata_to_zval()`, `ZEND_METHOD(FFI, new)`,
  `ZEND_METHOD(FFI, cast)`.
