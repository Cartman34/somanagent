# Configuration

> See also: [Adapters](adapters.md) · [Installation](../development/installation.md)

## `.env` File

The `.env` file at the project root contains all configuration variables. It is ignored by git. Copy `.env.example` to get started:

```bash
cp .env.example .env
```

## Available Variables

### Database

```ini
DATABASE_URL=postgresql://somanagent:secret@db:5432/somanagent?serverVersion=16&charset=utf8
```

| Parameter | Description |
|---|---|
| `somanagent` (user) | PostgreSQL user |
| `secret` | Password (change in production) |
| `db` | Docker container hostname |
| `5432` | PostgreSQL port |
| `somanagent` (db) | Database name |

### Claude API Key

```ini
CLAUDE_API_KEY=sk-ant-...
```

Required for the `claude_api` connector. Obtain it at [console.anthropic.com](https://console.anthropic.com).

### GitHub Integration

```ini
GITHUB_TOKEN=ghp_...
```

GitHub Personal Access Token. Required permissions: `repo`, `workflow`, `write:packages`.

### GitLab Integration

```ini
GITLAB_TOKEN=glpat-...
GITLAB_URL=https://gitlab.com
```

`GITLAB_URL` can be the URL of a self-hosted GitLab instance.

### Symfony Application

```ini
APP_ENV=dev
APP_SECRET=changethis
```

`APP_SECRET` must be a random string of at least 32 characters.

## `.env.example`

```ini
# Database
DATABASE_URL=postgresql://somanagent:secret@db:5432/somanagent?serverVersion=16&charset=utf8

# Claude API
CLAUDE_API_KEY=

# GitHub
GITHUB_TOKEN=

# GitLab
GITLAB_TOKEN=
GITLAB_URL=https://gitlab.com

# Symfony
APP_ENV=dev
APP_SECRET=changethis
```

## Symfony Configuration

### `config/services.yaml`
Manages dependency injection. Key points:
- `CLAUDE_API_KEY` is injected into `ClaudeApiAdapter`
- `skills_dir` points to `../skills` (relative to the backend directory)
- AI adapters are tagged `app.agent_adapter` for `AgentPortRegistry`

### `config/packages/doctrine.yaml`
- Doctrine mapping on `src/Entity/` (prefix `App\Entity`)
- `uuid` type mapped to `Symfony\Bridge\Doctrine\Types\UuidType`
- `server_version: '16'` for PostgreSQL 16

### `config/packages/nelmio_cors.yaml`
Allows cross-origin requests from the frontend (`localhost:5173`).

## Verifying the Configuration

```bash
php scripts/health.php
```

Checks that the API responds and that the configured connectors are reachable.
