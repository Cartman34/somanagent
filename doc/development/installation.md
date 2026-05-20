# Installation and Getting Started

> See also: [Configuration](../technical/configuration.md) · [System dependencies](../technical/system-dependencies.md) · [Scripts](scripts.md) · [Symfony Commands](commands.md)

## Prerequisites

- **OS**: Ubuntu 22.04+ or 24.04+ (or another Debian-based distribution with `apt`).
- **Privileges**: `sudo` access for host-level package installation.
- **Network access**: Ubuntu repositories, `docker.com`, `ppa.launchpad.net`, npm registry, and the GitHub release API.
- **Bootstrap PHP**: a working `php` binary on the host (any recent 8.x release is enough; `setup.php install` upgrades it to 8.4+ when needed). Check with `bash scripts/check-php.sh`.

> **Docker Desktop is not required.** `setup.php` installs **Docker Engine + Compose plugin** directly from `docker.com` repositories on the host.

## Full Installation (First Time)

```bash
# 1. Clone the project
git clone https://github.com/Cartman34/somanagent.git
cd somanagent

# 2. Configure the environment
cp .env.dist .env
# Edit .env and set at minimum CLAUDE_API_KEY

# 3. Resolve host dependencies (generates the local lockfile)
php scripts/setup.php update

# 4. Install host dependencies and run project setup
php scripts/setup.php install

# 5. Start the dev environment
php scripts/server.php start
```

### Step details

- `setup.php update` queries each source declared in `scripts/resources/dependencies.yaml` (apt-cache, npm view, GitHub releases) and writes the resolved versions to `scripts/resources/dependencies.lock`.
- `setup.php install` reads the lockfile and installs or upgrades host dependencies (PHP 8.4+ and extensions, Docker Engine + Compose plugin, git, tmux, AI clients `claude`/`codex`/`opencode`/`gemini`), then runs project-level steps (Composer, npm, Doctrine migrations via host PHP CLI).
- `server.php start` brings up Docker Compose services (`db`, `redis`, `php`, `worker`, `nginx`, `node`, `mercure`).

### Lockfile is local-only on this project

`scripts/resources/dependencies.lock` is **not committed** in this repository: it stores per-host `pre_existing` state and per-host absolute paths for side effects (apt repositories, GPG keys). Each machine generates its own lockfile by running `setup.php update`.

`setup.php install` refuses to run when the lockfile is missing or has not been initialized (`generated_at: ~`), with the message *"lockfile not initialized — run 'php scripts/setup.php update' first"*.

### Verify alignment

After install or any system change you can compare system state, lockfile, and manifest without mutating anything:

```bash
php scripts/setup.php verify
```

Exit code `0` means everything is aligned. Exit code `1` reports missing, outdated, orphaned, or unlocked dependencies — usually fixed by re-running `setup.php update` followed by `setup.php install`.

## Starting After Installation

```bash
php scripts/server.php start            # full stack: db, redis, php, worker, nginx, node, mercure
php scripts/server.php start --minimal  # db + redis only (lightweight, agents-on-host mode)
php scripts/server.php stop
php scripts/server.php restart
php scripts/server.php status           # docker compose ps
php scripts/server.php health           # native PHP probes (PDO / TCP socket), no pg_isready / redis-cli on host
```

## Remote Server Setup (Agents on Host)

For a remote dev server where AI agents run **on the host** rather than inside the `php` container:

- Use `php scripts/server.php start --minimal` to keep only `db` and `redis` up. The rest of the stack (`php`, `worker`, `nginx`, `node`, `mercure`) stays stopped, reducing the memory and CPU footprint significantly.
- Backend code, agent sessions, and project tooling run directly via host PHP/CLI. The database is reached at `localhost:5432`.
- Doctrine migrations are executed via host PHP CLI (`php backend/bin/console doctrine:migrations:migrate --no-interaction`), not via `docker compose exec`, so they work in minimal mode as long as the `db` container is up. `DATABASE_URL` is normalised automatically from `db:5432` to `localhost:5432`.
- `server.php health` performs its checks through PHP-native probes (PDO TCP connection for PostgreSQL, raw TCP socket + RESP `PING` for Redis, HTTP `file_get_contents` for nginx/mercure when the full profile is up). No `postgresql-client` or `redis-tools` package is added to the host manifest for this purpose.

## URLs (Local Development)

- **API**: `http://localhost:8080/api/health`
- **Frontend**: `http://localhost:5173`
- **Mercure (Vite proxy in dev)**: `http://localhost:5173/.well-known/mercure`
- **Mercure (Nginx)**: `http://localhost:8080/.well-known/mercure`
- **PostgreSQL**: `localhost:5432` (user: `somanagent`, password: `somanagent`)
- **Redis**: `localhost:6379`

## Stopping the Environment

```bash
php scripts/server.php stop
```

## Docker Structure

The `docker-compose.yml` defines these services:

| Service | Image | Exposed Port | Profile | Role |
|---|---|---|---|---|
| `db` | PostgreSQL 16 | 5432 | always | Database |
| `redis` | Redis | 6379 | always | Queue / cache |
| `php` | PHP 8.4-FPM + Composer | — | `full` | Runs Symfony |
| `worker` | PHP CLI Messenger worker | — | `full` | Consumes async agent jobs |
| `nginx` | Nginx alpine | 8080 | `full` | Proxy to PHP-FPM |
| `mercure` | Mercure hub | — | `full` | Dedicated realtime transport |
| `node` | Node 20 alpine | 5173 | `full` | Vite dev server |

`server.php start` activates the `full` profile (everything). `server.php start --minimal` skips the `full` profile and starts only `db` and `redis`.

## Migrations

Migrations live in `backend/migrations/`. Use the dedicated wrapper:

```bash
php scripts/migrate.php            # run migrations (host PHP CLI)
php scripts/migrate.php --dry-run  # simulate without applying
php scripts/migrate.php --generate # generate a diff against a temp DB
```

To inspect status:

```bash
php scripts/console.php doctrine:migrations:status
```

> Note: `setup.php install` already runs Doctrine migrations during step 4 of the full installation, so `migrate.php` is only needed for ongoing schema work after the initial setup.

## Sample Data

To create the example Web Development Team:

```bash
php scripts/console.php somanagent:seed:web-team
```

To fully recreate the local database and reload fixtures:

```bash
php scripts/db.php reset --fixtures
```

## Troubleshooting

### Docker won't start

```bash
php scripts/server.php status
php scripts/logs.php php
php scripts/logs.php worker
php scripts/logs.php db
```

### Database connection error

- Check that `DATABASE_URL` in `.env` resolves correctly. Inside the `php` container it points at `db:5432`; from host it must resolve to `localhost:5432` (handled automatically by `migrate.php` and `setup.php install`).
- Wait a few seconds for PostgreSQL to finish starting up; `server.php health` will exit `0` once the DB is ready.

### Migrations fail

```bash
php scripts/console.php doctrine:migrations:status
php scripts/console.php doctrine:migrations:list
```

If `setup.php install` fails on the migration step, check the message — it explicitly indicates whether the `db` container is missing or whether the connection itself failed.

### API responds but Claude connectors are down

- Check `CLAUDE_API_KEY` in `.env`.
- For `claude_cli`: check that the `claude` binary is accessible in the PHP container (`php scripts/setup.php install` installs it on the host).

### Lockfile errors on install

If `setup.php install` complains about the lockfile not being initialized, run `php scripts/setup.php update` first to generate it. The lockfile is local-only and never committed.
