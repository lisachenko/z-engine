# Self-debugging: an Xdebug-class debugger in pure PHP

Can a PHP process debug *itself* — line breakpoints, step over/into/out, stack and
variable inspection, an IDE attached over Xdebug's own protocol — with no C extension,
using only z-engine's FFI access to the running engine? **Yes, with two honest
asterisks**: only code compiled *after* the debugger initializes is steppable, and
first-chance exception breakpoints are partial. This document maps every Xdebug
capability to the z-engine primitive that carries it, records what was verified on a
live PHP 8.4 build (the statement hook, named frame locals, the error and interrupt
hooks) and what is closed off by the engine itself (the observer API, the
exception-throw hook), and sketches the wire protocol story. The debugger itself lives
in a separate package — [ZDebug](https://github.com/lisachenko/zdebug), which implements
this study; z-engine's job, covered here, is the core primitives.

Environment ground rules apply as everywhere in z-engine: exact supported PHP minor,
`ffi.enable=1`, **JIT off** (the JIT rewrites the executor internals these hooks plug
into), a platform with generated definitions (e.g. linux-x64-nts, darwin-arm64-nts), experimental status.

## What Xdebug hooks, and what substitutes for it

Xdebug is a `zend_extension` that attaches at C level to: `zend_execute_ex` (call
interception), extended-info oplines (`EXT_STMT`) plus opcode overrides for statement
stepping, `zend_throw_exception_hook` / `zend_error_cb` for exception and error
breakpoints, and a DBGp socket loop that blocks inside the engine while the IDE shows
a suspended process. Structurally, nothing it does at runtime is unavailable to
z-engine — the differences are *when* a hook can be installed (module startup vs.
after `Core::init()`) and that our callbacks are PHP code re-entering the same VM they
interrupt. Those two differences generate every limitation below.

| Xdebug mechanism | z-engine substitute | Status |
|------------------|--------------------|--------|
| `EXT_STMT` statement handler | `Compiler::setOptions()` + `OpCode::setHandler(OpCode::EXT_STMT, …)` | **verified live** |
| Stack/context inspection | `ExecutionData` chain + `getLocalVariables()` | **verified live** |
| `zend_error_cb` | `Core::setErrorCallbackHandler()` | **verified live** |
| Async break (pause) | `Core::setInterruptHandler()` + `Executor::requestInterrupt()` | **verified live** |
| `zend_throw_exception_hook` | none — ext/ffi aborts (see below) | **closed** |
| Observer API | none — frozen before userland runs ([#106](https://github.com/lisachenko/z-engine/pull/106)) | **closed** |
| `zend_execute_ex` | not exported (roadmap; call-granular only) | future |

## The statement hook — the load-bearing primitive

A debugger needs a callback at every statement boundary. The engine has a compiler
mode for exactly this: `ZEND_COMPILE_EXTENDED_STMT` makes every compiled statement
start with an `EXT_STMT` opline, and user opcode handlers intercept it. Both pieces
have long been part of z-engine; this recipe was verified end-to-end on 8.4:

```php
use ZEngine\Core;
use ZEngine\System\Compiler;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

$compiler = Core::$compiler;
$compiler->setOptions($compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);

$hook = OpCode::setHandler(OpCode::EXT_STMT, function (ExecutionData $scope): int {
    $line   = $scope->getOpline()->getLine();
    $locals = $scope->getLocalVariables();          // named, live, per-frame
    // ... breakpoint table lookup / suspend loop goes here ...
    return Core::ZEND_USER_OPCODE_DISPATCH;
});
```

Observed output for a probe closure (`$double = $n * 2; $triple = $n * 3; return …`)
called with `7` — one hit per statement, locals evolving exactly as a step debugger
would render them:

```
stmt line 2: {"n":7}
stmt line 3: {"n":7,"double":14}
stmt line 4: {"n":7,"double":14,"triple":21}
```

Three properties of user opcode handlers shape the whole design
(`src/System/Hook/OpCodeHook.php`, `tests/System/Hook/OpCodeHookTest.php`):

1. **Compile-order coverage.** The user-handler dispatch is baked into
   `opline->handler` at `pass_two()` time, so both the compiler option and the
   handler only affect op_arrays compiled *after* they are set. A self-debugger must
   initialize before `require`-ing the code it wants to step (preload/bootstrap
   ordering), and scripts served from opcache in their pre-instrumentation form stay
   invisible. There is no retro-instrumentation: `OpLine::setCode()` cannot help
   because the baked handler pointer, not the opcode byte, decides dispatch — the
   offline alternative for already-cached code is patching the opcache file cache
   ([docs/opcache-binary.md](opcache-binary.md)).
2. **Cost.** Every intercepted opcode crosses a libffi trampoline
   ([docs/memory-model.md](memory-model.md), Path A) and `OpCodeHook::handle()`
   additionally resolves the executing frame's class scope per hit — read off the
   `execute_data` the callback already receives through the reflection wrappers
   (it used to be a `debug_backtrace(…, 10)` filter). With `COMPILE_EXTENDED_STMT` on, that tax applies
   to *every statement of every instrumented file*. Mitigations, in leverage order: gate
   instrumentation per file (toggle the compiler option from a `zend_ast_process` hook
   based on `Compiler::getFileName()`), memoize an include/exclude decision per op_array
   address instead of the per-hit scope filter, and strip `EXT_STMT` oplines back to
   `NOP` while no breakpoint targets their file. ZDebug ships the middle one (its
   `OpArrayGate` decides each op_array once, keyed by entry address); the compiler-option
   gating and the `NOP` strip-back remain design sketches.
3. **Reentrancy.** Opcodes executed inside `ZEngine\*` classes bypass user handlers
   by design, but that exclusion is class-prefix-based: top-level code, plain
   functions and closures of the debugger itself are *not* excluded. A debugger must
   carry its own latch — a `static bool $inDebugger` checked first thing in the
   handler is sufficient and cheap.

An alternative statement source worth benchmarking in the debugger package:
`declare(ticks=1)` + `register_tick_function()` has **no FFI in the hot path** (the
tick callback is a plain PHP call; the frame is recovered via
`Core::$executor->getExecutionState()`), at the price of coarser hit placement and
per-file `declare` injection (a `zend_ast_process` rewrite). Same compile-order
constraint.

**Verdict: feasible, verified.** Statement-granular interception with full frame
access works today; coverage is bounded by compile order.

## Breakpoints and stepping

Line breakpoints are a table lookup inside the statement hook: resolve the frame's
file once per op_array (key it by `ReflectionFunction::getAddress()`), then
`isset($breakpoints[$file][$line])` per hit. `EXT_STMT` fires at statement *starts*,
so a breakpoint on a non-statement line snaps to the next statement — DBGp explicitly
supports reporting the resolved line back to the IDE. Conditional breakpoints
evaluate their expression against the frame's named locals (below); per-variable
write-back through `ReflectionValue::setNativeValue()` on the CV slot is available
when a condition (or the IDE user) wants to mutate state.

**Suspension is just blocking.** The opcode handler is ordinary PHP: when it decides
to suspend, it enters a loop reading debugger commands from a socket and simply does
not return until the resume command arrives. The VM sits parked inside the handler;
nothing else runs in the process — which is exactly what a suspended single-threaded
debuggee should look like.

Stepping is a resume-mode state machine over statement hits, with the frame depth
computed by walking `ExecutionData::getPrevious()`:

| Resume mode | Break condition on next EXT_STMT |
|-------------|----------------------------------|
| `step_into` | always |
| `step_over` | depth ≤ D (D = depth at resume) |
| `step_out`  | depth < D |

Two correctness notes. Raw `execute_data` pointer equality is **not** a valid frame
identity across resumes — the VM stack reuses frame memory, so compare depth (plus
the op_array address as tiebreaker). And `≤`/`<` rather than `=` handles frames that
disappear without a same-depth statement (exceptions, `finally`). Internal functions
emit no `EXT_STMT`, so stepping *into* C code is impossible — matching Xdebug — while
a userland callback passed to `array_map()` is instrumented and steppable as usual.
Generator/Fiber suspension changes the `prev_execute_data` topology mid-flight;
step-over across a `yield` boundary needs a dedicated experiment in the debugger
package.

**Verdict: feasible.** Same fidelity as Xdebug within instrumented code.

## Stack and variable inspection

The backtrace is the `ExecutionData::getPrevious()` chain; per frame:
`getFunction()` / `getFunctionEntry()`, `getThis()`, `getArguments()`, and
`getOpline()->getLine()` (for parent frames the opline is the call site — which is
the line DBGp wants). Note for hook authors: `getFunction()` resolves through native
reflection and **throws for closure frames**; inside any FFI callback a thrown
exception is a fatal engine error ("Throwing from FFI callbacks is not allowed"), so
prefer `getFunctionEntry()` or guard with try/catch inside handlers.

Locals come from the compiled-variable table, not the symbol table:

- `FunctionLikeTrait::getVariableNames()` reads `op_array->vars` — the CV slot
  names, arguments first, indexed by the same slot numbering
  `ExecutionData::getCallVariableByNumber()` uses;
- `ExecutionData::getLocalVariables()` pairs both into a `name => ReflectionValue`
  map of the live frame (IS_UNDEF slots skipped);
- `ExecutionData::getLocalVariable($name)` also surfaces declared-but-unset slots so
  an IDE can render "uninitialized";
- `ExecutionData::hasLocalVariable($name)` / `getLocalVariableNames()` probe and
  enumerate the CV slots the frame actually has - call these before the by-name
  reader, which throws for a missing slot.

Frame introspection reports the **optimized** op_array: with opcache active, the
optimizer (SCCP, dead-code elimination, CV compaction) removes and renumbers CV
slots, so a variable that exists in the source may have no slot on the live frame at
all. Instrumentation that must observe every declared variable should run with
`opcache.optimization_level=0`.

The values are borrowed views into the live frame — valid while the frame is on the
VM stack, which is precisely the suspended-in-a-hook situation. Object graphs expand
through `ReflectionValue` → `ObjectEntry`/`HashTable` recursion, naturally paged for
DBGp's `max_depth`/`max_children`. Globals come from
`Executor::getGlobalSymbolTable()`.

The historical `getSymbolTable()` segfault is fixed: frame symbol tables exist only
when the engine materialized one (variable variables, `extract()`/`compact()`/
`get_defined_vars()`, eval'd code) and flags it via `ZEND_CALL_HAS_SYMBOL_TABLE`;
the accessor now checks the flag and returns null otherwise, so the CV-slot route
above is both the fast path and the safe one.

**Verdict: feasible, verified** — the locals API shipped with this research.

## Exception and error breakpoints

The honest partial. Three routes, from working to closed:

- **Error breakpoints work.** `Core::setErrorCallbackHandler()` observes every
  engine diagnostic — including ones silenced by `@` or `error_reporting()`, which
  never reach `set_error_handler()` — with file/line/message and `proceed()`
  chaining to the engine default. Fatal severities must always `proceed()` (the
  engine expects its error callback to bail out; swallowing a fatal resumes
  execution in a state the engine considers unreachable).
- **First-chance exception breakpoints: userland throws only.** A user opcode
  handler on `OpCode::THROW` runs *instead of* the VM's THROW handler, i.e. before
  `EG(exception)` exists, so it may inspect the exception operand and suspend
  safely. Throws originating in internal functions or engine-generated errors
  (`TypeError`, …) never execute a userland THROW opcode and stay invisible on this
  route.
- **`zend_throw_exception_hook` is closed to userland.** The engine sets
  `EG(exception)` *before* invoking the hook, and ext/ffi refuses to enter a PHP
  callback while the engine carries a live exception — the trampoline aborts with a
  fatal error and the script's catch blocks never run. This is the same root cause
  that killed observer end-handlers in [#106](https://github.com/lisachenko/z-engine/pull/106).
  The symbol is exported in the generated header for native consumers, but z-engine
  deliberately ships no wrapper; the behavior is pinned by a sacrificial-child test
  (`tests/System/Hook/ThrowExceptionHookAbortTest.php`) so a future ext-ffi change
  surfaces as a red test. The same reasoning predicts a `CATCH` opcode handler
  cannot work either (it executes during unwinding, exception still in flight).
- **Uncaught exceptions** remain observable the userland way
  (`set_exception_handler`), at the Xdebug-fidelity cost that the stack is already
  unwound when the handler runs. `Executor::getCurrentException()` /
  `suppressCurrentException()` cover inspection and break-and-swallow semantics
  where an exception is already parked in `EG(exception)`.

**Verdict: partial** — by engine/FFI design, not by missing plumbing.

## Async break: pausing a running script

An IDE's "pause" button needs the debuggee to stop *without* a breakpoint being hit.
That is exactly what the engine's VM interrupt exists for, and it is now wrapped:

```php
$hook = Core::setInterruptHandler(function (InterruptHook $hook): void {
    $frame = $hook->getExecutionData();   // the interrupted frame
    // enter the same suspend loop the statement hook uses
    if ($hook->hasOriginalHandler()) {
        $hook->proceed();                 // keep pcntl & friends working
    }
});

Core::$executor->requestInterrupt();      // e.g. from a signal handler
```

`requestInterrupt()` raises `EG(vm_interrupt)`; the callback fires at the next VM
interrupt check (loop back-edges, function entries) inside whatever frame is running
— verified live, including chaining to ext/pcntl which claims the same pointer.
Because the request side is a single flag write, any asynchronous trigger a pure-PHP
process has works: a `pcntl_signal` handler, a tick, or the statement hook itself
noticing a "break" command on its (non-blocking) command socket.

**Verdict: feasible, verified.**

## Talking to an IDE: DBGp over DAP

**Recommendation: implement DBGp** — Xdebug's own protocol — first.

- The *engine connects out* to the IDE (`client_host:9003`), so a suspended
  in-process debugger needs no listener, no accept loop, no second thread: connect
  lazily on init or on the first break, then block on `fread` inside the suspend
  loop.
- PhpStorm and VS Code speak DBGp natively; "compatible with Xdebug" reduces to
  "speak DBGp correctly" with zero client-side work.
- DAP (the Debug Adapter Protocol) assumes the IDE *launches* an adapter process, so
  it would force an out-of-process component — exactly what the in-process story
  avoids. A DAP translator on top of a working DBGp core remains a clean later
  addition.

Wire mechanics are undemanding for a PHP implementation: commands arrive as
NUL-terminated ASCII lines (`breakpoint_set -i 5 -t line -f file://… -n 42`),
responses go out as `<length> NUL <xml> NUL`. The minimal command set maps directly
onto the primitives above — `breakpoint_set/remove` (breakpoint table),
`run/step_into/step_over/step_out` (resume-mode machine), `stack_get`
(`getPrevious()` chain), `context_get` (`getLocalVariables()` /
`getGlobalSymbolTable()`), `property_get` (paged value traversal), `eval`
(conditional-breakpoint machinery), `stop/detach` (LIFO hook uninstall per
[docs/long-running.md](long-running.md)). The single-threaded engine maps cleanly
onto DBGp's `starting/break/running/stopping` status model; the async `break`
command is the interrupt hook above.

**Verdict: feasible**; DBGp chosen deliberately.

## Hard limitations

| Constraint | Consequence |
|------------|-------------|
| Compile-order coverage | Only code compiled after debugger init is steppable; opcache-cached scripts escape unless the cache is cold or patched offline |
| Optimizer rewrites CV slots | With opcache active, source variables may have no frame slot (SCCP + DCE + CV compaction); probe with `ExecutionData::hasLocalVariable()` or run instrumented processes with `opcache.optimization_level=0` |
| FFI abort on live exception | No throw-hook/CATCH interception; handler code must never leak an exception (fatal) |
| Observer API frozen pre-userland | No per-call begin/end events; profiling/tracing stays out of scope ([#106](https://github.com/lisachenko/z-engine/pull/106)) |
| JIT must be off | The JIT bypasses the rewritten executor internals |
| Per-statement trampoline cost | Instrumentation must be scoped per file to stay usable; whole-process stepping is for short sessions |
| Self-debugging shares the process | Debugger and debuggee share heap, output buffers, error handlers and limits: the observer perturbs the observed |
| Platform envelope | Supported PHP minors, platforms with generated definitions (linux-x64, darwin-x64/arm64), `ffi.enable=1`, experimental |

## Roadmap

Shipped with this research: `getVariableNames()` + `getLocalVariables()`/
`getLocalVariable()`, the `getSymbolTable()` guard, `Core::setErrorCallbackHandler()`,
`Core::setInterruptHandler()` + `Executor::requestInterrupt()`, the exported
`zend_throw_exception_hook` symbol and its pinning test.

The debugger package now exists: **[ZDebug](https://github.com/lisachenko/zdebug)**
implements this study — the breakpoint table, the suspend loop and the DBGp transport
over these primitives, the memoized per-op_array instrumentation decision, line /
conditional / call / return and first-chance exception breakpoints, stepping, stack and
variable inspection with write-back, `eval`, and return-value debugging. Still open
there: the async `break` command over `Core::setInterruptHandler()`, the
EXT_STMT-vs-ticks benchmark, and the generator/fiber stepping experiment.

Candidate z-engine follow-ups if that work needs them: exporting `zend_execute_ex`
(call-depth events without walking `getPrevious()`), `zend_vm_set_opcode_handler`
(handler-level retro-instrumentation), a `zend_ast_process` hook for per-file
`EXT_STMT` emission, and a leaner statement-hook fast path that replaces the per-hit
backtrace filter with a memoized per-op_array decision.
