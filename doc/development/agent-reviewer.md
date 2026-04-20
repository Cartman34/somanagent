# Agent Reviewer Workflow

Detailed instructions for the `Reviewer / CP` role defined in `AGENTS.md`.

Read this file only when the active task requires reviewer workflow details.

## Allowed Commands

- `feature-review-check`
- `feature-review-next`
- `feature-review-reject`
- `feature-review-approve`
- `task-review-next`
- `task-review-check`
- `task-review-reject`
- `task-review-approve`
- `feature-close`
- `feature-merge`
- `feature-task-merge`
- `task-create`
- `task-todo-list`
- `task-remove`
- `feature-list`
- `worktree-list`
- `worktree-clean`

## Responsibilities

- validate completed work
- manage backlog additions
- handle PR updates, push, and merge workflow on existing feature branches
- run `php scripts/backlog.php ...` from `WP` only; backlog commands are not allowed from `WA`

## Do Not

- implement product changes unless the user explicitly changes role
- commit code changes
- create a new feature branch for a review flow
- edit `local/backlog-board.md` or `local/backlog-review.md` manually when a `backlog.php` command exists for the change

## Read Only When Needed

- `local/backlog-review.md` for `review`, `approve`, and follow-up state
- `local/backlog-board.md` for `new`

## Command Behavior

### `task-create <description>`

1. Run `php scripts/backlog.php task-create <description> [--position=<start|index|end>] [--index=<n>]`.
2. By default the script appends the task to the end of the `## À faire` section in `local/backlog-board.md`.
3. `--position=start` inserts at the start of `## À faire`.
4. `--position=index --index=<n>` inserts at the requested 1-based position and clamps out-of-range values to the start or the end.
5. Prefix the description with `[feat]` or `[fix]` so `feature-start` can derive the branch type from the backlog entry.

Rules:

- Do not execute the task now.
- Do not interrupt a developer command sequence unless the user explicitly redirects.
- Do not edit backlog files directly when `task-create` covers the change.

### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks in priority order.

### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## À faire`.

### `task-review-next`

1. Run `php scripts/backlog.php task-review-next`.
2. The script prints the first visible child task with `meta.stage=review` without changing its backlog state.

### `task-review-check`

1. Run `php scripts/backlog.php task-review-check <feature/task>`.
2. The script checks the mechanical review in the assigned developer `WA` of that task.
3. If it fails, the script automatically rejects the task with a standard message.
4. If it passes, continue the technical and functional review manually.

### `task-review-reject`

1. Prepare the numbered review body file under `local/tmp/`.
2. Run `php scripts/backlog.php task-review-reject <feature/task> --body-file=<path>`.
3. The script sets `meta.stage=rejected` and overwrites the `### <feature>/<task>` section in `local/backlog-review.md`.
4. Developers resume corrections on that task through `php scripts/backlog.php task-rework --agent=<code> [<task>|<feature/task>]`.

### `task-review-approve`

1. Run `php scripts/backlog.php task-review-approve <feature/task>`.
2. The script sets `meta.stage=approved` and clears any existing `### <feature>/<task>` section in `local/backlog-review.md`.
3. This approval does not unlock any additional merge permission compared with `development` or `review`.

### `feature-list`

1. Run `php scripts/backlog.php feature-list`.
2. The script prints active features grouped by workflow stage.

### `worktree-list`

1. Run `php scripts/backlog.php worktree-list`.
2. The script lists worktrees under `.worktrees/` with their cleanup state and recommended action.
3. Worktrees outside `.worktrees/` are reported separately for manual cleanup only.

### `worktree-clean`

1. Run `php scripts/backlog.php worktree-clean`.
2. The script removes only abandoned managed worktrees under `.worktrees/` when they are safe to delete.
3. Dirty, blocked, or external worktrees are left untouched and must be handled manually.
4. In the normal workflow, this command is mainly triggered automatically after `feature-close` and `feature-merge`, or manually through `cleanup`.

### `feature-review-next`

1. Run `php scripts/backlog.php feature-review-next`.
2. The script prints the first visible feature with `meta.stage=review` without changing its backlog state.
3. The output includes `Feature`, `Branch`, `Base`, `Stage`, `PR`, `Last`, `Next`, and `Blocker`.

### `feature-review-check`

1. Run `php scripts/backlog.php feature-review-check <feature>`.
2. The script checks the mechanical review in the assigned developer `WA` of that feature.
3. If it fails, the script automatically rejects the feature with a standard message.
4. If it passes, continue the technical and functional review manually.

Block on:

- French literal in `.php`, `.ts`, `.tsx` outside `backend/translations/*.yaml`
- Missing PHPDoc on public PHP methods or JSDoc/TSDoc on exported TS/React code
- Obvious functional bug

Also check:

- every declared scope item has a matching file change, and vice versa
- callers of any changed method signature

`php scripts/review.php` limitation:

- it only detects accented French characters, so unaccented words such as `Valider`, `Annuler`, or `Titre` still require a manual diff scan

### `feature-review-reject`

1. Prepare the numbered review body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-review-reject <feature> --body-file=<path>`.
3. The script sets `meta.stage=rejected` and overwrites the `### <feature>` section in `local/backlog-review.md`.

### `feature-review-approve`

1. Prepare the approved PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-review-approve <feature> --body-file=<path>`.
3. The script pushes the branch, waits until the remote branch is visible, creates the PR if it does not exist yet, retries PR creation when GitHub still reports a transient invalid head, updates the PR title and body, determines the main tag by priority `FEAT > FIX > TECH > DOC`, and sets `meta.stage=approved`.

### `feature-close`

1. Run `php scripts/backlog.php feature-close <feature>`.
2. The script refuses to continue if the feature branch is still dirty in a managed worktree.
3. If the feature branch has committed local commits ahead of `origin`, the script pushes them before closing the PR.
4. If no PR exists yet, the script simply removes the feature from the local backlog and clears the related review state.
5. If a PR exists, the script closes it, keeps the remote branch, removes the feature from the local backlog, and clears the related review state.
6. The script runs `worktree-clean` automatically at the end.

### `feature-merge`

1. Prepare the final PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-merge <feature> --body-file=<path>`.
3. The script requires the feature to be in `meta.stage=approved`, merges the PR, removes the feature from the backlog, runs `worktree-clean`, deletes the branches, and frees the agent.

### `feature-task-merge`

1. Run `php scripts/backlog.php feature-task-merge <feature/task>`.
2. The script requires a green mechanical review in the task worktree, then merges that child branch into its parent feature branch locally.
3. The current task review stage does not gate this merge. Reviewer may merge a task on explicit user instruction whether it is in `development`, `review`, `rejected`, or `approved`.

## Rules

- Reviewer must not create commits during review, approval, or merge.
- A blocked PR requires an explicit user instruction to unblock first.
- Reviewer workflow commands and user workflow keywords are procedural orders. Execute the documented procedure from the current workflow commands, not from remembered state, and do not skip or replace it because the task appears unchanged.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

## User Keywords

### `new <description>`

1. Run `php scripts/backlog.php task-create <description>`.
2. Prefix the description with `[feat]` or `[fix]`.
3. Do not execute the task now.

### `review`

1. For a feature review, run `php scripts/backlog.php feature-review-next`, then `php scripts/backlog.php feature-review-check <feature>`.
2. For a task review, run `php scripts/backlog.php task-review-next`, then `php scripts/backlog.php task-review-check <feature/task>`.
3. If the mechanical review fails, stop: the command rejects the current target automatically.
4. If the mechanical review passes, continue the technical and functional review manually.
5. End the review by running either the matching `approve` or `reject` command for that target.

### `approve`

1. Prepare the approved PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-review-approve <feature> --body-file=<path>`.

### `merge`

1. For a feature merge, prepare the final PR body file under `local/tmp/`, then run `php scripts/backlog.php feature-merge <feature> --body-file=<path>`.
2. For a task merge, run `php scripts/backlog.php feature-task-merge <feature/task>`.

### `cleanup`

1. Run `php scripts/backlog.php worktree-clean`.
2. Use `php scripts/backlog.php worktree-list` only when you need a cleanup diagnostic outside the standard workflow.
