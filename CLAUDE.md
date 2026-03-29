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
- The order of tasks in `local/planned-tasks.md` is the authoritative priority order.
- The user may reorder `local/planned-tasks.md` manually at any time to redefine priorities.
- `next` means: execute the first task from `local/planned-tasks.md`, remove it from that file, then append the result to `local/changes-list.md`.
- `new ...` means: append a new task to the end of `local/planned-tasks.md`.
- If a `next` is already in progress, `new ...` does not interrupt it unless the user explicitly redirects the work.
- `rework` means: read `local/changes-review.md`, resume from the pending review feedback, and apply the needed follow-up changes.
- During `rework`, review feedback is not assumed to be automatically correct: challenge weak or risky requests when needed, and ask for clarification if a point is ambiguous or under-specified.
- During `rework`, any additional change explicitly requested by the user as part of the same follow-up must also be added to `local/changes-list.md`, even if it goes beyond the original review remarks.
- If a completed feature needs a follow-up bugfix, add it to `local/changes-list.md` with prefix `[FIX]`.

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
docker exec somanagent_php php /var/www/backend/bin/console cache:clear
docker exec somanagent_php php /var/www/backend/bin/console somanagent:task:redispatch --latest
docker exec somanagent_php php /var/www/backend/bin/console somanagent:task:redispatch <task-id> --sync
docker exec somanagent_php php /var/www/backend/bin/console somanagent:agent:hello <projectId> <agentId> --message=Salut
docker exec somanagent_php claude auth status
docker exec somanagent_worker claude auth status
docker exec somanagent_node npm run type-check
docker logs somanagent_worker --tail 120
docker exec somanagent_db psql -U somanagent -d somanagent -c "SELECT source, category, level, title, occurred_at FROM log_event ORDER BY occurred_at DESC LIMIT 20;"
```

## Workflow Commands

### `review` / `review async`

1. Read `local/changes-list.md` for the declared scope
2. Run `git status --short` to detect untracked (`??`) files
3. Analyse all modified/added files: coherence, conventions, signatures
4. Write conclusions to `local/changes-review.md`
5. **Never modify code during review** — report only
6. **Never write a detailed report in chat** — only "Review ✅" or a short blocker list
7. If ✅ with no blocker → auto-proceed to approve

**Review quality:**
- Flag any modified/added file outside the declared scope, and any declared item without a matching file
- Grep callers of any method whose signature changes
- **Block on:** non-English source string without justifying comment, missing PHPDoc/JSDoc on public method or exported component, obvious functional bug
- Exception for French strings: messages stored in DB for the in-app log UI may be French → must have `// Stored in DB for the in-app log UI, so the human-facing message stays in French.` on the preceding line
- Every `??` file must be included in the commit or covered by `.gitignore`

**`review async` variant:** same as review + approve, but `git checkout main` is done immediately after the commit, before push and PR creation.

### `approve` (or auto-approval after review ✅)

1. Apply any pending corrections
2. Clean `local/changes-list.md` (empty the Completed section)
3. Clean `local/changes-review.md` (reset to "Aucune review en cours.")
4. `git checkout -b feat/…` (or `fix/…` for `[FIX]` items) if not already on a feature branch
5. `git add . && git commit`
6. `git push -u origin <branch>` (if `review async`: `git checkout main` first, then push)
7. Write PR body with the **Write tool** to `/tmp/pr_body.md` (read first if it already exists), then:
   ```
   php scripts/github.php pr create --title "..." --body-file /tmp/pr_body.md
   ```
   The script reads and deletes the file automatically.

**Branch and title prefix:**
- All `[FIX]` items → `fix/…` branch, `🐛 Bug: …` title, PR body with **Type : Anomalie**
- Feature items → `feat/…` branch

### `merge`

Merge the current open PR: `php scripts/github.php pr merge <number>`, then `git checkout main && git pull`.

## Git Rules

- Always use `git add .` unless specific file staging is needed
- Simple `git push -u origin <branch>` — no token prefix, no `--force` without explicit user instruction
- Never amend a published commit — always create a new one
- Use `php scripts/github.php` for all GitHub operations — never `gh` directly

## Project-specific Rules

- Keep `doc/` up to date with code changes.
- `doc/README.md` is the documentation index and must be updated when a new doc file is added.
- UI text is French.
- Technical source content is English:
  - code
  - PHPDoc/JSDoc/TSDoc
  - comments
  - route names
  - script output
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
