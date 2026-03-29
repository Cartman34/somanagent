# SoManAgent — Context for Claude Code

## Project Overview

**SoManAgent** is an agent orchestration platform that manages autonomous AI agents, their skills, workflows, and team assignments.

**Current Phase:** UX Sprints 1–3 complete, chat/agent project interactions in place, and a first centralized logging foundation is implemented. Immediate next work is around lead-tech planning robustness, log retry clarity, and task rework UX.

---

## Tech Stack

| Layer | Technology | Notes |
|---|---|---|
| **Frontend** | React 18 + TypeScript + Vite | React Query (TanStack) for data fetching, Tailwind CSS |
| **Backend** | PHP 8.4-FPM + Symfony 7 | Doctrine ORM, REST API, Psalm static analysis |
| **Database** | PostgreSQL 16 | Health-checked, auto-migrations via Doctrine |
| **DevOps** | Docker Compose + WSL 2 | 6 services: php, nginx, db, node, redis, worker |
| **CLI Scripts** | PHP (OOP pattern) | Terminal output helpers, Bootstrap pattern, exception-based errors |

---

## Critical Performance Note

**The project MUST run from `~/projects/somanagent` in WSL native filesystem. This is your real working directory in the WSL, use command, write, read from this path.**
**You don't need to change directory with `cd` between these paths**
**Alert user if you are not in the WSL**

**Why:** Docker bind mounts from `/mnt/c/...` (Windows NTFS accessed through WSL) use the 9P protocol over Hyper-V virtio, causing 5-20x slower I/O. WSL native ext4 gives near-native Linux speed.

**Migration:** If ever cloned to `/mnt/c/...`, run `bash scripts/wsl-migrate.sh --dest {path}` with `{path}`, your main working path, to copy to WSL.

---

## Directory Structure

```
somanagent/                      # Project Root
├── frontend/                    # React SPA
│   └── src/
│       ├── api/                 # Axios clients (projects, teams, agents, roles, skills, workflows, tasks, features, chat, tokens, health)
│       ├── components/
│       │   ├── layout/          # Sidebar, TopBar (themed with CSS variables)
│       │   └── ui/              # Modal, ConfirmDialog, EmptyState, PageHeader, etc.
│       ├── pages/               # DashboardPage, ProjectsPage, ProjectDetailPage (7-tab hub), TeamsPage, AgentsPage, RolesPage, SkillsPage, WorkflowsPage, TasksPage, FeaturesPage, ChatPage, TokensPage, AuditPage
│       ├── pages/LogsPage.tsx   # Centralized log occurrences + events diagnostic UI
│       ├── hooks/               # useTheme (localStorage-persisted theme switching)
│       └── index.css            # Tailwind + theme tokens (Terminal default) + gray remapping
├── backend/                     # Symfony REST API
│   ├── src/
│   │   ├── Controller/          # REST endpoints (Projects, Teams, Agents, Roles, Skills, Workflows, Tasks, Features, Chat, Tokens, Audit, Health, Logs)
│   │   ├── Entity/              # Doctrine ORM entities
│   │   ├── Service/             # Business logic (ProjectService, WorkflowService, etc.)
│   │   ├── Adapter/             # External integrations (Claude CLI, GitHub, etc.)
│   │   └── Enum/                # AuditAction, ConnectorType, etc.
│   ├── migrations/              # Doctrine auto-generated migrations
│   └── bin/console              # Symfony CLI tool
├── docker/
│   ├── php/                     # PHP 8.4-FPM + Composer + Claude CLI binary
│   ├── nginx/                   # Reverse proxy
│   ├── node/                    # Node.js 20 for Vite dev server
│   └── data/postgres/           # PostgreSQL data (excluded from Docker context via .dockerignore)
├── scripts/
│   ├── setup.php                # Full initial setup (checks env, Docker, Composer, migrations, npm)
│   ├── dev.php                  # Start/stop Docker Compose
│   ├── migrate.php              # Run Doctrine migrations
│   ├── logs.php                 # Stream container logs
│   ├── health.php               # Check API + connectors health
│   ├── console.php              # Run Symfony bin/console commands
│   ├── check-php.sh             # Verify PHP 8.4 (auto-installs if missing on Ubuntu)
│   ├── wsl-migrate.sh           # Copy project to WSL native filesystem
│   └── src/
│       ├── Application.php      # Main entry point (boots WSL redirect, exposes Console, runs subprocesses)
│       ├── Console.php          # Terminal output (CRLF mode for WSL pipe to Windows terminal)
│       ├── Bootstrap.php        # (Deprecated — replaced by Application)
│       ├── Environment.php      # OS detection + path utilities
│       └── Exception/           # WslRequiredException, PhpNotAvailableException
├── skills/                      # Skill definitions (YAML + SKILL.md files)
├── .env.example                 # Environment template
├── docker-compose.yml           # 6 services + health checks
├── .dockerignore                # Excludes docker/data/, node_modules/, vendor/, var/cache/
└── CLAUDE.md                    # This file
```

---

## Session Continuity

Use this section to resume work quickly after reopening Claude.

### Active local workflow

- Pending tasks are tracked in [`local/planned-tasks.md`](/home/sowapps/projects/somanagent/local/planned-tasks.md).
- Completed work is tracked in [`local/changes-list.md`](/home/sowapps/projects/somanagent/local/changes-list.md).
- The order of tasks in `local/planned-tasks.md` is the authoritative priority order.
- The user may reorder `local/planned-tasks.md` manually at any time to redefine priorities.
- `next` means: execute the first task from `local/planned-tasks.md`, remove it from that file, then append the result to `local/changes-list.md`.
- `new ...` means: append a new task to the **end** of `local/planned-tasks.md`; if the user wants a different priority, they can reorder the file afterward.
- `rework` means: read `local/changes-review.md`, resume from the pending review feedback, and apply the needed follow-up changes.
- During `rework`, review feedback is not assumed to be automatically correct: challenge weak or risky requests when needed, and ask for clarification if a point is ambiguous or under-specified.
- During `rework`, any additional change explicitly requested by the user as part of the same follow-up must also be added to `local/changes-list.md`, even if it goes beyond the original review remarks.
- If a completed feature needs a follow-up bugfix, add it to `local/changes-list.md` with prefix `[FIX]`.
- Review notes from the user are expected in `local/changes-review.md` when present.

### Local-only files

- Files under `local/` are intentionally local and should not be committed.
- The helper directory exists for session continuity and ad hoc working notes.

### Base URLs and environment

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
- Sync it into Docker with `php scripts/claude-auth.php sync` or re-auth + sync with `php scripts/claude-auth.php login`.
- Claude CLI auth is shared into containers through:
  - `./.docker/claude/shared/.claude`
  - `./.docker/claude/shared/.claude.json`
- Runtime home used for CLI auth inside containers: `/claude-home`
- Health endpoint for auth: `GET /api/health/claude-cli-auth`

### Recent functional additions

- Project agent sheet supports:
  - viewing role and skills
  - reading skill content
  - chatting with the agent
  - project-level message history with response/error persistence
- Agent hello is available:
  - from backend CLI
  - from the UI quick action
- Project tabs persist across refresh through `?tab=...`
- Project detail also supports deep links:
  - `?task=<uuid>` opens the task drawer
  - `?agent=<uuid>` opens the agent sheet
- Ticket/task detail now supports:
  - markdown rendering
  - agent questions/comments
  - explicit replies to a comment
  - resume/relaunch flows
- Logs UI exists at `/logs` with:
  - occurrence list
  - filters
  - occurrence detail with event history
  - quick navigation to related project/task/agent context

### Recent technical additions

- Centralized logging foundation:
  - `log_event`
  - `log_occurrence`
  - `RequestCorrelationService`
  - `LogService`
  - `LogController`
- Current log coverage:
  - backend HTTP exceptions
  - backend runtime dispatch logs
  - worker execution start/error logs
  - frontend runtime/API failures via `/api/logs/events`
  - infra degradation signals from connector and Claude CLI auth health endpoints
- Current limitation:
  - worker retries are not yet clearly distinguished in the logs UI

### Most recent verified fixes

- `PlanningTask` is now a real PSR-4 class in [`backend/src/ValueObject/PlanningTask.php`](/home/sowapps/projects/somanagent/backend/src/ValueObject/PlanningTask.php).
- Lead-tech planning flow was verified end-to-end in sync mode:
  - planning JSON parses correctly
  - branch name is applied
  - subtasks are created
  - dependencies are created
  - story moves to `development` when `needsDesign=false`
- Replanning now replaces previous generated subtasks instead of stacking duplicates.
- `TaskService::failExecution()` now resets progress to `0` when moving a failed execution back to backlog.

### Current open priorities

1. Harden lead-tech planning against invalid JSON outputs, especially invalid `dependsOn`.
2. Clarify retries vs first execution in centralized logs using `trace_ref` and retry metadata.
3. Implement “send task back to a replayable agent step” UX and persistence.

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

### Diagnostics note

- For log investigations, prefer querying the database directly from `somanagent_db` rather than relying only on container stdout.
- A dedicated helper command/script for DB log extraction is planned and not implemented yet.

---

## UI Theme System

**Active Theme:** Terminal (dark green monospace)
**Available Themes:** Terminal, Slate, Obsidian, Aurora, Neo, Chalk
**Theme Switching:** Dropdown in TopBar (persists to localStorage via `useTheme` hook)

### How Theming Works

1. **CSS Variables** defined per theme in `frontend/src/index.css`:
   - `:root` = Terminal (default)
   - `[data-theme="slate"]` = Slate, etc.

2. **Un-layered CSS overrides** (after `@tailwind utilities`) remap Tailwind gray utilities to theme variables:
   - `text-gray-900` → `var(--text)`
   - `bg-white` → `var(--surface)`
   - `border-gray-200` → `var(--border)`
   - This makes ALL components auto-themed without per-file edits

3. **Semantic color tokens:**
   - `--bg`, `--surface`, `--surface2` (backgrounds)
   - `--text`, `--muted` (text)
   - `--brand`, `--brand-hover`, `--brand-dim` (accent)
   - `--border` (borders)
   - `--shadow`, `--radius`, `--font-body` (other)

---

## Application Architecture

### PHP Scripts Pattern (OOP + Exceptions)

All public scripts follow this pattern:

```php
require_once __DIR__ . '/src/Application.php';

try {
    $app = new Application();
    $app->boot();  // Handles WSL redirect (may exit), creates Console
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$c = $app->console;
$c->step('Doing something');
$code = $app->runCommand('docker compose up -d');
$c->ok('Done');
```

**Key Classes:**
- `Application` - Entry point, WSL bootstrap, subprocess executor
- `Console` - Terminal output with auto-detected CRLF (WSL pipe mode)
- `Environment` - OS detection, path conversion, filesystem checks
- Exceptions instead of `exit()` calls mid-function

### Subprocess Execution

`Application::runCommand($cmd)` is used instead of `passthru()`:
- **Normal mode (Linux TTY):** Delegates to `passthru()` for real-time interaction
- **WSL pipe mode:** Uses `popen()` + CRLF conversion so subprocess output renders correctly in Windows terminal (fixes the "staircase" effect)

---

## Frontend Architecture

### React Query (TanStack)
- `useQuery()` for GET operations
- `useMutation()` for POST/PUT/DELETE
- `useQueryClient().invalidateQueries()` after mutations for cache coherence
- Optimistic updates where applicable

### Routes
- Main routes: `/projects`, `/teams`, `/agents`, `/skills`, `/workflows`, `/audit`, `/`
- Sub-routes for detail views: `/projects/:id`, `/agents/:id/edit`, etc.
- Dynamic route navigation with `useNavigate()`

### Error Handling
- Try-catch in effect hooks
- Error boundaries for component safety
- User-facing error messages via `ErrorMessage` component or console toasts

---

## Backend Architecture

### REST Endpoints
All endpoints return JSON, versioned if needed.

**Implemented:**
- `GET /api/health`, `GET /api/health/connectors`, `GET /api/health/claude-cli-auth`
- `GET /api/logs/occurrences`, `GET /api/logs/occurrences/{id}`
- `GET /api/projects`, `POST /api/projects`, `GET /api/projects/{id}`, `PUT /api/projects/{id}`, `DELETE /api/projects/{id}`
- `GET /api/projects/{id}/audit`, `GET /api/projects/{id}/tokens`
- `POST /api/projects/{id}/modules`, `PUT /api/projects/modules/{id}`, `DELETE /api/projects/modules/{id}`
- `GET /api/projects/{projectId}/tasks`, `POST /api/projects/{projectId}/tasks`, `POST /api/projects/{projectId}/requests`
- `GET /api/tasks/{id}`, `PUT /api/tasks/{id}`, `DELETE /api/tasks/{id}`
- `PATCH /api/tasks/{id}/status`, `PATCH /api/tasks/{id}/progress`, `PATCH /api/tasks/{id}/priority`
- `POST /api/tasks/{id}/validate`, `POST /api/tasks/{id}/reject`, `POST /api/tasks/{id}/request-validation`
- `POST /api/tasks/{id}/story-transition`, `GET /api/tasks/{id}/execute`, `POST /api/tasks/{id}/execute`
- `GET /api/teams`, `POST /api/teams`, `GET /api/teams/{id}`, `PUT /api/teams/{id}`, `DELETE /api/teams/{id}`
- `POST /api/teams/{id}/agents`, `DELETE /api/teams/{id}/agents/{agentId}`
- `GET /api/agents`, `POST /api/agents`, `GET /api/agents/{id}`, `PUT /api/agents/{id}`, `DELETE /api/agents/{id}`
- `GET /api/agents/{id}/status` - Derived runtime status (idle/working/error)
- `GET /api/roles`, `POST /api/roles`, `GET /api/roles/{id}`, `PUT /api/roles/{id}`, `DELETE /api/roles/{id}`
- `POST /api/roles/{id}/skills`, `DELETE /api/roles/{id}/skills/{skillId}`
- `GET /api/skills`, `POST /api/skills`, `POST /api/skills/import`, `PUT /api/skills/{id}/content`, `DELETE /api/skills/{id}`
- `GET /api/workflows`, `POST /api/workflows`, `PUT /api/workflows/{id}`, `DELETE /api/workflows/{id}`, `POST /api/workflows/{id}/validate`
- `GET /api/projects/{projectId}/features`, `POST /api/projects/{projectId}/features`
- `GET /api/features/{id}`, `PUT /api/features/{id}`, `DELETE /api/features/{id}`
- `GET /api/projects/{projectId}/chat/{agentId}`, `POST /api/projects/{projectId}/chat/{agentId}`
- `GET /api/tokens/summary`, `GET /api/tokens/agents/{agentId}`
- `GET /api/audit` - Global paginated audit log

### Services
- `ProjectService`, `TeamService`, `AgentService`, `SkillService`, `WorkflowService`
- Encapsulate business logic, call `AuditService` for changes
- Return Entities or DTOs (not raw DB)

### Database
- Doctrine ORM with auto-migrations
- Health check via `pg_isready` in docker-compose.yml
- `PGDATA: /var/lib/postgresql/data/pgdata` workaround for Windows volume mount issue

---

## Development Workflow

### Initial Setup (WSL)

```bash
cd ~/projects/somanagent
php scripts/setup.php
```

Checks:
1. PHP 8.4 availability (installs if missing)
2. .env file exists (copies from .env.example if not)
3. Docker Compose up + build
4. PostgreSQL health
5. **Composer install** (PHP dependencies)
6. Doctrine migrations
7. npm install (frontend)

### Daily Development

```bash
# Start services
php scripts/dev.php

# View logs
php scripts/logs.php php    # or node, db, nginx
php scripts/logs.php db --tail 50

# Run migrations
php scripts/migrate.php

# Check health
php scripts/health.php

# Run Symfony commands
php scripts/console.php doctrine:make:migration
php scripts/console.php cache:clear

# Stop services
php scripts/dev.php --stop
```

### Frontend Dev Server

Runs inside Docker at `http://localhost:5173` with hot reload.

```bash
# From host (WSL):
php scripts/dev.php   # npm run dev starts automatically in container
```

### Backend Testing

```bash
# Inside PHP container:
php scripts/console.php tests:run
# or via PHPUnit directly
docker compose exec php vendor/bin/phpunit
```

---

## Known Issues & Solutions

| Issue | Root Cause | Solution |
|---|---|---|
| 9-second API response times | Docker bind mount from `/mnt/c/...` via 9P protocol | Migrate to WSL native filesystem (`bash scripts/wsl-migrate.sh`) |
| Staircase display in terminal | Bare `\n` from WSL subprocesses through Windows terminal pipe | `Application::runCommand()` detects CRLF mode, converts output |
| Text not themed in older pages | Hardcoded Tailwind gray classes override CSS variables | Un-layered CSS overrides in index.css remap gray utilities |
| PostgreSQL "directory not empty" error on Windows | `initdb` fails if mount point has hidden files | `PGDATA: /var/lib/postgresql/data/pgdata` subdirectory workaround |
| PHP prompt fails when called non-interactively | TTY not available when launched via `passthru()` | `check-php.sh` detects `[ -t 0 ]` and auto-installs without prompting |

---

## Code Style & Conventions

### PHP
- **Namespace:** `App\Controller`, `App\Service`, `App\Entity`, etc.
- **Naming:** Uppercase class names, camelCase methods/properties
- **Doctrine Attributes:** Entities use `#[ORM\...]` attributes, not XML
- **Exceptions:** Throw `\RuntimeException` or domain-specific exceptions; never `exit()` inside methods (let the script decide)
- **OOP:** Classes are final by default unless inheritance is intentional; favor composition
- **Type Hints:** Full return types (`public function foo(): string`)
- **PHPDoc:** Public methods should have a PHPDoc block unless the method is truly trivial and fully obvious from its name/signature/context. Private methods should also get PHPDoc when their role, assumptions, side effects, or return contract are not immediately obvious. PHPDoc must add useful intent and contract information, not restate the code mechanically line by line. Use `@param` / `@return` / `@throws` when they clarify something non-obvious.

### React/TypeScript
- **Components:** Default exports, PascalCase naming
- **Hooks:** Start with `use`, e.g., `useQuery`, `useTheme`
- **Props:** Interface `Props` per component
- **State:** React hooks (`useState`, `useEffect`, `useContext`)
- **JSDoc/TSDoc:** Treat it as mandatory for TypeScript/React code. Every exported function, component, hook, utility and every non-trivial internal helper must have a JSDoc/TSDoc comment. The reader must understand the role, inputs, outputs and important behavior without opening the implementation body.
- **Styling:** Tailwind utility classes + custom `.card`, `.btn-primary`, `.badge-*` classes
- **Files:** One component per file unless tightly coupled
- **JSDoc:** For components specifically, the comment must make the role, key props and important behaviour understandable **without reading the JSX**.

### Encoding
- **All files must be UTF-8** — no BOM, no Latin-1 or Windows-1252. This applies to PHP, TypeScript, Markdown, JSON, YAML, shell scripts, and any other text file in the project. If a file contains corrupted bytes (e.g. `\xb7` for `·`, `\x97` for `—`), fix them before committing.

### CSS
- **Layered:** `@layer base`, `@layer components`, `@layer utilities` for Tailwind integration
- **Un-layered:** Custom overrides (highest priority) placed after `@tailwind utilities`
- **Variables:** All colors, spacing, fonts defined as CSS custom properties
- **Selectors:** Simple class selectors, avoid deep nesting

---

## Language Convention

- **UI labels, page titles, button text:** French (the application's target language is French)
- **Everything technical — code, PHPDoc/JSDoc, CLAUDE.md, script output, routes, error messages, comments, commit messages:** English

This applies regardless of the language used when talking to Claude. When in doubt: if it appears in the UI → French; if it appears in the source or terminal → English.

---

## Claude Scripts (`scripts/claude/`)

These scripts are designed to reduce token consumption. **Use them systematically before reading source files directly.**

| Script | Purpose | Replaces |
|---|---|---|
| `php scripts/claude/db-schema.php` | Schema of all Doctrine entities | Reading 10+ Entity files |
| `php scripts/claude/api-routes.php` | All REST routes | Grepping Controllers |
| `php scripts/claude/frontend-map.php` | Routes, pages and API clients | Glob + reading App.tsx + pages |
| `php scripts/claude/grep-usage.php <term>` | Search a term across the codebase | Repeated grep calls |
| `php scripts/claude/status.php` | Docker, migrations, schema, git | Multiple manual commands |

Common options: `--json` (db-schema, api-routes, frontend-map), `--backend`/`--frontend` (grep-usage), `--context N` (grep-usage).

### Maintenance rule

**These scripts parse source files dynamically** — they do not need to be updated when you modify entities, controllers, or pages.

**Exception:** if you add a new directory for controllers, entities, or pages outside the default paths (`backend/src/Entity/`, `backend/src/Controller/`, `frontend/src/pages/`), update the paths in the relevant scripts.

---

## Notes for Claude

1. **Use the Application/Console pattern** for new scripts:
   - No `exit()` inside functions, throw exceptions instead
   - Let Application::boot() handle WSL redirect
   - Use `$console->step()`, `$console->ok()`, `$console->fail()` for output
   - Use `$app->runCommand()` for subprocess calls (auto-CRLF in WSL pipe mode)

2. **React Query is the data layer** — don't use `fetch()` directly, always use the API client modules in `frontend/src/api/`.

3. **Theming is automatic** — don't hardcode colors. Use semantic CSS class names (`.btn-primary`, `.card`) or inline Tailwind utilities that are remapped (`.text-gray-900` → `var(--text)`).

4. **Audit everything** — all changes to Projects, Teams, Agents, Skills, Workflows must call `AuditService::log()` so the audit log stays consistent.

5. **Prefer OOP instances over static methods** — use instances when there's meaningful state (e.g., `Console`); static methods are OK for stateless utilities (e.g., `Environment`).

6. **Exception-driven error handling** — catch exceptions at the top level (in scripts), never mid-function.
   
7. **Environment auto-detection** — scripts auto-detect Windows/WSL/Linux and act accordingly; users don't need to think about it.
   
8. **File paths — always use relative path:** — For files in the project. Do not `cd` to any subfolder of the projet and do not use `wsl -d Ubuntu-24.04 -e bash -c "..."`

9. **Git staging — always use `git add .`** unless files need to be added individually for a specific reason.

10. **Keep `doc/` up to date** — the `doc/` folder is the project's living documentation and must be maintained alongside code changes. It is organized as follows:

    | Folder | Content |
    |---|---|
    | `doc/functional/` | Functional documentation: concepts, features, business rules (what the app does) |
    | `doc/technical/` | Technical documentation: architecture, data model, API, adapters, configuration |
    | `doc/development/` | Developer guides: installation, scripts, Symfony commands |
    | `doc/mockups/` | UI theme mockups (HTML files, one per theme) |

    **Rules:**
    - When adding or modifying an entity, update `doc/technical/entities.md`.
    - When adding or modifying an API endpoint, update `doc/technical/api.md`.
    - When adding a new concept or feature visible to the user, update or create the relevant file in `doc/functional/`.
    - When adding a new script or Symfony command, update the relevant file in `doc/development/`.
    - When adding a new UI theme, add a mockup in `doc/mockups/` and update `doc/mockups/index.html`.
    - `doc/README.md` is the index — update it if a new file is added to `doc/`.

---

## Story Lifecycle (StoryStatus)

User stories and bugs follow a fixed lifecycle managed by `StoryStatus` enum:

```
new → ready → approved → planning → [graphic_design →] development → code_review → done
```

| Status | Trigger | Actor |
|---|---|---|
| `new` | Created | Human / Agent |
| `ready` | Marked ready | PO Agent or human |
| `approved` | Validated for development | Human |
| `planning` | Agent dispatched (tech-planning skill) | StoryExecutionService |
| `graphic_design` | Optional — needsDesign=true in plan | Agent |
| `development` | Active development | Agent(s) |
| `code_review` | Code submitted for review | Agent |
| `done` | Review passed | Agent / Human |

**Automated statuses** (`approved`, `graphic_design`, `development`, `code_review`): a "Lancer l'agent" button appears on the story card → opens `ExecuteModal` → dispatches `AgentTaskMessage` to Redis.

---

## Workflow Template vs Story Lifecycle

**Key distinction — do not confuse these two:**

- **`StoryStatus`** = the actual lifecycle state of a user story. It lives on the `Task` entity. Managed by `TaskService::transitionStory()`.
- **`Workflow`** = a reusable *template* that describes which agent roles execute at each automated stage. It is a configuration object, not an execution record.

The `Workflow` is assigned to a `Team`. When a project uses a team, its workflow template defines the `(roleSlug, skillSlug)` mapping for each story stage. `StoryExecutionService` reads this mapping from the workflow steps (F3 — done).

A workflow has a `status` field (`draft` → `validated` → `locked`). Only `validated` and `locked` workflows are usable for story execution. The Validate button is available in the workflow detail page when status is `draft`.

---

## Project → Team Relationship (known gap)

**Current state:** `Project` has a `team_id` column (F1). `StoryExecutionService` scopes agent search to the project's team (F2) and reads roleSlug/skillSlug from workflow steps (F3). All foundations complete.

---

## Agent Runtime Status

An agent's **operational state** is derived (not stored):

| State | Condition |
|---|---|
| `working` | Has ≥1 task with `status=in_progress` AND `assignedAgent=this` |
| `error` | Has recent `TaskLog` with action `agent_error` or `planning_parse_error` |
| `idle` | Neither of the above |

Computed in the API response for the project team view.

---
