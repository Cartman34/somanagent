# Agents

Single entrypoint for AI agents working on this repository.

Read only this file first. Read additional files only when the active command or task requires them.

## Core Rules

- Work from `~/projects/somanagent` in the WSL native filesystem.
- Do not work from `/mnt/c/...`.
- Use project scripts in `scripts/` first.
- Prefer `php scripts/console.php`, `php scripts/node.php`, `php scripts/logs.php`, `php scripts/db.php`, `php scripts/dev.php`, `php scripts/health.php`, and similar wrappers over raw container commands.
- Use relative paths in commands. Do not rely on `cd` into subfolders.
- Keep chat updates concise.
- Do not infer backlog or review state from chat alone.
- UI text is French, but must go through translation keys.
- Technical source content is English: code, comments, docs in source, PHPDoc/JSDoc/TSDoc, CLI output, commit messages.
- Keep `doc/` up to date when code changes require documentation updates.
- `doc/README.md` is the documentation index. Read it only when documentation is actually needed, then open only the relevant file(s).

## Local Source Of Truth

- Pending backlog: [`local/backlog-board.md`](/home/sowapps/projects/somanagent/local/backlog-board.md)
- Completed work: [`local/backlog-changes.md`](/home/sowapps/projects/somanagent/local/backlog-changes.md)
- Review state: [`local/backlog-review.md`](/home/sowapps/projects/somanagent/local/backlog-review.md)

Rules:

- Files under `local/` are local-only and must not be committed.
- For `local/backlog-board.md`, `local/backlog-changes.md`, and `local/backlog-review.md`, always follow the rules written in each file's `## Règles d'usage` section.
- Every modification to project files must be recorded in `local/backlog-changes.md`.

## Worktrees

- Unless the user explicitly asks otherwise, work on the main workspace.
- When the user asks for isolated parallel work, create and use a dedicated worktree per agent.
- Create agent worktrees under `.worktrees/` inside the main repository so they stay in the same WSL filesystem and remain easy to ignore.
- Use one dedicated branch per agent worktree.
- Name worktrees and branches with the agent identifier when one is provided, for example `agent-01`.
- Keep `.worktrees/` ignored in the root `.gitignore`.

Backlog rules with worktrees:

- `local/backlog-board.md`, `local/backlog-changes.md`, and `local/backlog-review.md` remain the source of truth in the main workspace.
- Do not maintain a parallel backlog state inside a worktree.
- Before taking a new task in a worktree, first resync that worktree with the current `main` branch state and make sure it is clean.
- If the user asks to move a task into `local/backlog-changes.md` before repatriation, edit the file in the main workspace, not inside the worktree.
- After a repatriation, continue on the main workspace until a new task is explicitly started in a worktree.

Implementation rules with worktrees:

- Code changes for the isolated task must be done in the dedicated worktree.
- Avoid modifying the main workspace while the task is still isolated, except for shared coordination changes explicitly requested by the user, such as `.gitignore` or backlog updates in `local/`.
- Before considering the isolated task done, run `php scripts/review.php` in the worktree and fix mechanical blockers within scope.

Repatriation rules:

- Repatriate changes with git-based workflows whenever possible.
- Prefer reviewing the worktree diff, then using git tools such as commit plus `cherry-pick`, generated patch application, or a targeted merge strategy instead of copying files manually.
- Resolve conflicts explicitly and verify the merged result in the main workspace.
- After repatriation, run `php scripts/review.php` in the main workspace and fix mechanical blockers within scope.
- Once repatriation is complete, update `local/backlog-changes.md` in the main workspace according to the relevant file rules.
- Once repatriation is complete, clean the worktree so the task-specific code changes are gone and the worktree is ready to be resynced for the next task.
- Worktree setup symlinks such as `backend/vendor`, `frontend/node_modules`, or similar local dependency links must stay in place when cleaning the worktree.

## Role Selection

Use one active role only.

### Developer

Allowed commands:

- `next`
- `rework`

Default responsibilities:

- implement tasks
- run `php scripts/review.php` after every implementation and fix mechanical blockers within scope
- critically challenge the implementation for gaps, regressions, and convention violations before considering it done
- update docs when required by the code change
- record completed work in `local/backlog-changes.md`

Do not:

- run `review`, `approve`, `merge`, or `new`
- handle PR workflow unless the user explicitly changes role

Read only when needed:

- `local/backlog-board.md` for `next`
- `local/backlog-review.md` for `rework`
- `local/backlog-changes.md` before appending completed work

Command behavior:

#### `next`

1. Read the first task from `local/backlog-board.md`
2. Execute it fully
3. Run `php scripts/review.php` and fix mechanical blockers within scope
4. Challenge the implementation critically for gaps, regressions, and convention violations
5. Stop and ask the user before making out-of-scope corrections
6. Remove the completed task from `local/backlog-board.md` according to that file's rules
7. Append the result to `local/backlog-changes.md`

Rules:

- Do not start a second task in the same turn
- Do not edit `local/backlog-review.md`
- Keep bugfixes discovered within the task folded into the same completed entry unless they are explicit follow-up fixes

#### `rework`

1. Read `local/backlog-review.md`
2. Apply the needed follow-up changes
3. Run `php scripts/review.php` and fix mechanical blockers within scope
4. Challenge the implementation critically for gaps, regressions, and convention violations
5. Stop and ask the user before making out-of-scope corrections
6. Append the result to `local/backlog-changes.md`

Rules:

- Do not apply feedback blindly; challenge weak, risky, or ambiguous review points
- Additional user-requested follow-up changes during `rework` must also be recorded in `local/backlog-changes.md`

### Reviewer / CP

Allowed commands:

- `review`
- `review async`
- `approve`
- `merge`
- `new <description>`

Default responsibilities:

- validate completed work
- manage backlog additions
- handle commit / push / PR / merge workflow

Do not:

- implement product changes unless the user explicitly changes role

Read only when needed:

- `local/backlog-changes.md` for `review` and `approve`
- `local/backlog-review.md` for `review`, `approve`, and follow-up state
- `local/backlog-board.md` for `new`

Command behavior:

#### `new <description>`

1. Append the task to the end of the `## À faire` section in `local/backlog-board.md`

Rules:

- Do not execute the task now
- Do not interrupt a `next` in progress unless the user explicitly redirects

#### `review` / `review async`

1. Read `local/backlog-changes.md`
2. Run `php scripts/review.php`
3. For each modified file, inspect the diff first; read the full file only when the diff is insufficient
4. Manually scan new visible strings in the diff to catch French literals that `php scripts/review.php` can miss when they are not accented
5. Verify declared scope vs actual file changes, conventions, and request alignment
6. Write findings to `local/backlog-review.md`
7. Return only `Review ✅` or a blocker list
8. If there is no blocker, auto-proceed to `approve`

Block on:

- French literal in `.php`, `.ts`, `.tsx` outside `backend/translations/*.yaml`
- Missing PHPDoc on public PHP methods or JSDoc/TSDoc on exported TS/React code
- Obvious functional bug

Also check:

- every declared scope item has a matching file change, and vice versa
- callers of any changed method signature

`php scripts/review.php` limitation:

- it only detects accented French characters, so unaccented words such as `Valider`, `Annuler`, or `Titre` still require a manual diff scan

#### `approve`

1. Keep `local/backlog-changes.md` untouched until the PR description contains the approved changes
2. Reset `local/backlog-review.md` to `Aucune review en cours.`
3. Create a feature or fix branch before the commit if needed
4. Run `git add . && git commit`
5. Run `git push -u origin <branch>`
6. If no PR exists yet for the branch, create it with `php scripts/github.php pr create --title "..." --head <branch> --body-file /tmp/pr_body.md`
7. If a PR already exists for the branch, update that PR so its description includes the approved changes
8. Once the PR exists with the right description, clean the `Réalisé` section in `local/backlog-changes.md`
9. Stay on the feature branch unless the command was `review async`

Rules:

- Use `fix/…` branches and a bug title only when all approved items are `[FIX]`
- `review async` inserts `git checkout main` after push and before PR creation
- An explicit `approve` must execute even if review blockers are documented
- If a PR already exists for the branch, do not create a second PR; edit the existing PR instead

#### `merge`

1. Merge the current open PR with `php scripts/github.php pr merge <number>`
2. Run `git checkout main && git pull`
3. Delete the merged branch on the remote: `git push origin --delete <branch>`
4. Delete the merged branch locally: `git branch -d <branch>`

Rules:

- Never merge a PR whose title contains `[BLOCKED]`
- A blocked PR requires an explicit user instruction to unblock first

## Git Rules

- Always use `git add .` unless specific file staging is needed
- Use `git push -u origin <branch>` with no force unless explicitly requested
- Never amend a published commit
- Use `php scripts/github.php` for GitHub operations instead of `gh`

## Conventions Snapshot

- PHPDoc is required on public PHP methods unless they are truly trivial, and on non-trivial private helpers.
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
