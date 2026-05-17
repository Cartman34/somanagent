# Script Conventions

> Scope: every file under `scripts/` and its subdirectories, recursively.
> See also: [Available Scripts](scripts.md)

## Intent

The `scripts/` tree exists to provide reliable local tooling for SoManAgent:

- top-level runnable scripts in `scripts/`
- shared implementation code in `scripts/src/`
- specialized helper subtrees such as `scripts/claude/`

These files are part of the project codebase and must follow explicit conventions, not ad hoc convenience patterns.

## Language Choice

- Prefer PHP for project scripts by default.
- Use Bash only when the task is genuinely shell-native or platform/bootstrap-specific.
- If a script starts simple but grows beyond a thin wrapper, move its logic into PHP.

## Structural Rules

- Keep top-level runnable scripts thin.
- Put non-trivial logic in dedicated classes under `scripts/src/`.
- Prefer object-oriented code for stateful or multi-step workflows.
- Avoid passing long lists of repeated parameters through procedural helper functions when those values can be object properties.
- Avoid large clusters of global functions for anything more than trivial bootstrapping.
- Replace repeated script modes, scopes, engines, states, and metadata keys with constants unless a real enum is available.
- Prefer static configuration maps or equivalent declarative structures over chains of `if` statements when the behavior is configuration-driven.
- Keep validation values and execution routing derived from the same constants/configuration source when possible.
- Always use braces for control-flow blocks, even for one-line branches or loops.
- Put the block body on its own indented line; do not keep `if (...) continue;` or similar compact forms.

## Reusable Runtime

- Reuse `Application` for environment bootstrapping and subprocess execution.
- Reuse `Console` for user-facing terminal output.
- Keep command execution centralized instead of scattering raw `exec()` / `passthru()` calls everywhere.
- If a direct Docker/container command becomes a repeated workflow, extract a dedicated runnable script instead of duplicating the command across docs, reviews, or UI hints.

## Documentation Rules

- Every runnable script must have a shebang and a header with:
  - `Author:`
  - `Description:`
  - one or more `Usage:`
- Every runnable script must accept `-h` or `--help` and display its header (description + usage).
- `php scripts/help.php` must remain accurate.
- `doc/development/scripts.md` must list runnable scripts that are intended for developer use.
- Deeper rules or cross-cutting standards for scripts belong in this file, not in a local `README` inside `scripts/`.

## Executable Bit

- Every runnable script (any file under `scripts/` that starts with `#!`) must carry the exec bit in the git index, persisted via `git update-index --chmod=+x <file>`. Running `chmod +x` locally is not enough — the mode must be recorded in the index so it survives a fresh clone.
- Verify the index mode with `git ls-files --stage scripts/*.php` — the leading column must read `100755` (not `100644`) for every shebang-bearing file.
- This invariant is enforced at review time by `scripts/validate-files.php`, which reports `Script exec bit: FAIL` and prints a `git update-index --chmod=+x` recovery hint for each shebang-bearing script that lost its exec bit. Repair the regression in one step with `php scripts/fix-permissions.php`, which applies `chmod +x` and `git update-index --chmod=+x` to every shebang-bearing entrypoint missing the bit.
- PHP files under `scripts/` that are not runnable (libraries, generated code, fixtures) intentionally have no shebang and are skipped by the validator.

Expected author syntax:

```bash
# Author: Florent HAZARD <f.hazard@sowapps.com>
```

## Design Guidelines

- Make the source of truth explicit when a script synchronizes or transforms data.
- Prefer predictable, idempotent operations when possible.
- Make destructive actions explicit and guarded by confirmation, unless a force flag is intentionally provided.
- Print enough context for diagnosis, but do not hide important side effects such as container recreation or data loss.

## Error Handling

- Fail with actionable messages.
- Surface the failing command context when a subprocess exits unsuccessfully.
- Distinguish between diagnostic/status commands and mutating commands:
  - status commands should report degraded states without necessarily aborting early
  - mutating commands should fail fast on invalid or partial execution

## File and Path Handling

- Treat mounted directories carefully.
- Do not replace or delete the root of a bind-mounted directory when preserving the mount point matters; clear contents instead.
- Prefer dedicated methods for directory creation, cleanup, copy, and hashing rather than duplicating filesystem logic inline.
- Project code never reads or writes anywhere under `.git/`. That directory is git's internal state, subject to git's own locking and conventions; any project write concurrent with a git operation on a shared `.git/` (worktrees) risks lock errors. File exclusions go through a versioned `.gitignore` at the repository root or a `.gitignore` placed in the relevant subdirectory — never through `.git/info/exclude` or any other file under `.git/`. Helpers like `git rev-parse --git-path` or `git update-index --assume-unchanged` exist only to coordinate with git internals and have no place in project tooling.

## Local Working Directories

- `local/tmp/` is reserved for short-lived session files: backlog or review body files, drafts, disposable fixtures, and one-shot debug notes. Its contents may be cleaned between sessions.
- `local/tests/` is reserved for outputs produced by test execution: PHPUnit reports, TestDox/JUnit/log HTML files, coverage outputs, error dumps, output snapshots, and stdout/stderr captures from test campaigns.
- Test inputs must live in source-controlled test resources such as `tests/`, `scripts/src/.../Test/`, or a nearby `resources/` directory, not under `local/`.
- Bootstrap and worktree preparation must keep both `local/tmp/` and `local/tests/` present with tracked `.gitkeep` files; directory contents remain gitignored.

## Test Fixtures

- Prefer using an existing project resource file in tests when the test validates the current production contract.
- If a test needs example variants that should not change with production config, put them in an explicit `resources/` or `fixtures/` directory next to the relevant test suite.
- Avoid large inline fixture strings in test methods when a named fixture file makes the intent clearer, even if that means keeping several fixture versions.

## Throwaway Migration Scripts

One-shot data or format migrations are not permanent tooling. They exist for a bounded time and must be retired once they have done their job.

- Migration scripts live under `scripts/migrations/`, never in the top-level `scripts/` tree where permanent tooling resides.
- Each file is named `YYYY-MM-DD-<slug>.php` using its introduction date; the chronological order is read directly from the filename.
- The header docblock must declare:
  - `Purpose:` one short sentence describing what the migration does
  - `Introduced:` the date the migration was added
  - `Remove after:` an explicit condition or date for retirement (e.g. "after all WAs have been regenerated" or "after 2026-08-01")
- Migration scripts must be idempotent: running them twice produces the same result, including on an already-migrated dataset (no-op on the second run).
- The active registry lives in [`migrations.md`](migrations.md). Every migration is listed there with its slug, introduction date, expected removal trigger, and current status (active / applied / retired).
- Once retired, the file is deleted and its registry entry is marked `retired` with the actual removal date; entries are kept in the registry as historical record.
- Migration scripts are not listed in [`scripts.md`](scripts.md) — that file documents permanent tooling. `migrations.md` is the sole user-facing surface for migrations.
- `php scripts/migrations-audit.php` reports migrations whose retirement condition is met but whose file is still present.

## Review Expectations

A script change is not done until it also respects:

- code structure conventions in this file
- script registration/help discoverability
- relevant documentation in `doc/`
- real execution validation when the script affects local runtime behavior
