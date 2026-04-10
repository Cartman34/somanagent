# Agents

Single entrypoint for AI agents working on this repository.

Read only this file first. Read additional files only when the active command or task requires them.

## Core Rules

- Work from `~/projects/somanagent` in the WSL native filesystem.
- Do not work from `/mnt/c/...`.
- Use project scripts in `scripts/` first.
- Prefer `php scripts/console.php`, `php scripts/node.php`, `php scripts/logs.php`, `php scripts/db.php`, `php scripts/dev.php`, `php scripts/health.php`, and similar wrappers over raw container commands.
- Use relative paths in commands. Do not rely on `cd` into subfolders.
- For temporary files needed by repo procedures (for example PR body files), write them under `local/tmp/` inside the repository, not `/tmp`.
- Do not repeatedly probe for optional CLI tools across sessions.
- If a tool is known to be unavailable in the current environment, stop trying to use it until the user explicitly asks to install it or confirms it is now available.
- In this repository, treat `rg` as unavailable by default unless the user explicitly asks to install it or confirms it is available again.
- Keep chat updates concise.
- Do not infer backlog or review state from chat alone.
- UI text is French, but must go through translation keys.
- Technical source content is English: code, comments, docs in source, PHPDoc/JSDoc/TSDoc, CLI output, commit messages.
- Keep `doc/` up to date when code changes require documentation updates.
- `doc/README.md` is the documentation index. Read it only when documentation is actually needed, then open only the relevant file(s).
- `doc/technical/openapi.yaml` is the source of truth for the HTTP API contract. Update it in the same change as any backend API route or payload change, keep it hand-written and readable, and keep `x-somanagent-implemented: false` on planned operations not yet implemented.
- Do not take product, architecture, exposure, workflow, or library-choice initiatives that were not explicitly requested when they carry meaningful tradeoffs. Stop and ask the user before introducing them.

## Local Source Of Truth

- Pending backlog: [`local/backlog-board.md`](/home/sowapps/projects/somanagent/local/backlog-board.md)
- Review state: [`local/backlog-review.md`](/home/sowapps/projects/somanagent/local/backlog-review.md)

Rules:

- Files under `local/` are local-only and must not be committed.
- For `local/backlog-board.md` and `local/backlog-review.md`, always follow the rules written in each file's `## Règles d'usage` section.
- Local backlog vocabulary is strict: `À faire` = queued, `En développement` = active on a developer branch, `À relire` = ready for reviewer actions, `Rejetées` = review failed and needs rework, `Approuvées` = reviewer-approved and waiting for merge.
- Local backlog files are not edited manually.
- If a needed backlog transition or backlog mutation is not covered by an existing command, stop and ask the user before proceeding.

## Worktrees

- Developer work in a dedicated worktree is mandatory for every task.
- Create agent worktrees under `.worktrees/` inside the main repository so they stay in the same WSL filesystem and remain easy to ignore.
- Use `WP` for the main workspace and `WA` for one developer agent worktree.
- A `WA` belongs to the developer agent and is treated as ephemeral.
- A branch belongs to the active feature.
- A feature branch must never stay checked out in multiple worktrees at the same time.
- Keep `.worktrees/` ignored in the root `.gitignore`.

Feature identity rules:

1. Every active task is attached to one feature.
2. The canonical identifier is the feature slug.
3. Active backlog entries must use this exact prefix format with no spaces between metadata blocks: `[feature:<slug>][agent:<code>][branch:<type>/<slug>][base:<sha>] ...`
4. `<type>` is `feat` or `fix` on the branch.
5. Every developer commit on a feature branch must start with `[<slug>]`.
6. Review and approval must be scoped from the recorded `base` commit, not from the current `main`.

Command policy:

1. Prefer `php scripts/backlog.php` for the full local workflow.
2. Every developer command on `backlog.php` requires `--agent=<code>`.
3. Reviewer commands on `backlog.php` never use `--agent`.
4. The agent code must never leave local backlog files.
5. Any backlog state change covered by `backlog.php` must go through `backlog.php`, never through a manual file edit.
6. Manual edits to `local/backlog-board.md` or `local/backlog-review.md` are forbidden unless the user explicitly asks for a manual edit outside the scripted workflow.
## Role Selection

Use one active role only.

### Developer

Allowed commands:

- `task-create`
- `task-todo-list`
- `task-remove`
- `task-book-next`
- `task-book-release`
- `feature-start`
- `feature-task-add`
- `feature-assign`
- `feature-unassign`
- `feature-rework`
- `feature-block`
- `feature-unblock`
- `feature-list`
- `feature-status`
- `feature-review-request`

Default responsibilities:

- manage one `WA` identified by the agent code
- reserve tasks, start features, and continue development on the feature branch
- commit on the feature branch with the feature slug prefix
- run `php scripts/review.php` after every implementation and fix mechanical blockers within scope
- critically challenge the implementation for gaps, regressions, and convention violations before considering it ready for review
- update docs when required by the code change
- keep `local/backlog-board.md` in sync with the current stage of the feature through `backlog.php`

Do not:

- run reviewer commands or `merge`
- use raw git or GitHub commands when `backlog.php` provides the workflow step
- start a second visible backlog entry for the same feature
- edit `local/backlog-board.md` or `local/backlog-review.md` manually

Read only when needed:

- `local/backlog-board.md` for feature state
- `local/backlog-review.md` for rework input

Command behavior:

#### `task-create`

1. Run `php scripts/backlog.php task-create <description>`.
2. The script appends the task to the end of `## À faire`.

#### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks and visible reservation metadata.

#### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## À faire`.

#### `task-book-next`

1. Run `php scripts/backlog.php task-book-next --agent=<code> [<feature>]`.
2. Reserve the next task in `## À faire`.
3. If `<feature>` is omitted, let the script derive the feature slug.
4. If the agent already owns one active feature, the reservation reuses that feature slug.

#### `task-book-release`

1. Run `php scripts/backlog.php task-book-release --agent=<code> [<feature>]`.
2. Release the reservation before the feature is started.

#### `feature-start`

1. Prepare the PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-start --agent=<code> --branch-type=<feat|fix> --body-file=<path>`.
3. The script creates the feature branch, pushes it, waits until the remote branch is visible, creates the `[WIP]` PR, moves the feature to `## En développement`, and authorizes development.

#### `feature-task-add`

1. Prepare the PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-task-add --agent=<code> --body-file=<path> --feature-text=<text>`.
3. The script absorbs all tasks reserved by this agent into the current feature and updates the PR body.

#### `feature-assign`

1. Run `php scripts/backlog.php feature-assign --agent=<code> <feature>`.
2. The script reassigns the feature to the agent and prepares the `WA`.

#### `feature-unassign`

1. Run `php scripts/backlog.php feature-unassign --agent=<code> [<feature>]`.
2. The script removes the current agent assignment from the target feature and keeps the feature in its current backlog section.

#### `feature-rework`

1. Read `local/backlog-review.md`.
2. Run `php scripts/backlog.php feature-rework --agent=<code> [<feature>]`.
3. Resume development on the same feature branch from `## Rejetées`.

#### `feature-block`

1. Run `php scripts/backlog.php feature-block --agent=<code> [<feature>]`.
2. The script marks the feature as blocked and keeps the current backlog section.

#### `feature-unblock`

1. Run `php scripts/backlog.php feature-unblock --agent=<code> [<feature>]`.
2. The script removes the blocked flag from the feature and updates the PR title when one exists.

#### `feature-list`

1. Run `php scripts/backlog.php feature-list`.
2. The script prints active features grouped by backlog section.

#### `feature-status`

1. Run `php scripts/backlog.php feature-status [--agent=<code>] [<feature>]`.
2. The script prints `Feature`, `Branch`, `Base`, `Stage`, `Last`, `Next`, and `Blocker`.

#### `feature-review-request`

1. Run `php scripts/backlog.php feature-review-request --agent=<code> [<feature>]`.
2. The script requires a green mechanical review and moves the feature to `## À relire`.

Rules:

- Do not start a second visible feature for the same agent.
- Do not edit local backlog files directly.
- A task is considered done for Developer only when it is committed, mechanically valid, and passed to `## À relire`.
- If a new task is added to an existing feature, keep a single backlog line for that feature and preserve all useful scope details.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

### Reviewer / CP

Allowed commands:

- `feature-review-check`
- `feature-review-reject`
- `feature-review-approve`
- `feature-close`
- `feature-merge`
- `task-create`
- `task-todo-list`
- `task-remove`
- `feature-list`

Default responsibilities:

- validate completed work
- manage backlog additions
- handle PR updates, push, and merge workflow on existing feature branches

Do not:

- implement product changes unless the user explicitly changes role
- commit code changes
- create a new feature branch for a review flow
- edit `local/backlog-board.md` or `local/backlog-review.md` manually when a `backlog.php` command exists for the change

Read only when needed:

- `local/backlog-review.md` for `review`, `approve`, and follow-up state
- `local/backlog-board.md` for `new`

Command behavior:

#### `task-create <description>`

1. Run `php scripts/backlog.php task-create <description>`.
2. The script appends the task to the end of the `## À faire` section in `local/backlog-board.md`.

Rules:

- Do not execute the task now
- Do not interrupt a developer command sequence unless the user explicitly redirects
- Do not edit backlog files directly when `task-create` covers the change.

#### `task-todo-list`

1. Run `php scripts/backlog.php task-todo-list`.
2. The script prints queued tasks and visible reservation metadata.

#### `task-remove`

1. Run `php scripts/backlog.php task-remove <number>`.
2. The script removes the queued task at the given 1-based position from `## À faire`.

#### `feature-list`

1. Run `php scripts/backlog.php feature-list`.
2. The script prints active features grouped by backlog section.

#### `feature-review-check`

1. Run `php scripts/backlog.php feature-review-check <feature>`.
2. The script checks the mechanical review in reviewer context.
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

#### `feature-review-reject`

1. Prepare the numbered review body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-review-reject <feature> --body-file=<path>`.
3. The script moves the feature to `## Rejetées` and overwrites the `### <feature>` section in `local/backlog-review.md`.

#### `feature-review-approve`

1. Prepare the approved PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-review-approve <feature> --body-file=<path>`.
3. The script pushes the branch, waits until the remote branch is visible, retries PR creation when GitHub still reports a transient invalid head, updates the PR title and body, determines the main tag by priority `FEAT > FIX > TECH > DOC`, and moves the feature to `## Approuvées`.

#### `feature-close`

1. Run `php scripts/backlog.php feature-close <feature>`.
2. The script refuses to continue if the feature branch is still dirty in a managed worktree.
3. If the feature branch has committed local commits ahead of `origin`, the script pushes them before closing the PR.
4. The script closes the PR if it exists, keeps the remote branch, removes the feature from the local backlog, and clears the related review state.

#### `feature-merge`

1. Prepare the final PR body file under `local/tmp/`.
2. Run `php scripts/backlog.php feature-merge <feature> --body-file=<path>`.
3. The script requires the feature to be in `## Approuvées`, merges the PR, deletes the branches, removes the feature from the backlog, and frees the agent.

Rules:

- Reviewer must not create commits during review, approval, or merge.
- A blocked PR requires an explicit user instruction to unblock first.
- If a needed backlog action is missing from `backlog.php`, stop and ask the user instead of editing the backlog manually.

## Git Rules

- Always use `git add .` unless specific file staging is needed
- Use `php scripts/backlog.php` before falling back to raw git or GitHub commands for workflow steps covered by that script.
- Developers do not push manually.
- Reviewers may push existing feature branches with `git push -u origin <branch>` when required by the workflow and no script wrapper exists yet.
- Never amend a published commit
- Use `php scripts/github.php` for GitHub operations instead of `gh`

## Conventions Snapshot

- PHPDoc is required on public PHP methods unless they are truly trivial, and on non-trivial private helpers. PHPDoc must describe what the class or callable does, not merely restate types or generic boilerplate.
- JSDoc/TSDoc is required on exported TypeScript/React code and on non-trivial internal helpers.
- When a Symfony method has both PHPDoc and attributes, keep the order: PHPDoc, attribute, method declaration.
- For detailed conventions, read [`doc/technical/conventions.md`](/home/sowapps/projects/somanagent/doc/technical/conventions.md) only when needed.

## Runtime Notes

- Frontend dev URL: `http://localhost:5173`
- API through Vite proxy: `/api/...`
- Claude CLI auth source of truth is WSL
- Sync auth with `php scripts/claude-auth.php sync`
- Re-auth with `php scripts/claude-auth.php login`
- Health endpoint: `GET /api/health/claude-cli-auth`

Useful checks:

```bash
php scripts/console.php cache:clear
php scripts/claude-auth.php status
php scripts/node.php type-check
php scripts/logs.php worker --tail 120
php scripts/db.php query "SELECT source, category, level, title, occurred_at FROM log_event ORDER BY occurred_at DESC LIMIT 20;"
```
