# Observer hook (`zend_observer` fcall bridge)

`ObserverHook` bridges the engine's `zend_observer` fcall *begin*/*end* handlers to userland
callbacks, following the same install/uninstall/reinstall lifecycle and `Core` hook registry
semantics as the other hooks (see [long-running.md](long-running.md#hook-lifecycle)). It targets a
single `zend_function` and attaches a begin and an end handler through the engine's per-function
runtime API (`zend_observer_add_begin_handler` / `zend_observer_add_end_handler`).

```php
// From the opcache.preload script only (see below):
$function = (new ReflectionFunction('some_function'))->getRawFunctionPointer();
Core::observeFunction(
    $function,
    fn(ExecutionData $frame) => /* begin */,
    fn(ExecutionData $frame, ?ReflectionValue $return) => /* end; omit for a begin-only hook */,
);
```

The callbacks receive an [`ExecutionData`](../src/System/ExecutionData.php) frame; the end callback
also receives the return value (or `null` for abrupt/generator returns). Exceptions thrown by a
callback are contained and downgraded to an `E_USER_WARNING` — a throw must never cross the FFI
boundary into the engine ([#50](https://github.com/lisachenko/z-engine/issues/50)).

The full firing path — begin/end with return values, clean uninstall, nested-call ordering,
throwing functions under begin-only hooks, containment, and internal functions — is verified
end-to-end by [`ObserverHookFiringTest`](../tests/System/Hook/ObserverHookFiringTest.php) against
the reference provider described below.

## The hard constraints

The `zend_observer` fcall machinery is designed for **C extensions that register during MINIT**, and
that assumption leaks into every part of its API. The constraints below are not policy choices;
they are what the engine does, verified against php-src 8.4.19.

### 1. Registration timing — preload only, and even that is too late to *enable* observers

`zend_observer_fcall_register()` is only honoured before startup finishes. The engine freezes the
observer configuration in `zend_observer_post_startup()`, which runs at the tail of
`php_module_startup()` (`main.c`), **before** the `opcache.preload` script executes — preloading is
driven from `zend_post_startup()` → `accel_post_startup()` → `accel_finish_startup()`, which the
engine calls *after* `zend_observer_post_startup()`.

Consequently, by the time `Core::preload()` runs, `zend_observer_fcall_op_array_extension` is already
`-1` (observers disabled) unless a startup-time provider reserved the slot. This is directly
observable:

```
$ php -d ffi.enable=1 -d opcache.enable_cli=1 \
      -d opcache.preload=probe.php -r ''
# probe.php, during preload:
op_array_extension = -1   # ZEND_OBSERVER_ENABLED is false
```

`ObserverHook` therefore requires the preload boot path (`Core::isPreloaded()`), and refuses with
`ObserverException::notPreloaded()` under a plain `Core::init()` request. But the preload requirement
is necessary, not sufficient: the machinery must additionally have been *enabled* by a startup
provider (next point).

### 2. Already-compiled op_arrays and the retroactive-stamping verdict

Observer support is stamped into each function at **compile time**, in `pass_two`
(`zend_opcode.c`): `op_array->cache_size = zend_observer_fcall_op_array_extension_handles *
sizeof(void*)` is set in `init_op_array`, and `op_array->T += ZEND_OBSERVER_ENABLED` reserves the
per-frame temporary that stores the observed-frame linked list. Internal functions get one shared
`run_time_cache` block sized once at startup by `zend_init_internal_run_time_cache()`.

This makes **retroactive stamping unsafe** and, in fact, makes userland self-enablement impossible:

- A function compiled while observers were **disabled** has a `cache_size` and `T` that do **not**
  include an observer slot. Writing observer handler data into its `run_time_cache`, or enabling
  observers so the VM reads a `prev_observed_frame` temporary the frame never reserved, is an
  out-of-bounds access — heap and stack corruption.
- Internal functions share a single startup-sized cache block; growing the extension handle count
  afterwards cannot grow that block.

Enabling observers late (setting `zend_observer_fcall_op_array_extension` by hand from the preload
script) was tested and **segfaults**: the engine's observer install path invokes the registered
`zend_observer_fcall_init` — a callback that returns a struct by value — from inside call-frame
setup, and driving that through an FFI trampoline corrupts execution state
(`SIGSEGV` on the first observed call). z-engine therefore never self-enables observers.

**Observed/unobserved boundary.** Only functions compiled **after** the engine's observer machinery
was enabled — by a startup-time provider — can be observed. On a stock z-engine build with no such
provider, observers are disabled and `ObserverHook::install()` refuses with
`ObserverException::observersDisabled()` rather than corrupting memory. This boundary is pinned by a
test: [`ObserverHookPreloadTest`](../tests/System/Hook/ObserverHookPreloadTest.php) boots through the
preload path and asserts `PRELOADED=1`, `OBSERVER_ENABLED=0`, `OBSERVE=rejected`.

### 3. Callback-exception containment — and why throwing functions need begin-only hooks

`handleBegin()` / `handleEnd()` wrap the userland callback in a catch-all that downgrades any
`Throwable` to an `E_USER_WARNING` (a user error handler converting that warning back into an
exception is swallowed too), exactly like the other FFI-callback hooks
([#50](https://github.com/lisachenko/z-engine/issues/50)). This is verified end-to-end: a begin
callback that throws produces the warning and the function call — including its end handler —
continues unharmed.

There is a second, harder containment problem that **cannot** be solved from userland: the engine
invokes **end handlers while unwinding a throwing frame**, i.e. with `EG(exception)` set — and
ext/ffi refuses to run any callback in that state. `zend_call_function()` skips the PHP closure
outright when `EG(exception)` is set ("we would result in an unstable executor otherwise"), and the
FFI trampoline then aborts the whole process with the fatal error *"Throwing from FFI callbacks is
not allowed"* — all in C, before any z-engine code gets control. Therefore:

> **A function that can throw must be observed with a begin-only hook** (`$end = null` /
> omitted). Begin handlers run at frame entry, where no exception can be in flight, and the
> exception then propagates through the observed function exactly as without the hook.

Both sides are pinned by [`ObserverHookFiringTest`](../tests/System/Hook/ObserverHookFiringTest.php):
the begin-only hook observes the throwing function and the exception is caught normally, while a
deliberately attached end handler reproduces the documented ext/ffi abort in a sacrificial child
process. If a future PHP release lifts the ext/ffi restriction, that pin fails and the begin-only
rule can be revisited.

### 4. Internal vs userland functions

For a **user function**, `install()` warms the lazily-allocated `run_time_cache`
(`zend_init_func_run_time_cache`) so the observer slot exists before the first call, then attaches
via the op_array observer extension slot. For an **internal function**, observation uses the separate
`zend_observer_fcall_internal_function_extension` slot and the startup-sized shared cache block;
z-engine refuses whenever that slot is `-1`, because the block is frozen at startup and cannot be
grown from userland. Both kinds fire verifiably
([`ObserverHookFiringTest`](../tests/System/Hook/ObserverHookFiringTest.php) asserts begin/end for a
preload-compiled user function and for `strrev`), and the guard paths are covered by
[`ObserverHookTest`](../tests/System/Hook/ObserverHookTest.php) /
[`ObserverHookPreloadTest`](../tests/System/Hook/ObserverHookPreloadTest.php).

Note on `zend_execute_internal`-based paths: observer begin/end for internal functions is driven by
the *calling* op_array's `DO_ICALL`/`DO_FCALL` observer handler variants, not by replacing
`zend_execute_internal`, so the two interception mechanisms are independent and can coexist.

### 5. JIT

Out of scope — z-engine already requires `opcache.jit=off`.

## The reference startup-time provider

z-engine ships the minimal provider as a test fixture:
[`tests/fixtures/observer-enabler`](../tests/fixtures/observer-enabler/observer_enabler.c) — a
~50-line extension whose MINIT registers an fcall observer returning `{NULL, NULL}` handlers for
every function. Registering it is enough to make the engine reserve the observer extension slots
(`ZEND_OBSERVER_ENABLED` becomes true) while observing nothing itself; the per-function runtime API
then becomes fully usable by `ObserverHook`. Consumers who want observer support in production can
replicate it verbatim (build with `phpize && ./configure && make`, load with `extension=...`), or
load any existing observer-registering extension instead.
[`ObserverHookFiringTest`](../tests/System/Hook/ObserverHookFiringTest.php) builds this fixture on
demand with the local toolchain and skips cleanly when `phpize`/`cc` are unavailable.

## Slot priming and provider interaction

The engine initialises a function's observer handler slots lazily, on the function's first call in
a request, by walking every registered provider's init callback (`zend_observer_fcall_install`); the
runtime add-handler API is only legal on initialised slots. `ObserverHook::install()` therefore
primes a never-called function's slots itself, writing the engine's own `NOT_OBSERVED` sentinel —
exactly what the install routine would write for a `{NULL, NULL}` provider — before attaching.

Two consequences, both accepted and documented:

- Priming marks the function "installed", so **other providers' lazy init callbacks are not
  consulted for that function** for the rest of the request. With the reference enabler (which
  observes nothing) this changes nothing; alongside a real observing extension it means a
  z-engine-hooked function is not seen by that extension's per-function init in the same request.
- The engine reserves exactly `2 × count` handler slots per function (count = registered
  providers, derived via `Core::observerFcallObserverCount()`), and z-engine cannot prove a second
  begin/end pair would fit — so **only one `ObserverHook` per function** is allowed;
  a second `install()` throws `ObserverException::alreadyObserved()`.

## Lifecycle and long-running processes

`ObserverHook` registers in the `Core` hook registry under the synthetic key
`observer-fcall::<function address>`, so `Core::shutdown()` detaches every still-installed hook while
the libffi trampolines are guaranteed alive (`zend_observer_remove_begin_handler` /
`remove_end_handler`), and `Core::reinstallHooks()` re-mints the begin/end trampolines for SAPIs that
cycle FFI callback state between requests. Each installed hook holds up to two live trampolines
(begin, and end when attached); both are owned by ext/ffi and freed at its `RSHUTDOWN`, covered by
the generic "one live libffi trampoline per installed hook" row in the
[immortal allocation table](long-running.md).

## Summary

| Requirement | Behaviour |
|-------------|-----------|
| Non-preload boot (`Core::init()`) | `ObserverException::notPreloaded()` |
| Preload boot, observers disabled (stock build) | `ObserverException::observersDisabled()` |
| Preload boot, observers enabled by a startup provider | begin/end fire for functions compiled after enablement, userland and internal (verified) |
| Second hook on the same function | `ObserverException::alreadyObserved()` |
| Callback throws | contained, `E_USER_WARNING`, execution continues |
| Observed function throws | supported with a begin-only hook; an end handler would be aborted by ext/ffi (pinned) |
| `Core::shutdown()` | handlers detached while trampolines are alive |
