# Contributing to z-engine

Thanks for helping improve z-engine! Because this library manipulates the Zend
Engine's internal memory directly, contributing to it has a couple of unusual
rules. Please skim [`AGENTS.md`](AGENTS.md) — it is the full contract for both
humans and automated tools, and this document is the short human version.

## Before you start

- **Match your PHP version to the branch.** `master` targets the newest
  supported PHP minor, branch `8.4` targets PHP 8.4, and so on. Running against
  the wrong minor crashes PHP — this is the single most important rule.
- Develop against a **debug PHP build** when you can (`--enable-debug`, FFI
  enabled). It turns silent memory corruption into loud assertion failures. A
  ready-made image lives in `tools/docker/php-debug.Dockerfile`.

## Setup

```bash
composer install
composer test          # safe suite
composer phpstan       # static analysis at level max
composer cs:check      # coding standards
```

To run the destructive, segfault-prone tests use the debug build:

```bash
composer test:internal
```

## Making changes

1. Branch from the **minimum affected version branch** (see the branch model in
   `AGENTS.md`) — fixes cascade upward, never downward.
2. Keep new code clean at PHPStan level max and passing `composer cs:check`.
3. If you change the set of engine symbols the library uses, update
   `tools/generator/symbols.php` and run `composer gen-headers` (needs Docker).
   Never hand-edit anything under `include/`.
4. Add or update tests. If a struct is dereferenced by your code, make sure it
   is listed in `layout_structs` so its layout is verified against the C
   compiler.
5. Use [Conventional Commits](https://www.conventionalcommits.org/) for your
   commit messages.

## Pull request checklist

- [ ] Targets the correct branch for the PHP version affected
- [ ] `composer test` passes on the matching PHP minor
- [ ] `composer phpstan` and `composer cs:check` are green
- [ ] Generated `include/` artifacts regenerated if engine symbols changed
- [ ] Tests added or updated
- [ ] Conventional commit messages

## Reporting bugs

Open an issue using the bug report template and include the full first line of
`php -v`, your thread-safety mode (NTS/ZTS), and OS/architecture. The exact
build determines the memory layout, so this information is essential.
