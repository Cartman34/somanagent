# Agent Developer Workflow

Detailed instructions for the `Developer` role defined in `AGENTS.md`.

Read this file only when the active task requires developer workflow details.

## Allowed Commands

- `task-create`
- `status`
- `task-todo-list`
- `task-remove`
- `review-request`
- `rework`
- `entry-rename`
- `work-start`
- `feature-release`
- `feature-task-merge`
- `feature-assign`
- `feature-unassign`
- `feature-block`
- `feature-unblock`
- `feature-list`
- `worktree-list`
- `worktree-clean`
- `worktree-restore`

## Responsibilities

- manage one `WA` identified by the agent code
- start work on the next queued task, optionally release untouched features, and continue development on the feature branch
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
- For frontend TypeScript validation, do not run raw `npx tsc`; use `php scripts/validate-files.php --with-types <changed-frontend-files>` so the same check is available to mechanical review.
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
2. By default the script appends the task to the end of `## To do`.
3. `--position=start` inserts at the start of `## To do`.
4. `--position=index --index=<n>` inserts at the requested 1-based position and clamps out-of-range values to the start or the end.
5. When you create a queued task, prefix the description with `[feat]` or `[fix]` so `work-start` can derive the branch type from the backlog entry.

### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks in priority order.

### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## To do`.

### `entry-rename`

1. Run `php scripts/backlog.php entry-rename --agent=<code> <new-text>`.
2. The script updates the main text of the agent's active entry, whether it is a `kind=task` or a `kind=feature`.
3. For `kind=task`, the corresponding contribution line inside the parent feature container is also updated to keep them in sync.
4. The agent can only rename their own active entry.

### `rework`

1. Run `php scripts/backlog.php rework --agent=<code> [<feature>|<task>|<feature/task>]`.
2. Without an explicit reference, the script resolves the single rejected entry (task or feature) assigned to the agent.
3. With a `<feature/task>` reference, the script targets that child task. With a plain slug, it tries feature first then task, and errors if both match.
4. The script requires the entry to be in `meta.stage=rejected`, moves it back to `meta.stage=development`, displays the stored review notes from `local/backlog-review.md`, and reopens the entry branch in the agent `WA`.
5. The review notes stay in `local/backlog-review.md` until the next `review-request` clears them.

### `work-start`

1. Run `php scripts/backlog.php work-start --agent=<code>`.
2. The agent must have no active entry. If one exists, the script refuses and describes the required next step.
3. The script reads the branch type from the queued task prefix `[feat]` or `[fix]`. If no type prefix is present, it falls back to `feat`.
4. The script takes the next task from `## To do`, updates local `main` when possible, creates the branch in the agent worktree, moves the entry to `## In progress`, sets `meta.stage=development`, and authorizes development.
5. Behaviour depends on the queued task prefix (after the optional `[feat]`/`[fix]` type prefix):
   - **`[feature-slug][task-slug] text`** — creates or reuses the parent `kind=feature` entry for `<feature-slug>` with `agent=none`, and creates the child `kind=task` entry assigned to the agent on branch `<type>/<feature-slug>--<task-slug>`. The `kind=feature` container stays unassigned until a developer explicitly takes integration ownership with `feature-assign`.
   - **`[feature-slug] text`** — creates a plain `kind=feature` with the explicit slug `<feature-slug>`, assigned to the agent, on branch `<type>/<feature-slug>`.
   - **`text` (no feature prefix)** — creates a plain `kind=feature` with a slug derived from the task text, assigned to the agent.
6. The command output includes the started task when applicable, the parent feature summary and details, and the assigned worktree path and branch.
7. `work-start` is local-only: it does not push and it does not create a PR.

### `feature-release`

1. Run `php scripts/backlog.php feature-release --agent=<code> [<feature>]`.
2. The script returns the active feature to the start of `## To do` only when the branch is still clean and has no commit ahead of its recorded `base`.
3. A parent `kind=feature` cannot be released while child `kind=task` entries are still active for that feature.
4. The script then removes the managed worktree and deletes the untouched local branch.

### `feature-task-merge`

1. Run `php scripts/backlog.php feature-task-merge --agent=<code> [<task>|<feature/task>]`.
2. The script targets the agent's active `kind=task` entry, or the explicit task reference when provided.
3. The script requires a green mechanical review in the task worktree, then merges the child branch into the parent feature branch locally from the parent feature worktree or from a temporary merge worktree.
4. The current task review stage does not gate this merge. `development`, `review`, `rejected`, and `approved` are all mergeable when the user explicitly asks for `merge`.
5. The child task entry is removed from `## In progress` after the local merge. The child task worktree is removed when that agent no longer owns any active task.
6. The parent `kind=feature` entry remains, keeps the merged task content in its aggregated lines, and is moved back to `development` so the remote review flow must be requested again on the parent branch.

### `feature-assign`

1. Run `php scripts/backlog.php feature-assign --agent=<code> <feature>`.
2. Export `SOMANAGER_ROLE=developer` and `SOMANAGER_AGENT=<code>` before running the command.
3. Developer can only assign a feature to itself, and only when the feature is not assigned to another agent.
4. The script assigns the feature to that same agent and prepares the `WA`.
5. For `kind=feature` containers created with `agent=none` (from a `[feature-slug][task-slug]`-prefixed task), this is the required step before running `review-request` on the feature. The developer takes integration ownership of the feature branch.

### `feature-unassign`

1. Run `php scripts/backlog.php feature-unassign --agent=<code> [<feature>]`.
2. Export `SOMANAGER_ROLE=developer` and `SOMANAGER_AGENT=<code>` before running the command.
3. Developer can only remove its own assignment from its own feature.
4. The script removes the current agent assignment from the target feature and keeps the feature in its current backlog section.
5. If this leaves behind an abandoned managed worktree under `.agent-worktrees/`, the script runs `worktree-clean` automatically.

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
2. The script lists worktrees under `.agent-worktrees/` with their cleanup state and recommended action.
3. Worktrees outside `.agent-worktrees/` are reported separately for manual cleanup only.
4. Use this command only when there is a cleanup need outside the normal workflow procedure.

### `worktree-clean`

1. Run `php scripts/backlog.php worktree-clean`.
2. The script removes only abandoned managed worktrees under `.agent-worktrees/` when they are safe to delete.
3. Dirty, blocked, or external worktrees are left untouched and must be handled manually.

### `worktree-restore`

1. Run `php scripts/backlog.php worktree-restore --agent=<code>` or `php scripts/backlog.php worktree-restore <feature>`.
2. The script recreates or refreshes the managed worktree for the active feature or task recorded in backlog metadata without changing the workflow stage.
3. Existing PHP vendors are validated with `scripts/vendor/autoload.php` and `backend/vendor/autoload.php`; when a witness is missing, the whole matching vendor directory is replaced from `WP`.
4. Run `php scripts/backlog.php worktree-restore --agent=<code> --force` to recreate the managed worktree completely; the script refuses `--force` when the existing worktree has local changes.
5. Use this command when `.agent-worktrees/<agent>` was removed or when copied PHP runtime dependencies are incomplete while the backlog still has active development.

### `status`

1. Run `php scripts/backlog.php status --agent=<code>` or `php scripts/backlog.php status <feature>`.
2. The script prints the agent worktree state, the active task if any, the parent feature if any, and separate next actions for task and feature workflow.
3. Use this command to inspect the current active entry before running `review-request`.

### `review-request`

1. Run `php scripts/backlog.php review-request --agent=<code>`.
2. The script resolves the agent's single active entry automatically: if `kind=task`, submits the task for review; if `kind=feature`, submits the feature for review.
3. For `kind=feature`, requires all child `kind=task` entries to have been merged locally first, and requires the agent to be assigned to the feature via `feature-assign`.

## Rules

- An agent can have at most one active entry (`kind=task` or `kind=feature`) at a time. `work-start` and `feature-assign` enforce this at the script level and will refuse with the current active entry details and the required next step.
- Do not edit local backlog files directly.
- A plain feature is considered done for Developer only when it is committed, mechanically valid, and passed to `meta.stage=review`.
- A `kind=task` entry may be submitted for review with `review-request`, but it is considered done for Developer only when it is committed, mechanically valid, and merged locally into its parent feature branch with `feature-task-merge`.
- For `feature-assign` and `feature-unassign`, `SOMANAGER_ROLE` must be `developer` and `SOMANAGER_AGENT` must match `--agent`.
- User workflow keywords are procedural orders. For `next`, `submit`, `rework`, and `cleanup`, execute the documented command sequence exactly as written, even if memory suggests the feature state is inconsistent or unchanged.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

## User Keywords

### `next`

1. `WP`: run `php scripts/backlog.php work-start --agent=<code>`.
2. `WA`: implement the feature scope on the branch checked out for that task.
3. `WA`: inspect the local diff and fix issues in scope before moving on.
4. `WA`: run `git add .`.
5. `WA`: run `git commit -m "[<feature-slug>] ..."` using the canonical feature identifier recorded in the backlog metadata and branch name.

### `submit`

1. `WP`: run `php scripts/backlog.php review-request --agent=<code>`.
2. For `kind=feature`, this keyword still applies only after all child `kind=task` entries have already been merged locally, and after `feature-assign` has been run to take integration ownership.

### `merge`

1. `WP`: if the active entry is `kind=task`, run `php scripts/backlog.php feature-task-merge --agent=<code> [<task>|<feature/task>]`.
2. This keyword merges the local task only on explicit user instruction; it is not implied by `submit`.

### `rework`

1. The review feedback is given with the `rework` instruction. The `rework` command provides the task status and review notes directly in its output. Do not run `status` or read `local/backlog-review.md` before proceeding.
2. `WP`: run `php scripts/backlog.php rework --agent=<code> [<feature>|<task>|<feature/task>]`.
3. `WA`: resume development on the same branch and address the recorded review feedback.
4. Stop here. Do not run `submit` unless the user explicitly asks for it.

### `cleanup`

1. `WP`: run `php scripts/backlog.php worktree-clean`.
2. `WP`: use `php scripts/backlog.php worktree-list` only when you need a cleanup diagnostic outside the standard workflow.
