# SoManAgent — Context for Claude Code

## Project Overview

**SoManAgent** is an agent orchestration platform that manages autonomous AI agents, their skills, workflows, and team assignments.

**Current Phase:** Phase 2B (React frontend with real API data via React Query)

**Working Directory:** `~/projects/somanagent` (WSL native filesystem — **critical for Docker performance**)

---

## Tech Stack

| Layer | Technology | Notes |
|---|---|---|
| **Frontend** | React 18 + TypeScript + Vite | React Query (TanStack) for data fetching, Tailwind CSS |
| **Backend** | PHP 8.4-FPM + Symfony 7 | Doctrine ORM, REST API, Psalm static analysis |
| **Database** | PostgreSQL 16 | Health-checked, auto-migrations via Doctrine |
| **DevOps** | Docker Compose + WSL 2 | 4 services: php, nginx, db, node |
| **CLI Scripts** | PHP (OOP pattern) | Terminal output helpers, Bootstrap pattern, exception-based errors |

---

## Critical Performance Note

⚠️ **The project MUST run from `~/projects/somanagent` in WSL native filesystem.**

**Why:** Docker bind mounts from `/mnt/c/...` (Windows NTFS accessed through WSL) use the 9P protocol over Hyper-V virtio, causing 5-20x slower I/O. WSL native ext4 gives near-native Linux speed.

**Migration:** If ever cloned to `/mnt/c/...`, run `bash scripts/wsl-migrate.sh --dest ~/projects/somanagent` to copy to WSL.

---

## Directory Structure

```
~/projects/somanagent/
├── frontend/                    # React SPA
│   └── src/
│       ├── api/                 # Axios clients (projects, teams, agents, skills, workflows, health)
│       ├── components/
│       │   ├── layout/          # Sidebar, TopBar (themed with CSS variables)
│       │   └── ui/              # Modal, ConfirmDialog, EmptyState, PageHeader, etc.
│       ├── pages/               # DashboardPage, ProjectsPage, TeamsPage, AgentsPage, SkillsPage, WorkflowsPage, AuditPage
│       ├── hooks/               # useTheme (localStorage-persisted theme switching)
│       └── index.css            # Tailwind + theme tokens (Terminal default) + gray remapping
├── backend/                     # Symfony REST API
│   ├── src/
│   │   ├── Controller/          # REST endpoints (Projects, Teams, Agents, Skills, Workflows, Audit, Health)
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
├── docker-compose.yml           # 4 services + health checks
├── .dockerignore                # Excludes docker/data/, node_modules/, vendor/, var/cache/
└── CLAUDE.md                    # This file
```

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
- `GET /api/health` - App version + status
- `GET /api/health/connectors` - Connector availability
- `GET /api/projects`, `POST /api/projects`, `PUT /api/projects/{id}`, `DELETE /api/projects/{id}`
- `GET /api/teams`, `POST /api/teams`, `PUT /api/teams/{id}`, `DELETE /api/teams/{id}`
- `GET /api/agents`, `POST /api/agents`, `PUT /api/agents/{id}`, `DELETE /api/agents/{id}`
- `GET /api/skills`, `POST /api/skills`, `PUT /api/skills/{id}`, `DELETE /api/skills/{id}`, `POST /api/skills/{id}/content`
- `GET /api/workflows`, `POST /api/workflows`, `PUT /api/workflows/{id}`, `DELETE /api/workflows/{id}`
- `GET /api/audit` - Paginated audit log

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

### React/TypeScript
- **Components:** Default exports, PascalCase naming
- **Hooks:** Start with `use`, e.g., `useQuery`, `useTheme`
- **Props:** Interface `Props` per component
- **State:** React hooks (`useState`, `useEffect`, `useContext`)
- **Styling:** Tailwind utility classes + custom `.card`, `.btn-primary`, `.badge-*` classes
- **Files:** One component per file unless tightly coupled

### CSS
- **Layered:** `@layer base`, `@layer components`, `@layer utilities` for Tailwind integration
- **Un-layered:** Custom overrides (highest priority) placed after `@tailwind utilities`
- **Variables:** All colors, spacing, fonts defined as CSS custom properties
- **Selectors:** Simple class selectors, avoid deep nesting

---

## Notes for Claude

1. **Always work from `~/projects/somanagent`** — WSL native filesystem is critical for Docker performance.

2. **Use the Application/Console pattern** for new scripts:
   - No `exit()` inside functions, throw exceptions instead
   - Let Application::boot() handle WSL redirect
   - Use `$console->step()`, `$console->ok()`, `$console->fail()` for output
   - Use `$app->runCommand()` for subprocess calls (auto-CRLF in WSL pipe mode)

3. **React Query is the data layer** — don't use `fetch()` directly, always use the API client modules in `frontend/src/api/`.

4. **Theming is automatic** — don't hardcode colors. Use semantic CSS class names (`.btn-primary`, `.card`) or inline Tailwind utilities that are remapped (`.text-gray-900` → `var(--text)`).

5. **Audit everything** — all changes to Projects, Teams, Agents, Skills, Workflows must call `AuditService::log()` so the audit log stays consistent.

6. **Prefer OOP instances over static methods** — use instances when there's meaningful state (e.g., `Console`); static methods are OK for stateless utilities (e.g., `Environment`).

7. **Exception-driven error handling** — catch exceptions at the top level (in scripts), never mid-function.

8. **Environment auto-detection** — scripts auto-detect Windows/WSL/Linux and act accordingly; users don't need to think about it.

---

## Next Phases (Post-Phase 2B)

- **Phase 3:** Workflow execution engine (run, pause, resume, dry-run modes)
- **Phase 4:** VCS integration (GitHub/GitLab webhooks, branch management)
- **Phase 5:** Skill marketplace (publish, discover, version control for skills)
- **Phase 6:** Monitoring & observability (logs, metrics, alerts)
