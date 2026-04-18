# Agent Developer Workflow

Detailed instructions for the `Developer` role defined in `AGENTS.md`.

Read this file only when the active task requires developer workflow details.

## Allowed Commands

- `task-create`
- `task-todo-list`
- `task-remove`
- `task-review-request`
- `feature-start`
- `feature-release`
- `feature-task-add`
- `feature-task-merge`
- `feature-assign`
- `feature-unassign`
- `feature-rework`
- `feature-block`
- `feature-unblock`
- `feature-list`
- `worktree-list`
- `worktree-clean`
- `feature-review-next`
- `feature-status`
- `feature-review-request`

## Responsibilities

- manage one `WA` identified by the agent code
- start features, optionally release untouched features, and continue development on the feature branch
- commit on the feature branch with the feature slug prefix
- critically challenge the implementation for gaps, regressions, and convention violations before considering it ready for review
- update docs when required by the code change
- keep `local/backlog-board.md` in sync with the current stage of the feature through `backlog.php`
- rely on the prepared `WA` runtime state: `backend/vendor` and `frontend/node_modules` are copied from `WP` when the `WA` is created or when they are missing, while root `.env` and `backend/.env.local` are refreshed by the workflow

## Workspace Rules

- `WA`: edit code, inspect files, run local git on the active branch, and commit.
- `WP`: run `php scripts/backlog.php ...` and read local workflow state when needed.
- When one step is prefixed with `WP:`, the working directory must be `WP`.
- When one step is prefixed with `WA:`, the working directory must be the active agent `WA`.
- Forbidden for `Developer`: `php scripts/console.php`, `php scripts/node.php`, `php scripts/db.php`, `php scripts/dev.php`, `php scripts/health.php`, `php scripts/github.php`, and any script that talks to containers, runtime, database, network, or GitHub.
- If a command is not explicitly allowed for `Developer`, do not run it.

## Do Not

- start implementing, editing, or committing for a feature before it is assigned to that exact agent code and started in that agent's dedicated `WA`
- run reviewer commands
- merge a task or feature without an explicit user instruction
- use raw git or GitHub commands when `backlog.php` provides the workflow step
- start a second visible backlog entry for the same feature
- edit `local/backlog-board.md` or `local/backlog-review.md` manually

## Read Only When Needed

- `local/backlog-board.md` for feature state

## Command Behavior

### `task-create`

1. Run `php scripts/backlog.php task-create <description> [--position=<start|index|end>] [--index=<n>]`.
2. By default the script appends the task to the end of `## À faire`.
3. `--position=start` inserts at the start of `## À faire`.
4. `--position=index --index=<n>` inserts at the requested 1-based position and clamps out-of-range values to the start or the end.
5. When you create a queued task, prefix the description with `[feat]` or `[fix]` so `feature-start` can derive the branch type from the backlog entry.

### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks in priority order.

### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## À faire`.

### `task-review-request`

1. Run `php scripts/backlog.php task-review-request --agent=<code> [<task>|<feature/task>]`.
2. The script targets the agent's active `kind=task` entry, or the explicit task reference when provided.
3. The script requires a green mechanical review in the task worktree, moves the task to `meta.stage=review`, and clears any stale local task review notes for that task.

### `feature-start`

1. Run `php scripts/backlog.php feature-start --agent=<code>`.
2. The script reads the branch type from the queued task prefix `[feat]` or `[fix]`.
3. If no type prefix is present, the script falls back to `feat`.
4. The script takes the next task from `## À faire`, updates local `main` when possible, creates the feature branch from `origin/main` in the agent worktree, moves the feature to `## Traitement en cours`, sets `meta.stage=development`, and authorizes development.
5. If the queued task starts with `[feature-slug][task-slug]`, the script creates or reuses the parent `kind=feature` entry for `<feature-slug>`, keeps the shared parent branch `<type>/<feature-slug>`, then creates the child `kind=task` entry and local child branch `<type>/<feature-slug>--<task-slug>` from that local parent branch in the agent worktree.
6. `feature-start` is local-only: it does not push and it does not create a PR.

### `feature-release`

1. Run `php scripts/backlog.php feature-release --agent=<code> [<feature>]`.
2. The script returns the active feature to the start of `## À faire` only when the branch is still clean and has no commit ahead of its recorded `base`.
3. A parent `kind=feature` cannot be released while child `kind=task` entries are still active for that feature.
4. The script then removes the managed worktree and deletes the untouched local branch.

### `feature-task-add`

1. Run `php scripts/backlog.php feature-task-add --agent=<code> --feature-text=<text> [--body-file=<path>]`.
2. The script updates the current parent feature summary text, then absorbs the next task from `## À faire` into that feature.
3. If the queued task is prefixed as `[feature-slug][task-slug]`, it must target the current feature, it creates a new `kind=task` child entry and a local child branch `<type>/<feature-slug>--<task-slug>`.
4. A child `task-slug` must be unique inside its feature.
5. A feature that already uses local child tasks cannot absorb a plain queued task without a `[feature-slug][task-slug]` prefix.
6. If a PR already exists for the feature, the script updates its body when `--body-file` is provided.

### `feature-task-merge`

1. Run `php scripts/backlog.php feature-task-merge --agent=<code> [<task>|<feature/task>]`.
2. The script targets the agent's active `kind=task` entry, or the explicit task reference when provided.
3. The script requires a green mechanical review in the task worktree, then merges the child branch into the parent feature branch locally from the parent feature worktree or from a temporary merge worktree.
4. The current task review stage does not gate this merge. `development`, `review`, `rejected`, and `approved` are all mergeable when the user explicitly asks for `merge`.
5. The child task entry is removed from `## Traitement en cours` after the local merge. The child task worktree is removed when that agent no longer owns any active task.
6. The parent `kind=feature` entry remains, keeps the merged task content in its aggregated lines, and is moved back to `development` so the remote review flow must be requested again on the parent branch.

### `feature-assign`

1. Run `php scripts/backlog.php feature-assign --agent=<code> <feature>`.
2. Export `SOMANAGER_ROLE=developer` and `SOMANAGER_AGENT=<code>` before running the command.
3. Developer can only assign a feature to itself, and only when the feature is not assigned to another agent.
4. The script assigns the feature to that same agent and prepares the `WA`.

### `feature-unassign`

1. Run `php scripts/backlog.php feature-unassign --agent=<code> [<feature>]`.
2. Export `SOMANAGER_ROLE=developer` and `SOMANAGER_AGENT=<code>` before running the command.
3. Developer can only remove its own assignment from its own feature.
4. The script removes the current agent assignment from the target feature and keeps the feature in its current backlog section.
5. If this leaves behind an abandoned managed worktree under `.worktrees/`, the script runs `worktree-clean` automatically.

### `feature-rework`

1. Read the review feedback provided with the `rework` instruction.
2. Run `php scripts/backlog.php feature-rework --agent=<code> [<feature>]`.
3. Resume development on the same feature branch from `meta.stage=rejected` back to `meta.stage=development`.

### `feature-block`

1. Run `php scripts/backlog.php feature-block --agent=<code> [<feature>]`.
2. The script marks the feature as blocked and keeps the current backlog section.

### `feature-unblock`

1. Run `php scripts/backlog.php feature-unblock --agent=<code> [<feature>]`.
2. The script removes the blocked flag from the feature and updates the PR title when one exists.

### `feature-list`

1. Run `php scripts/backlog.php feature-list`.
2. The script prints active features grouped by workflow stage.

### `worktree-list`

1. Run `php scripts/backlog.php worktree-list`.
2. The script lists worktrees under `.worktrees/` with their cleanup state and recommended action.
3. Worktrees outside `.worktrees/` are reported separately for manual cleanup only.
4. Use this command only when there is a cleanup need outside the normal workflow procedure.

### `worktree-clean`

1. Run `php scripts/backlog.php worktree-clean`.
2. The script removes only abandoned managed worktrees under `.worktrees/` when they are safe to delete.
3. Dirty, blocked, or external worktrees are left untouched and must be handled manually.

### `feature-status`

1. Run `php scripts/backlog.php feature-status [--agent=<code>] [<feature>]`.
2. The script prints `Feature`, `Branch`, `Base`, `Stage`, `PR`, `Last`, `Next`, and `Blocker`.

### `feature-review-request`

1. Run `php scripts/backlog.php feature-review-request --agent=<code> [<feature>]`.
2. The script verifies that the feature is assigned to that agent, that the agent `WA` is the correct worktree for the feature branch, then requires a green mechanical review and sets `meta.stage=review`.

## Rules

- Do not start a second visible feature for the same agent.
- Do not edit local backlog files directly.
- A plain feature is considered done for Developer only when it is committed, mechanically valid, and passed to `meta.stage=review`.
- A `kind=task` entry may be submitted for review with `task-review-request`, but it is considered done for Developer only when it is committed, mechanically valid, and merged locally into its parent feature branch with `feature-task-merge`.
- For `feature-assign` and `feature-unassign`, `SOMANAGER_ROLE` must be `developer` and `SOMANAGER_AGENT` must match `--agent`.
- User workflow keywords are procedural orders. For `next`, `submit`, `rework`, and `cleanup`, execute the documented command sequence exactly as written, even if memory suggests the feature state is inconsistent or unchanged.
- If a new task is added to an existing feature, keep a single backlog line for that feature and preserve all useful scope details.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

## User Keywords

### `next`

1. `WP`: run `php scripts/backlog.php feature-start --agent=<code>`.
2. `WA`: implement the feature scope on the branch checked out for that task.
3. `WA`: inspect the local diff and fix issues in scope before moving on.
4. `WA`: run `git add .`.
5. `WA`: run `git commit -m "[<feature-slug>] ..."` using the canonical feature identifier recorded in the backlog metadata and branch name.

### `submit`

1. `WP`: if the active entry is `kind=task`, run `php scripts/backlog.php task-review-request --agent=<code> [<task>|<feature/task>]`.
2. `WP`: if the active entry is `kind=feature`, run `php scripts/backlog.php feature-review-request --agent=<code> [<feature>]`.
3. For `kind=feature`, this keyword still applies only after all child `kind=task` entries have already been merged locally.

### `merge`

1. `WP`: if the active entry is `kind=task`, run `php scripts/backlog.php feature-task-merge --agent=<code> [<task>|<feature/task>]`.
2. This keyword merges the local task only on explicit user instruction; it is not implied by `submit`.

### `rework`

1. Read the review feedback provided with the `rework` instruction.
2. `WP`: run `php scripts/backlog.php feature-rework --agent=<code> [<feature>]`.
3. `WA`: resume development on the same feature branch and address the recorded review feedback.

### `cleanup`

1. `WP`: run `php scripts/backlog.php worktree-clean`.
2. `WP`: use `php scripts/backlog.php worktree-list` only when you need a cleanup diagnostic outside the standard workflow.
