# Security Policy

## Supported versions

Security fixes follow the same branch model as every other fix (see
[`AGENTS.md`](../AGENTS.md)): they land on the **minimum affected version
branch** and cascade upward.

| Branch   | PHP minor | Status                  |
|----------|-----------|-------------------------|
| `master` | 8.5       | actively maintained     |
| `8.4`    | 8.4       | actively maintained     |
| `8.0`    | 8.0       | frozen (no fixes)       |

## Reporting a vulnerability

There is no dedicated security contact for this project yet. Please use
**GitHub private vulnerability reporting** — the "Report a vulnerability"
button under the repository's *Security* tab — so the report stays private
until a fix is available. Do not open a public issue for something you believe
is exploitable.

Include the information the bug report template asks for: the full first line
of `php -v`, the thread-safety mode (NTS/ZTS), OS/architecture, and a minimal
reproducer. The exact build determines the engine memory layout, so a report
without it cannot be reproduced.

## What counts as a vulnerability here

z-engine is a library whose entire purpose is to reach into the Zend Engine's
own memory through FFI and write to it. It requires `ffi.enable=1` and runs
with the full privileges of the PHP process. **Crashing your own process is
therefore an expected failure mode, not a vulnerability.**

Not a vulnerability:

- a segfault, `zend_mm` abort, or assertion failure caused by calling z-engine
  APIs (including on a PHP minor the branch does not target — the version
  guard in `Core::init()` refuses that on purpose);
- memory corruption reached by passing raw pointers, `FFI\CData`, or
  `@internal` APIs values they were never meant to receive;
- the fact that code able to call z-engine can modify class tables, swap
  function bodies, or write engine globals. Code that can already load this
  library can already do all of that with FFI directly.

Likely a vulnerability — please report:

- a wrong struct offset or layout in the generated definitions for a
  **supported** platform/PHP build, i.e. memory corruption in ordinary,
  documented use with matching versions;
- an API whose *documented, PHP-native* surface can be driven into corrupting
  memory using plain PHP values only;
- code execution or file writes triggered by data the library parses (the
  opcache file-cache payloads, the generator's downloaded PHP sources or devel
  packs, generated headers);
- a supply-chain problem in the build/CI pipeline (workflow permissions,
  action pinning, artifact handling).

Because the layout guard (`ZENGINE_STRICT_LAYOUT_CHECK=1`) is the main defence
against the first category, a report showing it passes while memory is still
corrupted is especially valuable.
