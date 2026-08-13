<!--
Thanks for contributing! CONTRIBUTING.md is the short version of the rules,
AGENTS.md the full contract. The one non-negotiable rule: never run z-engine
against a PHP minor other than the one this branch targets.
-->

## What this changes

<!-- A short description, and the issue it fixes (e.g. "Fixes #123"). -->

## Environment it was verified on

- PHP version (full first line of `php -v`):
- Thread safety: NTS / ZTS
- OS / architecture:
- Debug build (`--enable-debug`)? yes / no

## Checklist

- [ ] Targets the **minimum affected version branch** (`master` = newest
      supported minor, `8.4` = PHP 8.4, …) — fixes cascade upward, never
      downward
- [ ] `composer test` passes on the matching PHP minor
- [ ] `composer phpstan` (level max) and `composer cs:check` are green
- [ ] Tests added or updated; structs the code dereferences are listed in
      `layout_structs` in `tools/generator/symbols.php`
- [ ] If `tools/generator/symbols.php` changed: regenerated with
      `composer gen-headers` and committed the result — nothing under
      `include/`, `stubs/` or `.phpstorm.meta.php` was hand-edited
- [ ] Conventional Commits used for the commit messages
