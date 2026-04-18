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
- `WP` is the only workflow workspace.
- `WA` is a development copy for one developer agent, not a runtime workspace.
- If a command touches backlog state, review state, containers, runtime, networked services, or GitHub, do not run it from `WA`.
- From `WP`, never launch dependent workflow commands in parallel. Any sequence where one command depends on the previous result, especially Git operations such as `add` then `commit`, must be run strictly one after another.
- A `WA` belongs to the developer agent and is treated as ephemeral.
- A branch belongs to the active feature.
- A feature branch must never stay checked out in multiple worktrees at the same time.
- Keep `.worktrees/` ignored in the root `.gitignore`.
- Run every `php scripts/backlog.php ...` command from `WP` only, never from a `WA`.
- This rule is technically enforced by `scripts/backlog.php`: the command fails if it is launched from a `WA` or any other directory.
- `WA` runtime dependencies use local copies for `backend/vendor` and `frontend/node_modules`, created from `WP` when the `WA` is created or when those paths are missing. Root `.env` and `backend/.env.local` are refreshed by the workflow.
- Use `php scripts/backlog.php worktree-list` to inspect managed worktrees under `.worktrees/`.
- Use `php scripts/backlog.php worktree-clean` to remove only abandoned managed worktrees that are safe to delete.
- Worktrees outside `.worktrees/` are never auto-removed by backlog commands; inspect them manually, then use `git worktree remove <path>` or `git worktree prune`.

## Feature Identity Rules

1. Every active task is attached to one feature.
2. For a plain task, the canonical identifier is the feature slug.
3. For a queued task prefixed as `[feature-slug][task-slug]`, the parent feature keeps `meta.kind=feature` and `meta.feature=<feature-slug>`, while the child task uses `meta.kind=task`, `meta.feature=<feature-slug>`, and `meta.task=<task-slug>`.
4. Active entries in `Traitement en cours` must keep the task line and its sub-tasks together, then end with a trailing `meta:` block, for example:
   `- Task text`
   `  - sub-task`
   `  meta:`
     `    kind: feature`
     `    stage: development`
     `    feature: <slug>`
     `    agent: <code>`
     `    branch: <type>/<slug>`
     `    base: <sha>`
     `    pr: none`
5. For child task entries, `meta.feature-branch` stores the local parent feature branch, and `meta.branch` stores the local child task branch.
6. Child task slugs must be unique inside one feature. One `[feature-slug][task-slug]` maps to one local child branch `<type>/<feature-slug>--<task-slug>` and one contribution block in the parent feature entry.
7. Parent `kind=feature` entries keep their own summary text, while merged or active child task contributions are stored in machine-managed lines prefixed with `[task:<task-slug>]`.
8. `<type>` is `feat` or `fix` on the branch.
9. Every developer commit on a feature branch must start with `[<slug>]`.
10. Review and approval must be scoped from the recorded `base` commit, not from the current `main`.
11. Active workflow state is stored in `meta.stage` with one of:
   `development`, `review`, `rejected`, `approved`.
12. The `meta:` block is absent from queued tasks that have never been taken.
13. Inside one active entry, `meta:` is always the final block. The entry ends on the next blank line, next root `- ...`, or next section title.

## Agent Code Rules

1. An agent code is a local workflow identifier.
2. It must be used exactly as assigned, without truncation, normalization, inference, or nickname conversion.
3. Example: if the assigned code is `agent-03`, use `agent-03` everywhere, not `03`.

## Assignment Permission Rules

1. `feature-assign` and `feature-unassign` read the active caller role from `SOMANAGER_ROLE`.
2. Allowed values are `manager` and `developer`.
3. When `SOMANAGER_ROLE=developer`, `SOMANAGER_AGENT` is mandatory and must match the `--agent` value passed to the command.
4. `Manager` may assign or unassign any feature for any developer agent.
5. `Developer` may only assign itself to an unassigned feature or keep the same self-assignment.
6. `Developer` may only unassign itself from its own feature.

## Command Policy

1. Use `php scripts/backlog.php` for the full local workflow.
2. Every developer command on `backlog.php` requires `--agent=<code>`.
3. Reviewer commands on `backlog.php` never use `--agent`.
4. The agent code must never leave local backlog files.
5. `feature-start` takes the next queued task directly from `## À faire`; no separate reservation step is part of the standard workflow.
6. Queued tasks may declare their branch type with a prefix `[feat]` or `[fix]`.
7. `feature-release` returns the active feature to `## À faire` only when no development was done on its branch. A parent `kind=feature` cannot be released while child `kind=task` entries still exist for that feature.
8. When `feature-start` consumes a queued task prefixed as `[feature-slug][task-slug]`, it creates or reuses the local parent feature branch from `origin/main`, ensures one active `kind=feature` entry exists for that feature, and creates the active child `kind=task` entry from that local parent branch.
9. Starting a new child task or merging a child task locally invalidates any parent feature review state and moves the parent `kind=feature` back to `development`.
10. `kind=task` entries are local-only delivery units: they are never pushed and never get GitHub PRs.
11. `feature-task-add --agent=<code> --feature-text=<text>` may absorb the next queued task into the current feature. If that queued task is prefixed as `[feature-slug][task-slug]`, it must target the current feature, it creates a new local child task entry, and it follows the same child-branch rules as `feature-start`.
12. `feature-task-add` must not mix a plain queued task into a feature that already uses local child tasks.
13. `task-review-request --agent=<code> [<task>|<feature/task>]` moves one child task to `review` after a green mechanical review in the task worktree.
14. `task-review-check`, `task-review-reject`, and `task-review-approve` apply only to `kind=task` entries and store local review notes under `local/backlog-review.md` with keys shaped as `<feature>/<task>`.
15. For `kind=task` entries, `meta.stage=approved` means the reviewer review is OK, but it does not grant any additional merge permission beyond `development` or `review`.
16. `feature-task-merge` merges one child task branch into its parent feature branch locally, after a green mechanical review in the task worktree, using either the worktree already bound to the parent branch or a temporary merge worktree.
17. `feature-task-merge --agent=<code> [<task>]` is the developer form for merging the current agent task after an explicit user merge instruction.
18. `feature-task-merge <feature>/<task>` is the reviewer form for merging one explicit child task locally.
19. The remote review, approval, and merge flow applies only to `kind=feature` entries and is blocked while child `kind=task` entries remain active for that feature.
20. Any backlog state change covered by `backlog.php` must go through `backlog.php`, never through a manual file edit.
21. Manual edits to `local/backlog-board.md` or `local/backlog-review.md` are forbidden unless the user explicitly asks for a manual edit outside the scripted workflow.
22. `--dry-run` simulates backlog, git, GitHub, and filesystem mutations without executing them.
23. `--verbose` prints detailed execution steps and simulated commands.
24. When the user invokes a documented workflow keyword or command sequence, agents must rerun that documented procedure each time unless the user cancels it. Repetition is not a reason to switch to advisory mode or rely on remembered state instead of the workflow result.
