# Configuration

> See also: [Adapters](adapters.md) · [Installation](../development/installation.md)

## Environment Files

| File | Committed | Purpose |
|---|---|---|
| `.env.dist` | Yes | Template — copy to `.env` and fill in local values |
| `.env` | No (gitignored) | Local values for docker-compose and Docker containers |
| `backend/.env` | Yes | Symfony generic defaults, loaded by the Dotenv component |
| `backend/.env.dev` | Yes | Dev-environment Symfony overrides |
| `backend/.env.local` | No (gitignored) | Local Symfony overrides (non-Docker use) |

### How values reach the application

docker-compose reads the root `.env` for two purposes:

1. **Service configuration** — `${VAR}` substitution in `docker-compose.yml` configures services like `db` (`POSTGRES_*`) and `mercure` (`MERCURE_*` keys) directly.
2. **Container injection** — `env_file: - .env` passes all root `.env` variables as OS environment variables into the `php` and `worker` containers, where they override `backend/.env` values (OS env vars have priority over Symfony Dotenv).

To get started, copy the template:

```bash
cp .env.dist .env
# Edit .env and fill in real values (APP_SECRET, API keys, tokens…)
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

### Claude CLI Login

The `claude_cli` connector now uses WSL Claude auth as the source of truth, then synchronizes it into the Docker shared mount used by the containers.

Recommended commands:

```bash
php scripts/claude-auth.php login
php scripts/claude-auth.php status
```

If you already authenticated in WSL and only need to refresh the Docker copy:

```bash
php scripts/claude-auth.php sync
```

The synchronized Docker auth files are mounted as:

```text
./.docker/claude/shared/.claude      -> /claude-home/.claude
./.docker/claude/shared/.claude.json -> /claude-home/.claude.json
```

### Codex CLI Login

The `codex_cli` connector uses WSL Codex auth as the source of truth, then synchronizes it into the Docker shared mount used by the containers.

Recommended commands:

```bash
php scripts/codex-auth.php login
php scripts/codex-auth.php status
```

If you already authenticated in WSL and only need to refresh the Docker copy:

```bash
php scripts/codex-auth.php sync
```

Important rule:
- the synchronized login must be a ChatGPT account login
- API-key logins are rejected by the sync script because `codex_cli` must use ChatGPT plan usage limits instead of API credits

The synchronized Docker auth directory is mounted as:

```text
./.docker/codex/shared/.codex -> /codex-home/.codex
```

### OpenCode CLI Credentials

The `opencode_cli` connector uses WSL OpenCode credentials as the source of truth, then synchronizes them into the Docker shared mount used by the containers.

Recommended commands:

```bash
php scripts/opencode-auth.php login
php scripts/opencode-auth.php status
```

If you already authenticated in WSL and only need to refresh the Docker copy:

```bash
php scripts/opencode-auth.php sync
```

Important rule:
- OpenCode currently authenticates through provider credentials stored in `~/.local/share/opencode/auth.json`
- no subscription-based account usage mode has been detected, so this connector does not currently meet the same “use plan limits instead of API credits” requirement as `codex_cli`

The synchronized Docker auth tree is mounted as:

```text
./.docker/opencode/shared/.local -> /opencode-home/.local
```

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

### Mercure Realtime Hub

```ini
MERCURE_PUBLISH_URL=http://mercure/.well-known/mercure
MERCURE_PUBLISHER_JWT_KEY=!ChangeThisMercureHubJWTSecretKey!
MERCURE_SUBSCRIBER_JWT_KEY=!ChangeThisMercureHubJWTSecretKey!
```

| Parameter | Description |
|---|---|
| `MERCURE_PUBLISH_URL` | Internal hub URL used by the backend adapter |
| `MERCURE_PUBLISHER_JWT_KEY` | Shared HMAC secret used by the backend to publish updates |
| `MERCURE_SUBSCRIBER_JWT_KEY` | Shared HMAC secret used by the hub for subscriber auth policies |

### Symfony Application

```ini
APP_ENV=dev
APP_SECRET=changethis
```

`APP_SECRET` must be a random string of at least 32 characters.

## `backend/.env`

`backend/.env` is committed and documents the full set of variables expected by Symfony. Variables whose real values must come from the local environment are present but empty, with a comment pointing to the root `.env`.

## Symfony Configuration

### `config/services.yaml`
Manages dependency injection. Key points:
- `CLAUDE_API_KEY` is injected into `ClaudeApiConnector`
- `OPENAI_API_KEY` is injected into `CodexApiConnector`
- `skills_dir` points to `../skills` (relative to the backend directory)
- AI connectors are tagged `app.connector` for `ConnectorRegistry`

### `config/packages/doctrine.yaml`
- Doctrine mapping on `src/Entity/` (prefix `App\Entity`)
- `uuid` type mapped to `Symfony\Bridge\Doctrine\Types\UuidType`
- `server_version: '16'` for PostgreSQL 16

### `config/packages/nelmio_cors.yaml`
Allows cross-origin requests from the frontend (`localhost:5173`).

### Realtime transport

Mercure is documented in detail in [`realtime.md`](realtime.md).

## Verifying the Configuration

```bash
php scripts/health.php
```

Checks that the API responds and that the configured connectors are reachable.
