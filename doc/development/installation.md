# Installation and Getting Started

> See also: [Configuration](../technical/configuration.md) · [Scripts](scripts.md) · [Symfony Commands](commands.md)

## Prerequisites

| Tool | Minimum Version | Check |
|---|---|---|
| PHP | 8.4+ | `php --version` |
| Docker Desktop | 24+ | `docker --version` |
| Docker Compose | v2+ | `docker compose version` |
| Git | — | `git --version` |

**Node.js is not required locally** — it runs inside a Docker container.

Check PHP with the dedicated script:
```bash
bash scripts/check-php.sh
```

## Full Installation (First Time)

```bash
# 1. Clone the project
git clone https://github.com/Cartman34/somanagent.git
cd somanagent

# 2. Configure the environment
cp .env.example .env
# Edit .env and set at minimum CLAUDE_API_KEY

# 3. Run the automatic setup
php scripts/setup.php
```

The `setup.php` script:
1. Checks that `.env` is present
2. Starts Docker containers (`docker compose up -d --build`)
3. Waits for PostgreSQL to be ready
4. Runs Doctrine migrations
5. Runs `npm install` in the Node container

## Starting After Installation

```bash
php scripts/dev.php
```

URLs:
- **API**: `http://localhost:8080/api/health`
- **Interface**: `http://localhost:5173`
- **DB**: `localhost:5432` (user: `somanagent`, pass: `somanagent`)

## Stopping the Environment

```bash
php scripts/dev.php --stop
```

## Docker Structure

The `docker-compose.yml` defines 4 services:

| Service | Image | Exposed Port | Role |
|---|---|---|---|
| `php` | PHP 8.4-FPM + Composer | — | Runs Symfony |
| `worker` | PHP CLI Messenger worker | — | Consumes async agent jobs |
| `nginx` | Nginx alpine | 8080 | Proxy to PHP-FPM |
| `db` | PostgreSQL 16 | 5432 | Database |
| `node` | Node 20 alpine | 5173 | Vite dev server |

## Migrations

Migrations are located in `backend/migrations/`. To apply them:

```bash
php scripts/migrate.php
```

To check the status:
```bash
php scripts/console.php doctrine:migrations:status
```

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
# Check container status
docker compose ps
# View logs
php scripts/logs.php php
php scripts/logs.php worker
php scripts/logs.php db
```

### Database connection error
- Check that `DATABASE_URL` in `.env` matches the `db` Docker service
- Wait a few seconds for PostgreSQL to finish starting up

### Migrations fail
```bash
# See the list of migrations and their status
php scripts/console.php doctrine:migrations:status
# See available migrations
php scripts/console.php doctrine:migrations:list
```

### API responds but Claude connectors are down
- Check `CLAUDE_API_KEY` in `.env`
- For `claude_cli`: check that the `claude` binary is accessible in the PHP container
