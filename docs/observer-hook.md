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
    fn(ExecutionData $frame, ?ReflectionValue $return) => /* end */,
);
```

The callbacks receive an [`ExecutionData`](../src/System/ExecutionData.php) frame; the end callback
also receives the return value (or `null` for abrupt/generator returns). Exceptions thrown by a
callback are contained and downgraded to an `E_USER_WARNING` — a throw must never cross the FFI
boundary into the engine ([#50](https://github.com/lisachenko/z-engine/issues/50)).

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

### 3. Callback-exception containment

`handleBegin()` / `handleEnd()` wrap the userland callback in a catch-all that downgrades any
`Throwable` to an `E_USER_WARNING`, exactly like the other FFI-callback hooks
([#50](https://github.com/lisachenko/z-engine/issues/50)). The engine calls these handlers at
sensitive points during frame entry/exit; a leaked exception would surface as a fatal error.

### 4. Internal vs userland functions

For a **user function**, `install()` warms the lazily-allocated `run_time_cache`
(`zend_init_func_run_time_cache`) so the observer slot exists before the first call, then attaches
via the op_array observer extension slot. For an **internal function**, observation uses the separate
`zend_observer_fcall_internal_function_extension` slot; z-engine reports it as disabled whenever that
slot is `-1` and refuses, because the shared internal cache block is frozen at startup and cannot be
grown from userland. Both kinds are covered by the tests
([`ObserverHookTest`](../tests/System/Hook/ObserverHookTest.php),
[`ObserverHookPreloadTest`](../tests/System/Hook/ObserverHookPreloadTest.php)).

### 5. JIT

Out of scope — z-engine already requires `opcache.jit=off`.

## Lifecycle and long-running processes

`ObserverHook` registers in the `Core` hook registry under the synthetic key
`observer-fcall::<function address>`, so `Core::shutdown()` detaches every still-installed hook while
the libffi trampolines are guaranteed alive (`zend_observer_remove_begin_handler` /
`remove_end_handler`), and `Core::reinstallHooks()` re-mints the begin/end trampolines for SAPIs that
cycle FFI callback state between requests. Each installed hook holds two live trampolines (begin and
end); both are owned by ext/ffi and freed at its `RSHUTDOWN`, covered by the generic "one live libffi
trampoline per installed hook" row in the [immortal allocation table](long-running.md).

## Summary

| Requirement | Behaviour |
|-------------|-----------|
| Non-preload boot (`Core::init()`) | `ObserverException::notPreloaded()` |
| Preload boot, observers disabled (stock build) | `ObserverException::observersDisabled()` |
| Preload boot, observers enabled by a startup provider | begin/end attached to functions compiled after enablement |
| Callback throws | contained, `E_USER_WARNING` |
| `Core::shutdown()` | handlers detached while trampolines are alive |
