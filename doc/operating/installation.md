# Installation and Getting Started

> See also: [Configuration](configuration.md) · [System requirements](system-requirements.md) · [Scripts](scripts.md) · [Symfony Commands](commands.md)

## Prerequisites

Install all host-level dependencies listed in [System requirements](system-requirements.md) before proceeding.

## Full Installation (First Time)

```bash
# 1. Clone the project
git clone https://github.com/Cartman34/somanagent.git
cd somanagent

# 2. Configure the environment
cp .env.dist .env
# Edit .env: CLAUDE_API_KEY, GITHUB_TOKEN, etc.

# 3. Install scripts dependencies
php scripts/scripts-install.php

# 4. Wire toolkit and backlog packages
php scripts/toolkit/install.php
php scripts/backlog/install.php

# 5. Start the dev environment
php scripts/toolkit/server.php start
```

## Starting After Installation

```bash
php scripts/toolkit/server.php start            # full stack: db, redis, php, worker, nginx, node, mercure
php scripts/toolkit/server.php start minimal  # db + redis only (lightweight, agents-on-host mode)
php scripts/toolkit/server.php stop
php scripts/toolkit/server.php restart
php scripts/toolkit/server.php status           # docker compose ps
php scripts/toolkit/server.php health           # native PHP probes (PDO / TCP socket), no pg_isready / redis-cli on host
```

## Remote Server Setup (Agents on Host)

For a remote dev server where AI agents run **on the host** rather than inside the `php` container:

- Use `php scripts/toolkit/server.php start minimal` to keep only `db` and `redis` up. The rest of the stack (`php`, `worker`, `nginx`, `node`, `mercure`) stays stopped, reducing the memory and CPU footprint significantly.
- Backend code, agent sessions, and project tooling run directly via host PHP/CLI. The database is reached at `localhost:5432`.
- Doctrine migrations are executed via host PHP CLI (`php backend/bin/console doctrine:migrations:migrate --no-interaction`), not via `docker compose exec`, so they work in minimal mode as long as the `db` container is up. `DATABASE_URL` is normalised automatically from `db:5432` to `localhost:5432`.
- `scripts/toolkit/server.php health` performs its checks through PHP-native probes (PDO TCP connection for PostgreSQL, raw TCP socket + RESP `PING` for Redis, HTTP `file_get_contents` for nginx/mercure when the full profile is up). No `postgresql-client` or `redis-tools` package is added to the host manifest for this purpose.

## URLs (Local Development)

- **API**: `http://localhost:8080/api/health`
- **Frontend**: `http://localhost:5173`
- **Mercure (Vite proxy in dev)**: `http://localhost:5173/.well-known/mercure`
- **Mercure (Nginx)**: `http://localhost:8080/.well-known/mercure`
- **PostgreSQL**: `localhost:5432` (user: `somanagent`, password: `somanagent`)
- **Redis**: `localhost:6379`

## Docker Structure

TODO: Informations techniques, doit être dans un autre fichier qui peut être référencé

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

`scripts/toolkit/server.php start` activates the `full` profile (everything). `scripts/toolkit/server.php start minimal` skips the `full` profile and starts only `db` and `redis`.

## Migrations

Migrations live in `backend/migrations/`. Apply existing migrations with the toolkit DB wrapper:

```bash
php scripts/toolkit/db.php migrate
```

Generate a new migration with an isolated temporary database:

```bash
php scripts/generate-migration.php
```

To inspect status:

```bash
php scripts/toolkit/console.php doctrine:migrations:status
```

## Sample Data

To create the example Web Development Team:

```bash
php scripts/toolkit/console.php somanagent:seed:web-team
```

To fully recreate the local database and reload fixtures:

```bash
php scripts/toolkit/db.php reset --fixtures
```

## Troubleshooting

TODO Déplacer dans le fichier de ce nom

### Docker won't start

```bash
php scripts/toolkit/server.php status
php scripts/toolkit/logs.php php
php scripts/toolkit/logs.php worker
php scripts/toolkit/logs.php db
```

### Database connection error

- Check that `DATABASE_URL` in `.env` resolves correctly. Inside the `php` container it points at `db:5432`; from host it must resolve to `localhost:5432`.
- Wait a few seconds for PostgreSQL to finish starting up; `scripts/toolkit/server.php health` will exit `0` once the DB is ready.

### Migrations fail

```bash
php scripts/toolkit/console.php doctrine:migrations:status
php scripts/toolkit/console.php doctrine:migrations:list
```

### API responds but Claude connectors are down

- Check `CLAUDE_API_KEY` in `.env`.
- For `claude_cli`: check that the `claude` binary is accessible in the PHP container.
