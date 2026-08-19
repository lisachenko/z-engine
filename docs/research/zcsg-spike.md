# ZCSG publication feasibility spike (#121)

**Question.** Can z-engine publish a patched cache-binary image *directly* into a
live opcache shared-memory segment (ZCSG) — installing a `zend_persistent_script`
into `ZCSG(hash)` so every worker serves it immediately — without going through a
file-cache round trip?

**Answer up front: NO-GO for direct ZCSG writes; the already-shipped
file-cache→SHM reload path (PR #251/#253) is the sound solution and direct writes
add almost nothing over it while carrying structural, unmitigable risk.** The
control-plane entry points opcache would need (`zend_shared_alloc`,
`zend_accel_hash_update`, `zend_shared_alloc_lock`, `zend_accel_shared_protect`,
`zend_file_cache_script_load`) are **not exported** from `opcache.so` and are
therefore unreachable through FFI on Linux. The data plane (the ZCSG pointer, the
hash, the segment) *is* reachable and was walked live in a prototype, but reading
it is not the hard part — writing it correctly across processes is, and that is
exactly what the missing symbols provide.

This is a research spike. All prototype code lives under `tools/research/` and is
never wired into `src/`.

---

## 1. Symbol inventory (evidence)

FFI on ELF binds engine symbols out of the **running process image via
`RTLD_DEFAULT`** — `Core::engineLibrary()` returns `null` on `/`-separator hosts,
so `FFI::cdef($defs, null)` resolves through the process-global dynamic symbol
table. A symbol is bindable **iff** it appears in some loaded object's dynamic
symbol table with **default visibility**. PHP builds every extension with hidden
visibility and exports only symbols explicitly marked `ZEND_API` / `ZEND_EXT_API`.

### `opcache.so` exports only 6 defined dynamic symbols — on every build

`nm -D` over three independent builds (host release **8.4.19**, container
`no-debug-non-zts` and `debug-non-zts` **8.4.24**) is identical:

| Exported dynamic symbol | Kind | Relevance |
|---|---|---|
| `extension_version_info` | data | extension boilerplate |
| `zend_extension_entry` | data | extension boilerplate |
| `zend_jit_blacklist_function` | text | JIT hook |
| `zend_jit_status` | text | JIT hook |
| **`smm_shared_globals`** | data (`B`) | **`ZEND_EXT_API` — the SHM allocator globals (ZSMMG). Load-bearing, see §1.2.** |
| `program_invocation_short_name@GLIBC` | import | libc |

Every build is also **stripped** (`nm` on `.symtab` returns 0 rows), so the hidden
internals are not even present as local symbols — there is nothing for a
non-standard resolver to reach either.

### Needed write-path symbols: all ABSENT

| Symbol | php-src marking | In `opcache.so` dynsym? | FFI bind result |
|---|---|---|---|
| `zend_file_cache_script_load` | plain (hidden) | no | **FAIL** |
| `zend_accel_hash_update` | plain (hidden) | no | **FAIL** |
| `zend_shared_alloc` | plain (hidden) | no | **FAIL** |
| `zend_shared_alloc_lock` / `_unlock` | plain (hidden) | no | **FAIL** |
| `zend_accel_shared_protect` (`SHM_(UN)PROTECT`) | plain (hidden) | no | **FAIL** |
| `accel_shared_globals` (the ZCSG pointer) | plain `extern` (hidden) | no | **FAIL** |
| `lock_file` (the fcntl lock fd) | plain `extern` (hidden) | no | **FAIL** |
| `smm_shared_globals` | `ZEND_EXT_API` | **yes** | **BOUND** |
| `zend_system_id` (core) | `ZEND_API` | yes | BOUND |
| `zend_map_ptr_extend`, `zend_new_interned_string` (core) | `ZEND_API` | yes (in `php` binary) | BOUND |

Empirically proven in-process, opcache active
(`tools/research/zcsg-spike-01-symbol-binding.php`):

```
smm_shared_globals                 BOUND ok
accel_shared_globals               FAIL: Failed resolving C variable 'accel_shared_globals'
zend_shared_alloc                  FAIL: Failed resolving C function 'zend_shared_alloc'
zend_shared_alloc_lock             FAIL: Failed resolving C function 'zend_shared_alloc_lock'
zend_accel_shared_protect          FAIL: Failed resolving C function 'zend_accel_shared_protect'
zend_file_cache_script_load        FAIL: Failed resolving C function 'zend_file_cache_script_load'
zend_accel_hash_update             FAIL: Failed resolving C function 'zend_accel_hash_update'
zend_system_id                     BOUND ok
```

There is no ini flag, no build variant, and no z-engine symbol-manifest change
that can export these: the visibility is compiled into the standard opcache
binary that ships with PHP. Only a locally-recompiled opcache with patched
visibility attributes would expose them — out of scope for a library that must
run against stock PHP.

### 1.2 The ZCSG data plane *is* reachable — via the one exported pointer

`smm_shared_globals` is exported, and `zend_accel_init_shm()` stores the ZCSG base
into it: `ZSMMG(app_shared_globals) = accel_shared_globals;`. So the otherwise
hidden `accel_shared_globals` pointer is recoverable as
`smm_shared_globals->app_shared_globals`, and the segment geometry (base, size,
bump-pointer `pos`, `end`) is recoverable from
`smm_shared_globals->shared_segments[0]`.

Proven live (`tools/research/zcsg-spike-02-reach-zcsg.php`), opcache 64 MB SHM:

```
shared_segments_count = 1
app_shared_globals (== ZCSG)  = NON-NULL -> ZCSG base recoverable
segment[0]: p=0x5598cf000050 size=134217728 pos=9274568 end=67108864
```

And the hash was walked end-to-end (`tools/research/zcsg-spike-03-walk-hash.php`),
reading the interned `zend_string` keys of the resident scripts — a full,
userland, read-side view of `ZCSG(hash)` reconstructed purely from struct layouts
z-engine's generator already slices:

```
ZCSG(hash): num_entries=2 max=16229 direct=2
  [ 0] indirect=0 data=ptr key=/spike/zcsg-spike-02-reach-zcsg.php
  [ 1] indirect=0 data=ptr key=/spike/zcsg-spike-01-symbol-binding.php
```

**Conclusion for Q1.** Read access to ZCSG is fully available. Write access to
ZCSG through opcache's own primitives is not: the allocator, the lock, the memory
protector, and the hash mutator are all hidden. The gap is not "reach the data" —
it is "mutate it the way opcache mutates it."

---

## 2. The minimal correct write sequence, and where it is blocked

Reconstructed from `zend_file_cache_script_load()` (`zend_file_cache.c:1828`) and
`cache_script_in_shared_memory()` / `persistent_compile_file()`
(`ZendAccelerator.c:1564`, `:2143`). Installing a `zend_persistent_script` into
live SHM requires, in order:

| # | Step | Opcache primitive | Bindable via FFI? |
|---|---|---|---|
| 1 | Take the exclusive cross-process write lock | `zend_shared_alloc_lock()` → `fcntl(lock_file, F_SETLKW, F_WRLCK)` | **NO** — function hidden **and** `lock_file` fd hidden |
| 2 | Open the write window (drop `PROT_READ`-only) | `SHM_UNPROTECT()` → `zend_accel_shared_protect(false)` → `mprotect` | **NO** (function hidden; range *is* known from ZSMMG) |
| 3 | Bump-allocate the payload inside the segment | `zend_shared_alloc_aligned()` / `zend_shared_alloc()` | **NO** (function hidden; `pos`/`end` *are* known) |
| 4 | `memcpy` the payload to its final SHM address | `memcpy` | yes (but see step 5) |
| 5 | Relocate every interior pointer to the SHM base and intern strings into `ZCSG(interned_strings)` | `zend_file_cache_unserialize()` + opcache's `accel_new_interned_string` | **NO** — the interned-string install path is opcache-internal and locks the interned table |
| 6 | Extend the map_ptr table for the new op_arrays' run-time cache slots | `zend_map_ptr_extend(ZCSG(map_ptr_last))`; write back `ZCSG(map_ptr_last)` | partial — `zend_map_ptr_extend` is core/exported, but the bookkeeping is opcache's |
| 7 | Publish into the hash | `zend_accel_hash_update(&ZCSG(hash), filename, 0, script)` | **NO** (hidden; reimplementable against the layout) |
| 8 | Close the write window | `SHM_PROTECT()` → `zend_accel_shared_protect(true)` | **NO** |
| 9 | Release the lock | `zend_shared_alloc_unlock()` | **NO** |

Steps 1, 2, 3, 5, 7, 8, 9 depend on hidden functions. Steps 3, 7 and the range
for 2/8 are *reimplementable in userland* because the state they touch is
reachable through ZSMMG/ZCSG. **Steps 1 and 5 are the true blockers:**

- **Step 1 (locking) is structurally unreachable.** The lock is an `fcntl`
  record lock on `lock_file`, an anonymous `memfd`/`O_TMPFILE` fd created by the
  master before fork and inherited by every worker. Its *fd number* is the only
  handle, and it lives in a hidden global. Userland cannot obtain that fd, and a
  *different* lock primitive would not be mutually exclusive with the fcntl lock
  every other worker still uses. Writing to SHM without holding opcache's own lock
  means racing the compile path of every sibling worker with no interlock.
- **Step 5 (relocate + intern) reimplements opcache's serializer against the live
  interned-string table** under that same missing lock. z-engine already has a
  faithful port of the *file-cache* unserialize (`PayloadRelocator`), but pointing
  it at the shared interned table — which other workers mutate — reintroduces the
  locking problem at the string level.

So even the "reimplement the allocator + hash in userland" path bottoms out on a
lock we cannot acquire and an interned-string table we cannot safely mutate.

---

## 3. The file-cache→SHM reload alternative (already shipped, already sufficient)

Opcache's **own** `zend_file_cache_script_load()` performs the entire step 1–9
sequence internally, correctly, under the real lock — *when triggered by a normal
compile miss on a file-cache-enabled build*. z-engine already drives this path:

- `BinaryCacheFile::save()` writes a valid patched `.bin` (recomputing checksum,
  rebuilding the interned-string section, handling size-changing edits).
- `BinaryCacheFile::refresh()` = `save()` + `opcache_invalidate($src, true)`.
- On the **next compile of that script**, opcache faults, finds no valid SHM
  entry, calls `zend_file_cache_script_load()`, and installs the patched image
  into shared memory itself.

This is **proven working in the repo test suite** (PR #251,
`tests/OpCache/SharedMemoryRefreshTest.php`, `scripts/shm-refresh-worker.php`),
with opcache SHM active (not `file_cache_only`):

> `testFreshWorkerExecutesPatchedBodyFromSharedMemoryAfterRefresh` — a fresh
> worker (empty SHM, like a newly-spawned FPM child) executes the **patched** body
> and ends up **SHM-resident** (`shm=1`) afterwards — i.e. it went through
> opcache's own file-cache→SHM install path, checksum-verified.

Because a real FPM pool **shares one SHM segment across all workers**, a single
request that faults the invalidated script republishes the patched image into the
shared segment, and *every* worker serves it from that point — no per-worker
recompile, no direct write.

### What would direct ZCSG writes actually add?

| Property | file-cache reload (shipped) | direct ZCSG write (this spike) |
|---|---|---|
| Avoids a recompile | **Yes** — unserializes from `.bin`, no recompile | Yes — but it *also* must relocate/unserialize to the SHM address; same work |
| Cross-worker propagation | Yes — one fault republishes into shared SHM for all workers | Yes, marginally sooner |
| Update without any request touching the script | No — needs one compile miss (a warm-up request) | **Yes** — the only genuine delta (push vs. pull) |
| Avoids disk round-trip | No — writes then re-reads the `.bin` | Yes — marginal I/O saved |
| Atomic in-place replace of a hot entry | Same repoint-only semantics (old body kept alive for in-flight callers) | Same |
| Correctness / safety | Opcache's own locked path — sound | Requires a lock we cannot hold — unsound |

The only capability direct writes uniquely provide is **push-update without a
warm-up request**. That is a minor latency/orchestration nicety, purchased at the
price of racing every worker without opcache's lock. It does not justify the risk.

---

## 4. Safety analysis

For the write actions that *are* mechanically expressible from userland (bump the
segment `pos`, poke `ZCSG(hash)` entries, `mprotect` the range):

| Hazard | Severity | Mitigable? |
|---|---|---|
| **Cross-process lock absent.** We cannot acquire the fcntl lock on the hidden `lock_file` fd. Any write races sibling workers' compile path, the restart machinery, and interned-string appends. | Critical | **No — structural.** The fd is unreachable; a substitute lock does not interlock with the fcntl lock others hold. |
| **`opcache.protect_memory=1` faults on write.** With protection on, the segment is `mprotect`ed read-only outside opcache's own `SHM_UNPROTECT` window. A userland write **SIGSEGVs the process**. Proven: `tools/research/zcsg-spike-04-protect-write.php` — a benign free-region write **succeeds** under `protect_memory=0` (exit 0) and **crashes** under `protect_memory=1` (exit 139). | Critical | Partially — the range is known and `mprotect` is callable, but toggling it outside opcache's discipline defeats the very corruption-detection the flag exists for, and still races other workers. |
| **Interned-string table interactions.** Relocating a payload means interning its strings into `ZCSG(interned_strings)`, a shared table opcache mutates under lock. Duplicate/racing inserts corrupt the table for all workers. | Critical | **No — structural** without the lock and `accel_new_interned_string`. |
| **Restart-in-progress / OOM states.** `restart_pending`, `restart_in_progress`, `memory_exhausted` gate the real install path. Userland writes ignoring them collide with an in-flight SHM reset. We can *read* these flags (they are in ZCSG), but cannot participate in the restart protocol. | High | Read-mitigable (bail if set); still racy without the lock. |
| **Crash mid-write corrupts siblings.** SHM is shared; a partial write or a segfault between "bump `pos`" and "publish hash entry" leaves every worker looking at a half-written segment. There is no per-write journaling. | Critical | **No — structural.** Shared mutable memory with no transaction boundary we control. |
| **map_ptr / run-time cache slot desync.** New op_arrays need map_ptr slots (`ZCSG(map_ptr_last)`); getting this wrong makes the VM read the wrong inline-cache slot at execution. | High | Difficult — reimplementing opcache's slot accounting by hand. |

Mitigable hazards are all read-side guards (check flags, know the range).
The critical ones — **no lock, no safe interning, no crash atomicity** — are
structural consequences of doing under FFI what opcache does under a lock we
cannot hold. They cannot be engineered away from userland against stock opcache.

---

## 5. Prototype results

Timeboxed, debug container (`z-engine-php:debug84`, assertions on). Scripts under
`tools/research/`, marked spike.

| Prototype | Result |
|---|---|
| **01 symbol-binding** | Confirmed: `smm_shared_globals` + core symbols bind; **all** ZCSG write-path symbols fail to resolve via FFI. |
| **02 reach-ZCSG** | Confirmed: ZCSG base recovered through `smm_shared_globals->app_shared_globals`; segment geometry (`p`, `size`, `pos`, `end`) read live. |
| **03 walk-hash** | **Success:** enumerated `ZCSG(hash)` live from userland — `num_entries`, and every resident script's filename read out of the interned `zend_string` keys. Full read-side view of the SHM script table with zero opcache symbols beyond the one exported globals pointer. |
| **04 protect-write** | **Success (as a hazard demo):** a SHM write **succeeds** under `protect_memory=0` and **SIGSEGVs (exit 139)** under `protect_memory=1`, proving the mprotect hazard and that `SHM_UNPROTECT` (hidden) is mandatory for any write under protection. |

**How far it got, and what stopped it.** The read/data plane is completely open —
prototype 03 is a working, useful capability (a userland `opcache_get_status`-style
SHM inspector). The write plane stops at step 1 of §2: there is no bindable lock
and no bindable allocator/protector/interner. A `zend_accel_hash_update`-style
in-place repoint of an existing entry's `data` is *mechanically* writable (03 +
04 show the bytes are reachable and, under `protect_memory=0`, writable), but doing
so requires a replacement `zend_persistent_script` already correctly allocated and
relocated in SHM — which needs steps 1–5, which are blocked. Attempting the poke
without the lock would be demonstrating the race, not proving feasibility, so it
was not pursued against a shared segment.

---

## 6. Recommendation

### NO-GO on direct ZCSG publication. REDESIGN is unnecessary — the shipped path is the design.

**Rationale.**

1. **Unreachable by construction.** The opcache write-path entry points are hidden
   in the standard `opcache.so` on every build tested (§1). No z-engine change
   exposes them; only a locally-recompiled opcache would.
2. **Unsafe even if reachable.** The lock is on an unreachable fd; interning and
   crash-atomicity are structural, non-mitigable hazards (§4). A userland write
   path would race every worker.
3. **Redundant.** Opcache's own file-cache→SHM reload already installs patched
   images into shared memory, correctly and under the real lock, and z-engine
   already drives it (`refresh()`), with passing tests (§3). Direct writes add
   only "push-update without a warm-up request" — a minor nicety, not worth the
   risk.

**Recommended posture.**

- **Keep** `BinaryCacheFile::save()/refresh()` as the SHM publication mechanism.
  Document it as *the* supported way to get a patched script into shared memory.
- **Optionally ship the read-side inspector** (prototype 03) as a small,
  genuinely safe feature: a `ZCSG`/SHM introspection API (resident scripts, hash
  fill, wasted/free memory) built purely on the exported `smm_shared_globals`.
  Low effort, zero risk, real value, and it needs no hidden symbols.
- If push-without-request update ever becomes a hard requirement, the honest
  route is **not** userland ZCSG writes but a **companion opcache patch / a tiny
  Zend extension** that re-exports the needed functions (or exposes a single
  `opcache_publish_script(bin)` C entry point that runs opcache's own locked
  install). That is a php-src/extension effort, not an FFI one.

### Effort estimate

| Option | Effort | Risk |
|---|---|---|
| Do nothing (rely on `refresh()`) | 0 | none |
| Ship read-side SHM inspector (prototype 03 → `src/OpCache`) | **~1–2 days** (struct wiring via existing generator, tests, docs) | very low |
| Direct ZCSG writes against stock opcache | **infeasible** | — |
| Push-update via companion opcache/extension patch | **~2–4 weeks** + ships a native artifact users must install | high (defeats z-engine's "stock PHP" premise) |

---

### Appendix — reproduction

```bash
# php-src reference: PHP-8.4.19 at /tmp/php84src
# symbol export check (identical on host 8.4.19 and container 8.4.24 builds):
nm -D /usr/lib/php/20240924/opcache.so | grep -vwE 'U|w'

SCRIPTS=tools/research
docker run --rm -v "$PWD/$SCRIPTS":/spike z-engine-php:debug84 \
  php -d opcache.enable_cli=1 -d ffi.enable=1 -d opcache.memory_consumption=64 \
  /spike/zcsg-spike-01-symbol-binding.php          # symbol reachability
# 02/03 need a populated hash: opcache_compile_file(...) two files then require the walker
docker run --rm -v "$PWD/$SCRIPTS":/spike z-engine-php:debug84 bash -c \
  'php -d opcache.enable_cli=1 -d ffi.enable=1 -d opcache.memory_consumption=64 -r "
   opcache_compile_file(\"/spike/zcsg-spike-01-symbol-binding.php\");
   opcache_compile_file(\"/spike/zcsg-spike-02-reach-zcsg.php\");
   require \"/spike/zcsg-spike-03-walk-hash.php\";"'
# 04: run once with -d opcache.protect_memory=0 (writes) and once with =1 (SIGSEGV)
```
