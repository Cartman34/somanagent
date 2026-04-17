# Agent Developer Workflow

Detailed instructions for the `Developer` role defined in `AGENTS.md`.

Read this file only when the active task requires developer workflow details.

## Allowed Commands

- `task-create`
- `task-todo-list`
- `task-remove`
- `feature-start`
- `feature-release`
- `feature-task-add`
- `feature-deps-mode`
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
- run `php scripts/backlog.php ...` from `WP` only; backlog commands are not allowed from `WA`
- run `php scripts/review.php` after every implementation and fix mechanical blockers within scope
- critically challenge the implementation for gaps, regressions, and convention violations before considering it ready for review
- update docs when required by the code change
- keep `local/backlog-board.md` in sync with the current stage of the feature through `backlog.php`
- keep the dependency mode explicit for the active feature

## Do Not

- start implementing, editing, or committing for a feature before it is assigned to that exact agent code and started in that agent's dedicated `WA`
- run reviewer commands or `merge`
- use raw git or GitHub commands when `backlog.php` provides the workflow step
- start a second visible backlog entry for the same feature
- edit `local/backlog-board.md` or `local/backlog-review.md` manually
- change shared dependencies from a linked `WA` without switching the feature to `isolated` first

## Read Only When Needed

- `local/backlog-board.md` for feature state
- `local/backlog-review.md` for rework input

## Command Behavior

### `task-create`

1. Run `php scripts/backlog.php task-create <description> [--position=<start|index|end>] [--index=<n>]`.
2. By default the script appends the task to the end of `## À faire`.
3. `--position=start` inserts at the start of `## À faire`.
4. `--position=index --index=<n>` inserts at the requested 1-based position and clamps out-of-range values to the start or the end.

### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks in priority order.

### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## À faire`.

### `feature-start`

1. Run `php scripts/backlog.php feature-start --agent=<code> --branch-type=<feat|fix>`.
2. The script takes the next task from `## À faire`, creates the feature branch in the agent worktree, moves the feature to `## Traitement en cours`, sets `meta.stage=development`, and authorizes development.
3. `feature-start` is local-only: it does not push and it does not create a PR.

### `feature-release`

1. Run `php scripts/backlog.php feature-release --agent=<code> [<feature>]`.
2. The script returns the active feature to the start of `## À faire` only when the branch is still clean and has no commit ahead of its recorded `base`.
3. The script then removes the managed worktree and deletes the untouched local branch.

### `feature-task-add`

1. Run `php scripts/backlog.php feature-task-add --agent=<code> --feature-text=<text> [--body-file=<path>]`.
2. The script absorbs the next task from `## À faire` into the current feature.
3. If a PR already exists for the feature, the script updates its body when `--body-file` is provided.

### `feature-deps-mode`

1. Run `php scripts/backlog.php feature-deps-mode --agent=<code> [<feature>] <linked|isolated>`.
2. `linked` uses the shared dependency directories from `WP` through symlinks inside the `WA`.
3. `isolated` gives the `WA` its own dependency directories copied from `WP`.
4. The script updates `deps` in the feature `meta:` block.

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

1. Read `local/backlog-review.md`.
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
- A task is considered done for Developer only when it is committed, mechanically valid, and passed to `meta.stage=review`.
- For `feature-assign` and `feature-unassign`, `SOMANAGER_ROLE` must be `developer` and `SOMANAGER_AGENT` must match `--agent`.
- User workflow keywords are procedural orders. For `next`, `submit`, `rework`, and `cleanup`, execute the documented command sequence exactly as written, even if memory suggests the feature state is inconsistent or unchanged.
- If a new task is added to an existing feature, keep a single backlog line for that feature and preserve all useful scope details.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

## User Keywords

### `next`

1. Run `php scripts/backlog.php feature-start --agent=<code> --branch-type=<feat|fix>`.
2. Implement the feature scope in the assigned developer worktree `WA`, not in `WP`.
3. Work on the feature branch checked out by `feature-start` for that task.
4. Run `php scripts/review.php` and fix every blocker in scope before moving on. It cannot be replaced by running `php -l`.
5. Commit the work on that feature branch with a message starting with `[<feature-slug>]`, where `<feature-slug>` is the canonical feature identifier recorded in the backlog metadata and used in the branch name.

### `submit`

1. Verify the mechanical review is green with `php scripts/review.php`.
2. Run `php scripts/backlog.php feature-review-request --agent=<code> [<feature>]`.

### `rework`

1. Read `local/backlog-review.md`.
2. Run `php scripts/backlog.php feature-rework --agent=<code> [<feature>]`.

### `cleanup`

1. Run `php scripts/backlog.php worktree-clean`.
2. Use `php scripts/backlog.php worktree-list` only when you need a cleanup diagnostic outside the standard workflow.
