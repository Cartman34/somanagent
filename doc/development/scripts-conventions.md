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

## Reusable Runtime

- Reuse `Application` for environment bootstrapping and subprocess execution.
- Reuse `Console` for user-facing terminal output.
- Keep command execution centralized instead of scattering raw `exec()` / `passthru()` calls everywhere.
- If a direct Docker/container command becomes a repeated workflow, extract a dedicated runnable script instead of duplicating the command across docs, reviews, or UI hints.

## Documentation Rules

- Every runnable script must have a shebang and a header with:
  - `Description:`
  - one or more `Usage:`
- `php scripts/help.php` must remain accurate.
- `doc/development/scripts.md` must list runnable scripts that are intended for developer use.
- Deeper rules or cross-cutting standards for scripts belong in this file, not in a local `README` inside `scripts/`.

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

## Review Expectations

A script change is not done until it also respects:

- code structure conventions in this file
- script registration/help discoverability
- relevant documentation in `doc/`
- real execution validation when the script affects local runtime behavior
