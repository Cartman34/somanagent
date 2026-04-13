# Agent Workflow

Detailed workflow reference for AI agents working on this repository.

Read this file only when a task needs backlog, worktree, feature, or command behavior details beyond the summary in `AGENTS.md`.

## Local Source Of Truth

- Backlog board: `local/backlog-board.md`
- Review state: `local/backlog-review.md`

Rules:

- Files under `local/` are local-only and must not be committed.
- For `local/backlog-board.md` and `local/backlog-review.md`, always follow the `## Règles d'usage` section in each file.
- The backlog board uses these working sections:
  `À faire` = queued priorities,
  `Traitement en cours` = active features with workflow state in `meta.stage`,
  `Suggestions` = non-committed ideas and future directions.
- Local backlog files are not edited manually.
- If a needed backlog transition or backlog mutation is not covered by an existing command, stop and ask the user before proceeding.

## Worktrees

- Developer work in a dedicated worktree is mandatory for every task.
- Create agent worktrees under `.worktrees/` inside the main repository so they stay in the same WSL filesystem and remain easy to ignore.
- Use `WP` for the main workspace and `WA` for one developer agent worktree.
- From `WP`, never launch dependent workflow commands in parallel. Any sequence where one command depends on the previous result, especially Git operations such as `add` then `commit`, must be run strictly one after another.
- A `WA` belongs to the developer agent and is treated as ephemeral.
- A branch belongs to the active feature.
- A feature branch must never stay checked out in multiple worktrees at the same time.
- Keep `.worktrees/` ignored in the root `.gitignore`.
- Run every `php scripts/backlog.php ...` command from `WP` only, never from a `WA`.
- This rule is technically enforced by `scripts/backlog.php`: the command fails if it is launched from a `WA` or any other directory.
- Use `php scripts/backlog.php worktree-list` to inspect managed worktrees under `.worktrees/`.
- Use `php scripts/backlog.php worktree-clean` to remove only abandoned managed worktrees that are safe to delete.
- Worktrees outside `.worktrees/` are never auto-removed by backlog commands; inspect them manually, then use `git worktree remove <path>` or `git worktree prune`.

## Feature Identity Rules

1. Every active task is attached to one feature.
2. The canonical identifier is the feature slug.
3. Active entries in `Traitement en cours` must keep the task line and its sub-tasks together, then end with a trailing `meta:` block, for example:
   `- Task text`
   `  - sub-task`
   `  meta:`
   `    stage: development`
   `    feature: <slug>`
   `    agent: <code>`
   `    branch: <type>/<slug>`
   `    base: <sha>`
   `    pr: none`
   `    deps: linked`
4. `<type>` is `feat` or `fix` on the branch.
5. Every developer commit on a feature branch must start with `[<slug>]`.
6. Review and approval must be scoped from the recorded `base` commit, not from the current `main`.
7. Active workflow state is stored in `meta.stage` with one of:
   `development`, `review`, `rejected`, `approved`.
8. The `meta:` block is absent from queued tasks that have never been taken.
9. Inside one active entry, `meta:` is always the final block. The entry ends on the next blank line, next root `- ...`, or next section title.

## Agent Code Rules

1. An agent code is a local workflow identifier.
2. It must be used exactly as assigned, without truncation, normalization, inference, or nickname conversion.
3. Example: if the assigned code is `agent-03`, use `agent-03` everywhere, not `03`.

## Command Policy

1. Prefer `php scripts/backlog.php` for the full local workflow.
2. Every developer command on `backlog.php` requires `--agent=<code>`.
3. Reviewer commands on `backlog.php` never use `--agent`.
4. The agent code must never leave local backlog files.
5. `feature-start` takes the next queued task directly from `## À faire`; no separate reservation step is part of the standard workflow.
6. `feature-release` returns the active feature to `## À faire` only when no development was done on its branch.
7. Any backlog state change covered by `backlog.php` must go through `backlog.php`, never through a manual file edit.
8. Manual edits to `local/backlog-board.md` or `local/backlog-review.md` are forbidden unless the user explicitly asks for a manual edit outside the scripted workflow.
9. `--dry-run` simulates backlog, git, GitHub, and filesystem mutations without executing them.
10. `--verbose` prints detailed execution steps and simulated commands.
