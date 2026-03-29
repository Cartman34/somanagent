# Available Scripts

> See also: [Installation](installation.md) · [Symfony Commands](commands.md) · [Script Conventions](scripts-conventions.md)

Scripts are located in `scripts/`. All PHP scripts follow this convention: a **commented header** just after the shebang, with `Description:` and `Usage:` tags.

Project rule:
- always use a script from `scripts/` first when it already covers the operation
- only fall back to direct `docker exec`, raw `bin/console`, or container-specific commands when no script exists
- this keeps commands shorter, more consistent, and cheaper to use during day-to-day work

```bash
# See all available scripts
php scripts/help.php

# See the help for a specific script
php scripts/help.php migrate.php
```

## Overview

| Script | Type | Role |
|---|---|---|
| `check-php.sh` | Bash | Check that PHP 8.4+ is installed |
| `help.php` | PHP | Display help for all scripts |
| `setup.php` | PHP | Full installation (first time) |
| `dev.php` | PHP | Start / stop the environment |
| `migrate.php` | PHP | Run Doctrine migrations |
| `db-reset.php` | PHP | Recreate the local database, optionally with fixtures |
| `claude-auth.php` | PHP | Manage Claude CLI auth with WSL as source of truth, then sync it to Docker |
| `console.php` | PHP | Run a Symfony command |
| `node.php` | PHP | Run reusable commands inside the Node container |
| `db.php` | PHP | Run reusable commands inside the PostgreSQL container |
| `github.php` | PHP | GitHub CLI helper for PR creation, listing, view and merge |
| `logs.php` | PHP | Display Docker logs |
| `health.php` | PHP | Check application status |
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

### `help.php`
Displays the list of all scripts with their description and usage examples. Automatically parses the header of each script file.

```bash
php scripts/help.php              # list all scripts
php scripts/help.php migrate.php  # detail for one script
```

---

### `setup.php`
Full project installation. Run once after cloning.

```bash
php scripts/setup.php
php scripts/setup.php --skip-frontend  # without npm install
```

---

### `dev.php`
Starts or stops the Docker environment.

```bash
php scripts/dev.php           # start
php scripts/dev.php --stop    # stop
```

---

### `migrate.php`
Runs Doctrine migrations in the PHP container.

```bash
php scripts/migrate.php             # run migrations
php scripts/migrate.php --dry-run   # simulate without applying
```

---

### `db-reset.php`
Recreates the local database, runs migrations, and can also reload fixtures.

```bash
php scripts/db-reset.php
php scripts/db-reset.php --fixtures
php scripts/db-reset.php --fixtures --force
```

By default, the script asks for confirmation because it destroys all current local data. Use `--force` to skip the prompt.

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

### `console.php`
Runs any `bin/console` command in the PHP container.

```bash
php scripts/console.php cache:clear
php scripts/console.php doctrine:migrations:status
php scripts/console.php somanagent:seed:web-team
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

### `db.php`
Runs reusable `psql` commands in the PostgreSQL container.

```bash
php scripts/db.php query "SELECT 1"
php scripts/db.php exec -c "\\dt"
php scripts/db.php shell
```

Use this script in priority for repeated local database inspection instead of raw `docker exec ... psql ...`.

---

### `github.php`
Wraps a few common GitHub CLI/API flows used during delivery work, especially around pull requests.

```bash
php scripts/github.php pr list
php scripts/github.php pr view 42
php scripts/github.php pr create --title "My PR" --body "Summary"
php scripts/github.php pr merge 42 --squash
```

Requires `GITHUB_TOKEN` in `.env` and a detectable `origin` remote pointing to GitHub.

---

### `logs.php`
Displays a Docker container's logs in real time (tail -f).

```bash
php scripts/logs.php          # logs from the php container (default)
php scripts/logs.php db       # PostgreSQL logs
php scripts/logs.php node     # Vite logs
php scripts/logs.php nginx    # Nginx logs
```

Use this script in priority instead of raw `docker logs` when the target container is supported.

---

### `health.php`
Queries the API to check the application and connector status.

```bash
php scripts/health.php
php scripts/health.php --url http://my-server:8080
```

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
codex --login
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
