# Available Scripts

> See also: [Installation](installation.md) · [Symfony Commands](commands.md) · [Script Conventions](scripts-conventions.md)

Scripts are located in `scripts/`. All PHP scripts follow this convention: a **commented header** just after the shebang, with `Description:` and `Usage:` tags.

Project rule:
- always use a script from `scripts/` first when it already covers the operation
- only fall back to direct `docker exec`, raw `bin/console`, or container-specific commands when no script exists
- this keeps commands shorter, more consistent, and cheaper to use during day-to-day work

## Invocation

Two equivalent forms are supported from the project root for any script that carries a shebang:

```bash
php scripts/generate-migration.php          # explicit PHP interpreter
./scripts/generate-migration.php            # rely on the script's shebang (#!/usr/bin/env php)
```

Both work because every runnable script under `scripts/` declares a `#!/usr/bin/env php` (or equivalent) shebang **and** carries the exec bit in the git index. The exec bit is enforced by `scripts/toolkit/validate-files.php` at review time — see [Script Conventions / Executable Bit](scripts-conventions.md#executable-bit).

```bash
# See all available scripts
php scripts/toolkit/help.php

# See the help for a specific script
php scripts/toolkit/help.php generate-migration.php
```

## Portal scripts

The toolkit and backlog portals (`scripts/toolkit/`, `scripts/backlog/`) expose their own scripts from the `sowapps/web-toolkit` and `sowapps/web-backlog` packages. They are documented in those packages, not duplicated here:

- **Toolkit** (`scripts/toolkit/`): `help`, `phpstan`, `rector`, `phpunit`, `validate-files`, `validate-translations`, `code-search`, `code-refacto`, `doc-format`, `github`, `git-cleanup-branches`, `fix-permissions`, `analyze-code`, `server`, `logs`, `console`, `db`, `install` → see [`scripts/toolkit/doc/operating/scripts.md`](../../scripts/toolkit/doc/operating/scripts.md). The host stack is configured under `toolkit/config.yaml`.
- **Backlog** (`scripts/backlog/`): `backlog`, `agent`, `review`, `worktree-info`, `validate-agent-launchers`, `install` → see the backlog usage docs under [`scripts/backlog/doc/using/`](../../scripts/backlog/doc/using/) (workflow, roles, sessions, board/review) and each command's `--help`.

## Dedicated scripts (`scripts/`)

These scripts are owned by this project and documented below.

| Script | Type | Role |
|---|---|---|
| `check-php.sh` | Bash | Check that PHP 8.4+ is installed |
| `scripts-install.php` | PHP | Install the local Composer dependencies required by `scripts/` |
| `setup.php` | PHP | Manage host-level dependencies and project setup (install, update, verify, uninstall, reset, status, dep-config) |
| `generate-migration.php` | PHP | Generate a Doctrine migration using an isolated temporary database |
| `node.php` | PHP | Run reusable commands inside the Node container |
| `health.php` | PHP | Check application status |
| `validate-backend-tests.php` | PHP | Run isolated local PHPUnit checks for backend unit tests from WSL without Docker services |
| `claude-auth.php` | PHP | Sync Claude CLI auth from WSL to the Docker runtime |
| `codex-auth.php` | PHP | Sync Codex CLI ChatGPT auth from WSL to the Docker runtime |
| `opencode-auth.php` | PHP | Sync OpenCode provider credentials from WSL to the Docker runtime |
| `wsl-claude-install.sh` | Bash | Install Claude CLI inside the configured WSL distro |
| `wsl-codex-install.sh` | Bash | Install or upgrade OpenAI Codex CLI inside WSL |
| `wsl-migrate.sh` | Bash | Copy the project to the WSL native filesystem for faster Docker I/O |

## Script Details

### `check-php.sh`
Checks that PHP >= 8.4 is available in the PATH.

```bash
bash scripts/check-php.sh
# ✓ PHP 8.4.5 detected
```

---

### `scripts-install.php`
Installs the local Composer dependencies required by `scripts/` (the PHPStan/Rector binaries and their extensions under `scripts/vendor`). Standalone by design: it does not use the scripts runner stack, so it works on a fresh checkout where `scripts/vendor/autoload.php` is still missing.

```bash
php scripts/scripts-install.php
php scripts/scripts-install.php --update
```

---

### `setup.php`
Manages host-level dependencies and the project setup. Subcommand-based runner.

Subcommands:
- `update` — re-resolve the manifest against available sources and write the lockfile
- `install` — install or upgrade host dependencies from the lockfile, then run project setup (composer, npm, Doctrine migrations)
- `verify` — compare system state, lockfile, and manifest without mutating (exit `0` aligned, `1` discrepancies)
- `uninstall` — remove installed deps according to `pre_existing` flags and `on_uninstall_pre_existing` policy
- `reset` — drop the database and remove Docker volumes (does **not** touch host deps or client binaries)
- `status` — show manifest, lockfile, installed versions, Docker service status, last migration (no mutation)
- `dep-config` — read/write per-dep overrides in the lockfile (`get`/`set`/`unset`)

```bash
php scripts/setup.php help                           # show help (also displayed when no subcommand is passed)
php scripts/setup.php help <subcommand>              # detail one subcommand

php scripts/setup.php update                         # resolve + write lockfile
php scripts/setup.php update --preview-only          # resolution diff + plan, no apply
php scripts/setup.php update --dry-run               # plan + simulated commands, no apply
php scripts/setup.php update --force                 # apply without confirmation

php scripts/setup.php install                        # apply lockfile + composer/npm/migrations
php scripts/setup.php install --preview-only
php scripts/setup.php install --dry-run
php scripts/setup.php install --force

php scripts/setup.php verify                         # alignment check, no mutation
php scripts/setup.php status                         # full system / lockfile / docker overview, no mutation

php scripts/setup.php uninstall                      # remove non-pre-existing deps
php scripts/setup.php uninstall --restore            # one-shot: pre-existing deps downgrade to previous_version
php scripts/setup.php uninstall --keep               # one-shot: pre-existing deps untouched

php scripts/setup.php reset                          # drop DB + remove docker volumes (confirm prompt)
php scripts/setup.php reset --keep-volumes           # stop containers but keep volumes
php scripts/setup.php reset --force                  # skip confirmation

php scripts/setup.php dep-config get claude
php scripts/setup.php dep-config set claude on_uninstall_pre_existing restore
php scripts/setup.php dep-config unset claude on_uninstall_pre_existing
```

Notes:
- Lockfile is local: `scripts/resources/dependencies.lock` is **not committed** on this project — it stores per-host `pre_existing` state and side-effect paths. Each machine generates its own via `setup.php update`. `install` rejects an absent or sentinel lockfile (`generated_at: ~`).
- Mutation subcommands (`update`, `install`, `uninstall`, `reset`) accept `--preview-only`, `--dry-run`, and `--force`. `--preview-only` and `--dry-run` are mutually exclusive. `--force` still prints the preview for traceability.
- `dep-config` mutations are local and reversible (`unset`); no `--force` flag.
- `install` runs Doctrine migrations via **host PHP CLI** (`php backend/bin/console doctrine:migrations:migrate --no-interaction`), not via `docker compose exec`. Requires the `db` container up; the `php` container is not required (compatible with `scripts/toolkit/server.php start minimal`). `DATABASE_URL` is normalised from `db:5432` to `localhost:5432` automatically.
- `verify`: `0` if aligned, `1` for missing/outdated/orphaned/unlocked deps. Run `setup.php update` first if deps appear unlocked.
- `uninstall` policy chain: `--restore`/`--keep` flag > lockfile override (`dep-config`) > manifest per-dep `on_uninstall_pre_existing` > manifest default > framework default (`keep`).
- `reset` is destructive: explicit confirmation required unless `--force`. Host dependencies (apt packages, npm clients) are **not** removed by `reset` — use `uninstall` for that.
- BLOCKED items (version below minimum with `on_existing_below_min: error`) make the command exit before the preview is shown.

---

### `generate-migration.php`
Generates a new Doctrine migration diff against an isolated temporary database.

```bash
php scripts/generate-migration.php
```

The script creates a temporary database named `{agentCode}_migrate_gen`, applies all existing migrations on it, then runs `doctrine:migrations:diff`. The temporary database is dropped after the diff. `SOMANAGER_AGENT` is required because it names the temporary database.

`generate-migration.php` runs entirely locally without `psql`: it uses PHP/PDO to create and drop the temporary database on `localhost:5432`, and runs `php backend/bin/console` from the checkout root. The Docker PostgreSQL service must be running and accessible on `localhost:5432`; `scripts/toolkit/server.php start minimal` is enough. If the PHP/DB connection fails, the command exits with a structured error indicating the DSN, working directory, cause, and action expected.

Apply existing migrations with the toolkit DB wrapper:

```bash
php scripts/toolkit/db.php migrate
```

---

### `node.php`
Runs reusable developer commands in the Node container without repeating raw `docker compose exec`.

```bash
php scripts/node.php type-check
php scripts/node.php run build
php scripts/node.php exec npm install
php scripts/node.php shell
```

Use this script in priority for repeated frontend container actions such as type-checking, builds, linting, tests, or an interactive shell.

---

### `health.php`
Checks API reachability, then runs `somanagent:health` for the detailed connector battery.

```bash
php scripts/health.php
php scripts/health.php --url http://my-server:8080
```

---

### `validate-backend-tests.php`
Runs isolated local PHPUnit from WSL for backend unit tests that must stay independent from Docker services, databases, Redis, and real external APIs.

For service-driven validation, the dedicated test mapping is `backend/src/Service/...` -> `backend/tests/Unit/Service/...Test.php`.

```bash
php scripts/validate-backend-tests.php backend/src/Service/AgentModelRecommendationPolicyResolver.php
php scripts/validate-backend-tests.php backend/src/Service/VcsRepositoryUrlService.php
php scripts/validate-backend-tests.php --all
```

Rules:
- modified service files are detected only from the explicit file list passed to the script
- `--all` runs the `local-unit` testsuite only
- the dedicated mapping preserves subdirectories under `Service/`
- local unit tests must live under `backend/tests/Unit/`
- local unit tests must extend `Sowapps\SoManAgent\Tests\Support\LocalUnitTestCase`
- local unit tests must not boot the Symfony kernel, access DB/Redis, or instantiate real external HTTP/API clients
- a missing dedicated test is reported but does not fail validation
- an existing dedicated test must pass with no PHPUnit warning, notice, or deprecation

---

### `claude-auth.php`
Manages Claude CLI auth with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/claude-auth.php status
php scripts/claude-auth.php sync
php scripts/claude-auth.php login
php scripts/claude-auth.php sync --force
```

Use `login` to authenticate in WSL, then sync the resulting auth files to `./.docker/claude/shared/`.

---

### `codex-auth.php`
Manages Codex CLI auth with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/codex-auth.php status
php scripts/codex-auth.php sync
php scripts/codex-auth.php login
php scripts/codex-auth.php sync --force
```

Important rule:
- the script only accepts a ChatGPT-based Codex login
- if Codex is logged in with an API key, `sync` fails on purpose because `codex_cli` must consume account usage limits, not API credits

Use `login` to authenticate with ChatGPT in WSL, then sync the resulting auth directory to `./.docker/codex/shared/`.

---

### `opencode-auth.php`
Manages OpenCode provider credentials with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/opencode-auth.php status
php scripts/opencode-auth.php sync
php scripts/opencode-auth.php login
php scripts/opencode-auth.php login openrouter
```

Important rule:
- OpenCode currently authenticates through provider credentials
- no subscription-based account usage mode has been detected, so this connector cannot currently satisfy the same “use plan limits instead of API credits” constraint as `codex_cli`

Use `login [provider]` to configure a provider in WSL, then sync the resulting auth file to `./.docker/opencode/shared/`.

---

### `wsl-claude-install.sh`
Installs Claude CLI inside the configured WSL distro so it can be used from the project in a native Linux environment.

```bash
bash scripts/wsl-claude-install.sh
```

This script currently targets a configured WSL distro name internally.

---

### `wsl-codex-install.sh`
Installs or upgrades the OpenAI Codex CLI directly inside WSL, so it can later be started from a native Linux shell in the project.

```bash
bash scripts/wsl-codex-install.sh
bash scripts/wsl-codex-install.sh --skip-login
```

After installation:

```bash
codex login
php scripts/codex-auth.php sync
cd ~/projects/somanagent
codex
```

---

### `wsl-migrate.sh`
Copies the project from `/mnt/...` to the WSL native filesystem to avoid slow Docker bind mounts on Windows-backed filesystems.

```bash
bash scripts/wsl-migrate.sh
bash scripts/wsl-migrate.sh --dest ~/projects/somanagent
```

Use this when the repository was cloned under `/mnt/c/...` and local Docker I/O is too slow.

## Script Header Convention

Each script must start with this block (after the shebang):

**PHP:**
```php
#!/usr/bin/env php
<?php
// Description: Short one-line description
// Usage: php scripts/script-name.php [options]
// Usage: php scripts/script-name.php --flag value
```

**Bash:**
```bash
#!/usr/bin/env bash
# Description: Short one-line description
# Usage: bash scripts/script-name.sh [options]
```

`help.php` automatically parses these headers to generate its display.
