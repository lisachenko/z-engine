# Runtime class-entry specialization

`ClassSpecializer` deep-clones an existing, linked **userland** `zend_class_entry` under
a new runtime name, applies a controlled type-substitution pass over the copy and
registers the result in `CG(class_table)` as a first-class, instantiable class. It is an
engine-level primitive: the copy behaves like a class the compiler could have produced
itself, and the standard engine teardown dismantles it.

## API

```php
use ZEngine\Reflection\ClassSpecializer;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\TypeSubstitutionMap;

// Via the reflection surface ...
$template    = new ReflectionClass(SomeTemplate::class);
$specialized = $template->specialize('App\Specialized\SomeTemplateInt', new TypeSubstitutionMap([
    'App\TPlaceholder' => 'int',
]));

// ... or via the service directly
$specialized = (new ClassSpecializer())->specialize(
    SomeTemplate::class,
    'App\Specialized\SomeTemplateInt',
    new TypeSubstitutionMap(['App\TPlaceholder' => 'int']),
);

$instance = $specialized->newInstance();     // or: new \App\Specialized\SomeTemplateInt()
```

A *placeholder* is a class-like type name used in the template declaration (for example
`public TPlaceholder $value;` where `TPlaceholder` is never defined as a real class).
`TypeSubstitutionMap` maps placeholder names to concrete types; matching is
case-insensitive and ignores a leading `\`. Replacement targets can be:

- builtin types: `int`, `float`, `string`, `bool`, `true`, `false`, `null`, `array`,
  `object`, `mixed` — the placeholder `zend_type` becomes the corresponding `MAY_BE_*`
  mask (declared nullability, e.g. `?T`, is preserved);
- any class/interface name — the placeholder becomes a class-type with an owned name
  string, resolved lazily by the engine like every class type.

Substitution rewrites `zend_type` in the **copied** `zend_property_info` entries and in
duplicated `arg_info` blocks (parameter and return types) of copied methods. Engine-level
enforcement follows the substituted type on the copy only: assigning a mismatched value
to a substituted typed property throws `TypeError` on the specialized class while the
template keeps its original declaration.

## Semantics of the copy

- The specialized class is a **sibling** of the template: same parent, same interfaces.
  Instances of the copy are *not* `instanceof` the template.
- `static::` (late static binding), `self::` method calls, `new self()`, typed-property
  checks and static-property storage resolve against the copy: every copied
  `zend_function->common.scope` points at the new class entry.
- Compile-time-resolved literals keep the template's spelling: `self::class`,
  `__CLASS__` and constants folded by the compiler were baked into the shared opcodes as
  strings and still name the template. Use `static::class` for the runtime identity.
- Private properties keep their engine-mangled names (`\0TemplateName\0prop`) because
  the mangled `zend_string` is shared; this is cosmetic (var_dump/serialization output),
  slot access is offset-based and correct.
- Static properties are independent: the copy materializes its own live static-members
  table from the copied defaults on first access. Statics inherited from a parent remain
  `IS_INDIRECT` views into the (shared) parent storage, exactly like a regular subclass.
- Class constants declared by the template are copied (their `ce` is re-targeted, so
  lazily evaluated constant ASTs bind against the copy); constants inherited from
  parents/interfaces stay shared unless the engine had already materialized a
  class-owned copy (`CONST_OWNED`), which is copied too.

## Copy model (what is shared, what is duplicated)

| Structure | Strategy |
|-----------|----------|
| `zend_class_entry` | new request-memory block; storage flags (`ZEND_ACC_IMMUTABLE/CACHED/FILE_CACHED/PRELOADED`) cleared; fresh `refcount = 1`; `mutable_data`, `static_members_table`, `inheritance_cache` reset |
| Class name | fresh owned `zend_string` (released by `destroy_zend_class()`) |
| Own methods (scope == template, incl. trait clones) | **duplicated `zend_op_array` struct, shared body**: opcodes/literals/vars stay shared through the op_array `refcount` (the engine's own trait-clone model); per copy: `scope` = new CE, `function_name` addref, `run_time_cache` and `static_variables_ptr` reset to NULL (lazily re-materialized per copy), `ZEND_ACC_IMMUTABLE`/`ZEND_ACC_HEAP_RT_CACHE` cleared |
| Inherited methods | shared pointer with `(*refcount)++` + name addref, exactly like `zend_duplicate_function()` during engine inheritance |
| `arg_info` | shared with the body by default; duplicated into request memory (names addref'd, types deep-copied) only for methods whose signature contains a substituted placeholder |
| Own `zend_property_info` | copied block; `ce` re-targeted; name/doc-comment/attributes referenced; `zend_type` deep-copied with substitution; `prototype` self-references re-targeted onto the copies |
| Inherited `zend_property_info` | shared pointer (teardown only touches own entries) |
| `default_properties_table` / `default_static_members_table` | duplicated `zval[]` blocks with one owned reference per refcounted slot (`zval_add_ref`); `IS_INDIRECT` static slots copied as-is |
| `properties_info_table` | duplicated slot array (own slots point at the copied infos) |
| Own / `CONST_OWNED` class constants | copied `zend_class_constant` blocks with owned value/doc/attribute references |
| Inherited class constants | shared pointer |
| Interface list | duplicated pointer array (engine `efree`s it per class) |
| Trait names / aliases / precedences | deep-copied with owned name references (the engine releases and frees them per class) |
| Class attributes, doc comment, filename | shared with one owned reference each (interned/immutable payloads are reference-transparent and skipped symmetrically) |
| `iterator_funcs_ptr` / `arrayaccess_funcs_ptr` | fresh per-class blocks; the engine-filled method pointers are re-targeted through the copied method table |
| Union/intersection `zend_type` lists | duplicated into request memory with the `_ZEND_TYPE_ARENA` ownership bit (names released by the engine, the block reclaimed by the request allocator) |

### Share-vs-duplicate rationale for method bodies

Duplicating the `zend_op_array` *struct* while sharing the compiled *body* through the
engine's own refcount is exactly what `zend_bind_traits()` does for trait methods. It
gives every copy an independent scope, run-time cache and live static-variables table
(correct `self::`/`static::`/inline-cache behavior) at the cost of ~`sizeof(zend_op_array)`
per method instead of a full opcode copy, and it keeps teardown symmetric: each holder
releases its own name reference and body reference, and the last one frees the body.
Unlike real trait binding, the copies do NOT get `ZEND_ACC_TRAIT_CLONE`: the engine sets
that flag only for methods materialized during trait binding, and regular own-method
copies must not carry it (trait clones of the source keep the flag they already had).

## Memory ownership (per docs/memory-model.md and docs/long-running.md)

Everything the engine tears down per user class (`destroy_zend_class()`:
default-value tables, own property-info payloads, own constants, the interface array,
trait metadata, the embedded hashtables) is allocated as plain request memory with
engine assignment semantics — the copy dies exactly like a compiler-produced class, as
covered by the explicit-teardown test.

Structures the engine deliberately never frees for userland classes — the
`zend_class_entry` struct itself, `zend_property_info`/`zend_class_constant` blocks,
`properties_info_table`, the iterator/arrayaccess caches, duplicated `arg_info` blocks
and duplicated type lists — are compiler-**arena** allocations in a normal class. The
specializer mimics the arena with plain request allocations that are reclaimed by the
request allocator at request end. They are *request-lifetime by design*, not leaks —
though unlike real arena blocks they do show up in a debug build's `report_memleaks`
output (see the expected-allocations table in long-running.md). Additionally, when a
substituted method's `arg_info` was
duplicated, the sibling block that is *not* dismantled by the final `destroy_op_array()`
keeps its name/type references until request end (bounded: one block per substituted
method).

Nothing in the copy ever points into z-engine-owned trampolines and no persistent
(malloc) memory is involved: specialization is request-scoped. In long-running workers,
specialize once at boot like any other class-surgery API; the registered class lives
until request (worker) end.

### Opcache / `ZEND_ACC_IMMUTABLE` sources

A template whose class entry lives in opcache shared memory is copied out fully:

- storage flags are cleared on the copy, map-ptr slots (`static_members_table`,
  `mutable_data`, per-function `run_time_cache`/`static_variables_ptr`) are reset to
  plain NULL slots — never written in SHM form;
- immutable method bodies keep their NULL op_array `refcount` (the SHM body is never
  freed) while the copies clear `ZEND_ACC_IMMUTABLE` per function;
- interned/permanent strings and immutable tables are shared without refcounting, which
  the engine's release paths skip symmetrically;
- shared (non-arena) type lists are duplicated into request memory, so
  `zend_type_release()` on the copy never touches SHM.

The copy reflects the template's *pristine* state: per-request `mutable_data`
(evaluated constants, mutated defaults) of the immutable original is not carried over.
This branch is covered by `ClassSpecializerShmTest`: a child PHP process preloads the
template fixture (`opcache.preload` marks preloaded classes `ZEND_ACC_IMMUTABLE` even
under CLI), verifies the flag, specializes the shared-memory template and asserts the
copy works while the SHM original reads back unchanged. The test skips only when the
opcache extension is unavailable; a preload setup that fails to produce an immutable
template fails the test instead of passing silently.

## Support matrix

| Source | Supported | Failure |
|--------|-----------|---------|
| Plain userland class (linked), incl. abstract, readonly, with constructor, static members, constants, attributes, interfaces, traits-in-use | yes | — |
| Class implementing `IteratorAggregate`/`Iterator`/`ArrayAccess`/`Countable` (internal interfaces) | yes | — |
| Opcache-immutable userland class (full copy-out of the SHM entry, see above) | yes | — |
| Internal class | no | `ClassSpecializationException` |
| Class with an internal ancestor | no | `ClassSpecializationException` |
| Interface / trait / enum | no | `ClassSpecializationException` |
| Not-yet-linked class | no | `ClassSpecializationException` |
| Class with property hooks (`num_hooked_props > 0`) | no | `ClassSpecializationException` |
| Target name already registered | no | `ClassSpecializationException` |
| Substituting a placeholder declared by an ancestor (shared declaration) | no | `ClassSpecializationException` |
| Substituting a placeholder inside a union/intersection type list | no (copying such types *without* substitution works) | `ClassSpecializationException` |

All rejections happen **before** any engine state is modified: a failed call never
leaves a half-built class or dangling references behind.

## Known limitations

- `self::class`/`__CLASS__` literals inside copied bodies still name the template
  (compile-time constant folding; the opcodes are shared by design).
- Private property names stay mangled with the template name (cosmetic).
- Union/intersection placeholder substitution is a stretch goal; simple (single-name)
  placeholder types only.
- Property hooks, enums and internal classes are out of scope for this iteration.
- `getStaticPropertyValue`/reflection export on the copy report the substituted types;
  code compiled *before* the specialization that references the new class name by
  literal (e.g. `new \App\Specialized\X()`) works because class lookup is runtime, but
  the name must not collide with an autoloadable class.
