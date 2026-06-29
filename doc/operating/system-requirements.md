# System Requirements

> See also: [Installation](installation.md) · [Configuration](configuration.md)

Inventory of every host-level system dependency required to run the project. Application-level package dependencies (PHP via Composer, JavaScript via npm) are scoped in `backend/composer.json` and `frontend/package.json` and are not listed here.

## Scope

| Label | Meaning |
|---|---|
| `Required` | Must be present for the project to function. |
| `Optional` | Only needed for a specific feature or workflow. |

## Dependencies

###### php — PHP language runtime.
Required to run all project tooling on the host: setup, migrations, backlog workflow, agent launchers.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-pgsql — PostgreSQL driver for PHP.
Lets host PHP scripts open a Doctrine/PDO connection to the database during migrations and seed commands.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-xml — XML extension for PHP.
Required by Symfony components and Composer to parse XML manifests and configuration.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-curl — cURL extension for PHP.
Backs the Symfony HTTP client used by backend services and host scripts that call external APIs.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-mbstring — Multibyte string extension for PHP.
Handles UTF-8 and other multibyte encodings throughout Symfony and Composer operations.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-zip — Zip archive extension for PHP.
Lets Composer download and extract package archives.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### php-intl — Internationalization extension for PHP.
Provides ICU-backed locale, collation, and formatting features used by Symfony.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Required.

###### composer — PHP package manager.
Pulls and updates the PHP libraries the backend and scripts depend on.
Source: apt. Version: >=2. Scope: Required.

###### node — JavaScript runtime.
Needed on the host so npm can install the AI client CLIs and serve frontend tooling outside the container.
Source: distribution package manager. Version: >=20. Scope: Required.

###### npm — Node package manager.
Installs the AI client CLIs system-wide and resolves frontend packages.
Source: bundled with node. Version: bundled. Scope: Required.

###### curl — HTTP transfer tool.
Downloads GitHub release binaries during host dependency installation.
Source: distribution package manager. Version: any. Scope: Required.

###### tar — Archive extraction tool.
Unpacks GitHub release archives during host dependency installation.
Source: distribution package manager. Version: any. Scope: Required.

###### git — Distributed version control system.
Drives every backlog and worktree operation at runtime, including remote sync and branch lifecycle.
Source: apt. Version: >=2.30. Scope: Required.

###### docker-engine — Container runtime.
Runs every project service (database, queue, backend, worker, proxy, realtime hub, frontend dev server).
Source: apt (`download.docker.com`). Version: >=24. Scope: Required.

###### docker-compose-plugin — Compose orchestration plugin.
Brings the full service stack up and down with the project's `docker-compose.yml`.
Source: apt (`download.docker.com`). Version: >=2. Scope: Required.

###### claude — Anthropic Claude Code CLI.
Used by the application to drive AI sessions when the active client is Claude.
Source: npm-global (`@anthropic-ai/claude-code`). Version: >=1.0. Scope: Optional (one AI client required).

###### codex — OpenAI Codex CLI.
Used by the application to drive AI sessions when the active client is Codex.
Source: npm-global (`@openai/codex`). Version: >=0.1. Scope: Optional (one AI client required).

###### opencode — OpenCode CLI.
Used by the application to drive AI sessions when the active client is OpenCode.
Source: github-release (`sst/opencode`). Version: >=0.1. Scope: Optional (one AI client required).

###### gemini — Google Gemini CLI.
Used by the application to drive AI sessions when the active client is Gemini.
Source: npm-global (`@google/gemini-cli`). Version: >=0.1. Scope: Optional (one AI client required).

###### tmux — Terminal multiplexer.
Keeps agent client sessions alive across SSH disconnects so a developer can resume work after a drop.
Source: apt. Version: >=3.2. Scope: Optional.

###### zstd — Zstandard decompression tool.
Lets the Codex agent launcher read compressed session rollouts; without it, those rollouts are silently skipped.
Source: apt. Version: any. Scope: Optional.

###### rg — Ripgrep, a fast recursive grep.
Optional faster engine for `code-search.php`; the PHP fallback is used when ripgrep is not installed.
Source: apt. Version: any. Scope: Optional.

## Maintenance

Update this file whenever a system dependency is added, removed, or has its version constraint changed.
