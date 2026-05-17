# Throwaway Migrations

> Scope: scripts under `scripts/migrations/` — one-shot data or format migrations that exist for a bounded time and must be retired.
> Convention: see [Script Conventions — Throwaway Migration Scripts](scripts-conventions.md#throwaway-migration-scripts).

Each migration is added here when its file is introduced, kept while it is active or applied, and moved to the retired section once its file has been deleted from the repository.

## Active migrations

| Date introduced | Slug | Purpose | Remove after | Status |
|---|---|---|---|---|
| 2026-05-17 | backlog-yaml | Convert `local/backlog-board.md` (markdown + pseudo-YAML meta) to the new structured `local/backlog-board.yaml` format. Source `.md` is preserved; operator removes it manually once satisfied. | All known WAs have been regenerated against the YAML board and no operator needs to migrate a leftover `.md` board anymore. | active |

## Retired migrations

| Date introduced | Date retired | Slug | Purpose |
|---|---|---|---|
| — | — | — | — |
