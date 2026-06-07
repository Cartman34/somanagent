# Throwaway Migrations

> Scope: scripts under `scripts/migrations/` — one-shot data or format migrations that exist for a bounded time and must be retired.
> Convention: see [Script Conventions — Throwaway Migration Scripts](scripts-conventions.md#throwaway-migration-scripts).

Each migration is added here when its file is introduced, kept while it is active or applied, and moved to the retired section once its file has been deleted from the repository.

`scripts/backlog/backlog.php` blocks every backlog command while a script under `scripts/migrations/` is not listed in `local/backlog/migrations.applied`. The error is a read-only system block for agents to report to the user, not an instruction for agents to run.

Each migration script is responsible for appending its own filename to `local/backlog/migrations.applied` after successful execution. The backlog bootstrap never marks a migration as applied; it only seeds the marker for migrations that predate this convention and then checks for pending entries.

## Active migrations

| Date introduced | Slug | Purpose | Remove after | Status |
|---|---|---|---|---|
| 2026-05-17 | backlog-yaml | Convert `local/backlog-board.md` (markdown + pseudo-YAML meta) to the new structured `local/backlog-board.yaml` format. Source `.md` is preserved; operator removes it manually once satisfied. | All known WAs have been regenerated against the YAML board and no operator needs to migrate a leftover `.md` board anymore. | active |
| 2026-05-18 | backlog-dir | Move `local/backlog-board.yaml` → `local/backlog/backlog-board.yaml` and `local/backlog-review.md` → `local/backlog/backlog-review.md`. Lock path also moved to `local/backlog/backlog.lock`. | All WPs have been migrated and no backlog.php version expecting the old paths is still in use. | active |
| 2026-05-19 | rename-agent-to-developer | Rename the `agent:` key to `developer:` in the `todo` and `active` sections of `local/backlog/backlog-board.yaml`. | All WPs have been migrated and no backlog.php version writing the `agent:` key is still in use. | active |

## Historical renames (no data migration)

These entries document breaking command renames that required no migration script. Old command names are no longer recognised. Any agent session active at the time of the rename must be updated manually.

| Date | Change |
|---|---|
| 2026-05-19 | `task-remove` → `entry-remove`, `entry-assign` → `assign`, `entry-unassign` → `unassign`, `entry-rebase` → `rebase`, `entry-release` → `release`, `entry-rename` → `rename`, `entry-merge` → `merge`, `work-start` → `start`, `commit-gate` → `precommit-check` (backlog.php); `sessions` → `agent-history` (backlog-agent.php) |

## Retired migrations

| Date introduced | Date retired | Slug | Purpose |
|---|---|---|---|
| — | — | — | — |
