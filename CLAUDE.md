# SoManAgent — Claude Continuity

This file is for session continuity only.

Use `doc/` as the source of truth for product, technical and development documentation:
- index: [`doc/README.md`](/home/sowapps/projects/somanagent/doc/README.md)
- architecture and conventions: [`doc/technical/architecture.md`](/home/sowapps/projects/somanagent/doc/technical/architecture.md)
- API: [`doc/technical/api.md`](/home/sowapps/projects/somanagent/doc/technical/api.md)
- entities: [`doc/technical/entities.md`](/home/sowapps/projects/somanagent/doc/technical/entities.md)
- scripts: [`doc/development/scripts.md`](/home/sowapps/projects/somanagent/doc/development/scripts.md)
- Symfony commands: [`doc/development/commands.md`](/home/sowapps/projects/somanagent/doc/development/commands.md)

## Working Directory

- The project must run from `~/projects/somanagent` in the WSL native filesystem.
- Do not work from `/mnt/c/...`.
- Alert the user if the project is not running from WSL native storage.
- Do not `cd` into subfolders just to run project commands.

## Session Continuity

### Active local workflow

- Pending tasks are tracked in [`local/planned-tasks.md`](/home/sowapps/projects/somanagent/local/planned-tasks.md).
- Completed work is tracked in [`local/changes-list.md`](/home/sowapps/projects/somanagent/local/changes-list.md).
- Review notes are tracked in [`local/changes-review.md`](/home/sowapps/projects/somanagent/local/changes-review.md).
- These three local files are the continuity source of truth for backlog, completed work and review follow-up.
- The order of tasks in `local/planned-tasks.md` is the authoritative priority order.
- The user may reorder `local/planned-tasks.md` manually at any time to redefine priorities.
- User may require some instructions, they are listed below with behavior
- `next` means: execute the first task from `local/planned-tasks.md`, remove it from that file, then append the result to the end of `local/changes-list.md`.
- `new ...` means: append a new task to the end of `local/planned-tasks.md`.
- If a `next` is already in progress, `new ...` does not interrupt it unless the user explicitly redirects the work.
- `rework` means: read `local/changes-review.md`, resume from the pending review feedback, and apply the needed follow-up changes.
- During `rework`, review feedback is not assumed to be automatically correct: challenge weak or risky requests when needed, and ask for clarification if a point is ambiguous or under-specified.
- During `rework`, any additional change explicitly requested by the user as part of the same follow-up must also be added to `local/changes-list.md`, even if it goes beyond the original review remarks.
- If a completed feature needs a follow-up bugfix, add it to `local/changes-list.md` with prefix `[FIX]`.
- `review` means: analyse the current work, write the review findings into `local/changes-review.md`, and use that same file as the source for any later `rework`.
- Any new user process instruction that changes how work should be tracked or executed must be persisted in `CLAUDE.md`.
- `next`, `review`, and `rework` are explicit user commands, not an automatic gate sequence.
- Encourage the review/rework loop until the review is clean, but never block a user command just because another command would be preferable.
- An explicit user command `approve` must not be blocked by review findings; if the user requests `approve`, execute the approval workflow even if review blockers remain documented.
- Do not infer workflow state only from the current chat session: deduce the valid next command from the effective contents of `local/planned-tasks.md`, `local/changes-list.md`, and especially `local/changes-review.md`, because these files may have been updated outside the current session.
- Keep chat updates concise and do not restate information that is already available in the local backlog tracking files unless it is necessary for a decision or blocker.
- A bugfix discovered while implementing the current task must stay folded into that same task in `local/changes-list.md`; do not add a separate `[FIX]` entry unless it is a follow-up on already completed work or you are explicitly extending the existing task entry.

### Local-only files

- Files under `local/` are intentionally local and should not be committed.
- They exist for continuity, backlog tracking and review follow-up.

### Runtime environment

- Frontend dev URL: `http://localhost:5173`
- API through Vite proxy: `/api/...`
- Docker containers:
  - `somanagent_php`
  - `somanagent_worker`
  - `somanagent_node`
  - `somanagent_nginx`
  - `somanagent_db`
  - `somanagent_redis`

### Claude CLI auth

- WSL Claude auth is the source of truth.
- Sync it into Docker with `php scripts/claude-auth.php sync`.
- Re-auth in WSL and sync with `php scripts/claude-auth.php login`.
- Shared Docker auth paths:
  - `./.docker/claude/shared/.claude`
  - `./.docker/claude/shared/.claude.json`
- Runtime home for Claude auth inside containers: `/claude-home`
- Health endpoint: `GET /api/health/claude-cli-auth`

### Useful verification commands

```bash
php scripts/console.php cache:clear
php scripts/console.php somanagent:task:redispatch --latest
php scripts/console.php somanagent:agent:hello <projectId> <agentId> --message="Hello"
php scripts/claude-auth.php status
php scripts/node.php type-check
php scripts/logs.php worker --tail 120
php scripts/db.php query "SELECT source, category, level, title, occurred_at FROM log_event ORDER BY occurred_at DESC LIMIT 20;"
```

## Workflow Commands

### `review` / `review async`

1. Read `local/changes-list.md` for the declared scope
2. Run `git status --short` to detect untracked (`??`) files
3. Analyse all modified/added files: coherence, conventions, signatures
4. Write conclusions to `local/changes-review.md`
5. **Never modify code during review** — report only
6. The file `local/changes-review.md` is the review deliverable used later by `rework`
7. **Never write a detailed report in chat** — only "Review ✅" or a short blocker list
8. If ✅ with no blocker → auto-proceed to approve

**Review quality:**
- Flag any modified/added file outside the declared scope, and any declared item without a matching file
- Grep callers of any method whose signature changes
- **Block on:** French string outside a `backend/translations/*.yaml` file, missing PHPDoc/JSDoc on public method or exported component, obvious functional bug
- French strings are only allowed inside translation files — any French literal in `.php`, `.ts`, `.tsx` or other source files is a translation migration gap (see [`doc/technical/translations.md`](doc/technical/translations.md))
- Every `??` file must be included in the commit or covered by `.gitignore`

**`review async` variant:** same as review + approve, but `git checkout main` is done immediately after the commit — before push and PR creation. `--head <branch>` is passed explicitly so the script no longer depends on the current branch.

### `approve` (or auto-approval after review ✅)

1. Apply any pending corrections
2. Keep `local/changes-list.md` untouched until the PR is created and its description contains these changes
3. Clean `local/changes-review.md` (reset to "Aucune review en cours.")
4. `git checkout -b feat/…` (or `fix/…` for `[FIX]` items) if not already on a feature branch — **before the commit**
5. `git add . && git commit`
6. `git push -u origin <branch>`
7. Write PR body with the **Write tool** to `/tmp/pr_body.md` (read first if it already exists), then:
   ```
   php scripts/github.php pr create --title "..." --head <branch> --body-file /tmp/pr_body.md
   ```
   The script reads and deletes the file automatically.
8. Once the PR is created with a description that contains the approved changes, clean `local/changes-list.md` (empty the Completed section)
9. **Stay on the feature branch** — do NOT `git checkout main` unless `review async` was requested.

**`review async` only:** insert `git checkout main` between steps 6 and 7.

**Branch and title prefix:**
- All `[FIX]` items → `fix/…` branch, `🐛 Bug: …` title, PR body with **Type : Anomalie**
- Feature items → `feat/…` branch

### `merge`

Merge the current open PR: `php scripts/github.php pr merge <number>`, then `git checkout main && git pull`.

Blocking rule:
- If a PR title contains `[BLOCKED]`, it must never be merged.
- A blocked PR can only be merged after an explicit user instruction to unblock or merge it anyway.

## Git Rules

- Always use `git add .` unless specific file staging is needed
- Simple `git push -u origin <branch>` — no token prefix, no `--force` without explicit user instruction
- Never amend a published commit — always create a new one
- Use `php scripts/github.php` for all GitHub operations — never `gh` directly

## Project-specific Rules

- Keep `doc/` up to date with code changes.
- `doc/README.md` is the documentation index and must be updated when a new doc file is added.
- Always use project scripts in `scripts/` first when they cover the need.
- Prefer `php scripts/console.php ...`, `php scripts/logs.php ...`, `php scripts/node.php ...`, `php scripts/db.php ...`, `php scripts/dev.php ...`, `php scripts/health.php ...` and similar wrappers over direct `docker exec`, `bin/console`, or raw container commands.
- Only fall back to direct Docker or container commands when no project script exists for that operation.
- This rule is also about efficiency: using the project wrappers reduces command verbosity and unnecessary token usage.
- UI text is French, but must go through translation keys — never hardcode French strings directly in `.php`, `.ts`, or `.tsx` source files.
- During development, any new user-facing string must use a Symfony translation key (backend) or the equivalent translation mechanism (frontend) instead of a French literal in source code.
- Symfony commands, CLI help, and console output are English.
- User-provided command payloads may still be French when they represent business content, for example a chat message sent to an agent.
- Technical source content is English:
  - code
  - PHPDoc/JSDoc/TSDoc
  - comments
  - route names
  - script output
  - command descriptions, argument help, option help, and console UI output
  - commit messages
- For project files, use relative paths in commands and do not rely on `cd` into subdirectories.

## Project Conventions Snapshot

For the detailed conventions, use [`doc/technical/architecture.md`](/home/sowapps/projects/somanagent/doc/technical/architecture.md).

Important reminders:
- PHPDoc is required on public PHP methods unless they are truly trivial, and on non-trivial private helpers.
- JSDoc/TSDoc is mandatory on exported TypeScript/React code and on non-trivial internal helpers.
- When a Symfony method has both PHPDoc and attributes such as `#[Route(...)]`, keep the order:
  - PHPDoc
  - attribute
  - method declaration

## Diagnostics Note

- For log investigations, prefer querying PostgreSQL directly in `somanagent_db` rather than relying only on container stdout.

## Current Follow-up Note

- After the current ticket/workflow UI rework, the next technical cleanup must cover:
  - remove the remaining ticket-level resume/redispatch fallback still based on `StoryExecutionService`
  - stop using `Ticket.status` and `Ticket.progress` as if they represented workflow progression in async agent execution paths
  - enforce the “project requires a workflow” invariant consistently at model/migration/API typing level, not only in service/UI validation
