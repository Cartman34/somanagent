# SoManAgent — Claude Continuity

This file is for session continuity only.

Use `doc/README.md` as the documentation index — it includes a "which doc for which task" table.
Consult doc/ files only when a task requires it. Do not read them proactively.

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
User commands and their exact behaviour:

**`next`**
1. Read the first task from `local/planned-tasks.md`
2. Execute it fully
3. Remove it from `local/planned-tasks.md`
4. Append the result to `local/changes-list.md`
- DO NOT start a second task in the same turn
- DO NOT touch `local/changes-review.md`
- A bugfix discovered during the task stays folded in that task entry — do not add a separate `[FIX]` unless it is a follow-up on already-completed work

**`new <description>`**
1. Append the new task to the end of `local/planned-tasks.md`
- DO NOT interrupt a `next` in progress unless the user explicitly redirects

**`rework`**
1. Read `local/changes-review.md` for pending feedback
2. Apply the needed follow-up changes
3. Append any change made to `local/changes-list.md`
- DO NOT apply feedback blindly — challenge weak, risky, or ambiguous points; ask for clarification if needed
- Additional changes explicitly requested by the user during `rework` must also be added to `local/changes-list.md`

**`review`** → see Workflow Commands → `review`

**`approve`** → see Workflow Commands → `approve`

**`merge`** → see Workflow Commands → `merge`

General rules:
- `next`, `review`, `rework`, `approve`, `merge` are explicit user commands — never auto-chain them, except: `review` auto-proceeds to `approve` when there are no blockers (this is an explicit part of the `review` command, not a chain)
- Encourage the review/rework loop until the review is clean, but never block a user command
- An explicit `approve` must execute even if review blockers are documented
- Any new user process instruction must be persisted in `CLAUDE.md`
- Do not infer state from the chat session alone — read `local/planned-tasks.md`, `local/changes-list.md`, and `local/changes-review.md` as the source of truth
- Keep chat updates concise — do not restate what is already in the local files

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

1. Read `local/changes-list.md` — declared scope
2. Run `php scripts/review.php` — mechanical checklist (French strings, PHPDoc, JSDoc)
3. Each `M` file: `git diff <file>` first; full read only if diff is insufficient
4. Each `??` file: targeted `grep`/`head` only — no Explore agent
5. Write findings to `local/changes-review.md` — no code changes
6. Chat output: "Review ✅" or blocker list only — no verbose report
7. ✅ no blocker → auto-proceed to approve

**`review.php` limitations:** only detects accented characters (U+00C0–U+00FF) as French strings. Does NOT catch unaccented French words (`Valider`, `Commenter`, `Titre`, `Annuler`, etc.). Complement with a manual scan of new visible strings in the diff.

**Block on:**
- French literal in `.php`, `.ts`, `.tsx` outside `backend/translations/*.yaml`
- Missing PHPDoc on public PHP method or JSDoc on exported TS/React symbol
- Obvious functional bug

**Also check:**
- Every declared scope item has a matching file change, and vice versa
- Callers of any method whose signature changed

**`changes-review.md` format:** consumed by another AI running `rework` — write it for machine reading: structured sections, bullet points, blockers as facts. No prose padding, no redundant explanations.

**`review async`:** same as review + approve, with `git checkout main` inserted after push and before PR creation. Pass `--head <branch>` explicitly.

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
- A blocked PR can only be merged after an explicit user instruction to unblock it first.

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

For the detailed conventions, use [`doc/technical/conventions.md`](/home/sowapps/projects/somanagent/doc/technical/conventions.md).

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
