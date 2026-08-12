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
to user-declared classes extending `FFI\CData`, so that handles of those types
are minted as instances of the user's class. It is fully backward compatible and
zero-overhead when unused.

## Proposal

An FFI scope takes an optional **`array $options`** configuration — the same
shape SOAP already uses (`SoapServer`/`SoapClient` accept a `classmap`, and
`SoapClient` additionally a `typemap`). Two keys are recognised:

- **`classmap`** — maps a C struct/union type name to a userland class. Handles
  of that C type are minted as instances of the class.
- **`typemap`** — maps a C type name to conversion callbacks, for types that
  should surface as something other than a raw `CData` handle (scalars/typedefs,
  or a custom (de)serialization of a struct), analogous to `SoapClient`'s
  `typemap`.

```php
$ffi = FFI::cdef($code, $lib, options: [
    'classmap' => [
        'point' => \Geometry\Point::class,
    ],
    'typemap' => [
        // C type name => how to marshal it to/from PHP
        'timestamp_t' => [
            'from_cdata' => fn(FFI\CData $c): \DateTimeImmutable => /* ... */,
            'to_cdata'   => fn(\DateTimeImmutable $d, FFI\CData $c): void => /* ... */,
        ],
    ],
]);

final class Point extends \FFI\CData
{
    // Fields may be exposed as typed property hooks over the underlying CData,
    // and the class may carry ordinary methods.
    public float $x { get => $this->readFloat('x'); set => $this->writeFloat('x', $value); }

    public function distanceTo(Point $other): float { /* ... */ }
}
```

A registered `classmap` class must extend `FFI\CData`. It **may** declare typed
property hooks that read/write the underlying C fields through the raw CData, and
it **may** declare methods — there is no "no properties, no constructor"
restriction (the object's storage is still ext/ffi's `zend_ffi_cdata`, so a hook
body operates on the raw structure rather than on a real backing store). Given
the map, every `CData` ext/ffi mints for a mapped C type — `FFI::new()`,
`FFI::cast()`, `FFI::addr()`, struct-field reads yielding nested structs/pointers,
and function return values — is created as an instance of the mapped class. Its
runtime representation is unchanged (it *is* an ext/ffi `zend_ffi_cdata`); only
its class entry differs, so field access, casting, `sizeof`, GC and every
existing behaviour are identical. The payoff: `get_class()` is truthful,
`instanceof` works, native parameter/return types are enforced, and the class
carries methods and hooked properties.

## Backward Incompatible Changes

None. The feature is entirely opt-in: existing FFI code passes no `options` (or
one without `classmap`/`typemap`) and mints `FFI\CData` exactly as before. The
only relaxation is that `FFI\CData` becomes extendable by classes that are
registered in a scope's `classmap` (an unregistered `extends FFI\CData` may
remain an error).

## Proposed PHP Version(s)

PHP 8.6.

## RFC Impact

- **To SAPIs / existing extensions:** none.
- **To Opcache / preloading:** a mapped scope's registry must be re-resolved per
  request (the `zend_ffi_type *` keys are request/persistent-scoped), handled
  alongside the existing per-request scope materialization.
- **To ext/ffi internals:** one `HashTable` per scope keyed on `zend_ffi_type *`
  (populated from `classmap`), consulted only when non-empty at the single
  `object_init_ex(…, zend_ffi_cdata_ce)` mint site; registration-time validation
  of the target class; the optional `typemap` callbacks stored per resolved type.
  Object layout and handlers are unchanged.

## Open Issues

1. **`typemap` callback shape** — the exact `from_cdata`/`to_cdata` signature and
   when they fire (on every read/write vs. at boundary conversions only).
2. **Unregistered `extends FFI\CData`** — hard error vs inert never-minted class.
3. **`FFI::cast()` across scopes** — behaviour when casting a handle into a type
   mapped in a *different* scope.
4. **Property-hook interaction** — confirming hook bodies on a `zend_ffi_cdata`
   (which has no real property storage) compose cleanly with the field handlers.

## Future Scope

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
