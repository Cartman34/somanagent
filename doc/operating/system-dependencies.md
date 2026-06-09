# System Dependencies

> See also: [Installation](../operating/installation.md) · [Architecture](architecture.md) · [Configuration](configuration.md)

Inventory of every host-level system dependency required to run the project. Application-level package dependencies (PHP via Composer, JavaScript via npm) are scoped in `backend/composer.json` and `frontend/package.json` and are not listed here.

## Scope

| Label | Meaning |
|---|---|
| `Prod` | Required to run the project. Includes Dev implicitly: a Prod dependency is also installed in every development environment. |
| `Dev` | Required only for local development and agent workflows. Not part of the production runtime. |

## Dependencies

###### php — PHP language runtime.
Required to run all project tooling on the host: setup, migrations, backlog workflow, agent launchers.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-pgsql — PostgreSQL driver for PHP.
Lets host PHP scripts open a Doctrine/PDO connection to the database during migrations and seed commands.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-xml — XML extension for PHP.
Required by Symfony components and Composer to parse XML manifests and configuration.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-curl — cURL extension for PHP.
Backs the Symfony HTTP client used by backend services and host scripts that call external APIs.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-mbstring — Multibyte string extension for PHP.
Handles UTF-8 and other multibyte encodings throughout Symfony and Composer operations.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-zip — Zip archive extension for PHP.
Lets Composer download and extract package archives.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### php-intl — Internationalization extension for PHP.
Provides ICU-backed locale, collation, and formatting features used by Symfony.
Source: apt (`ppa:ondrej/php`). Version: >=8.4. Scope: Prod.

###### composer — PHP package manager.
Pulls and updates the PHP libraries the backend and scripts depend on.
Source: apt. Version: >=2. Scope: Prod.

###### node — JavaScript runtime.
Needed on the host so npm can install the AI client CLIs and serve frontend tooling outside the container.
Source: distribution package manager. Version: >=20. Scope: Prod.

###### npm — Node package manager.
Installs the AI client CLIs system-wide and resolves frontend packages.
Source: bundled with node. Version: bundled. Scope: Prod.

###### curl — HTTP transfer tool.
Downloads GitHub release binaries during host dependency installation.
Source: distribution package manager. Version: any. Scope: Prod.

###### tar — Archive extraction tool.
Unpacks GitHub release archives during host dependency installation.
Source: distribution package manager. Version: any. Scope: Prod.

###### git — Distributed version control system.
Drives every backlog and worktree operation at runtime, including remote sync and branch lifecycle.
Source: apt. Version: >=2.30. Scope: Prod.

###### docker-engine — Container runtime.
Runs every project service (database, queue, backend, worker, proxy, realtime hub, frontend dev server).
Source: apt (`download.docker.com`). Version: >=24. Scope: Prod.

###### docker-compose-plugin — Compose orchestration plugin.
Brings the full service stack up and down with the project's `docker-compose.yml`.
Source: apt (`download.docker.com`). Version: >=2. Scope: Prod.

###### claude — Anthropic Claude Code CLI.
Used by the application to drive AI sessions when the active client is Claude.
Source: npm-global (`@anthropic-ai/claude-code`). Version: >=1.0. Scope: Prod.

###### codex — OpenAI Codex CLI.
Used by the application to drive AI sessions when the active client is Codex.
Source: npm-global (`@openai/codex`). Version: >=0.1. Scope: Prod.

###### opencode — OpenCode CLI.
Used by the application to drive AI sessions when the active client is OpenCode.
Source: github-release (`sst/opencode`). Version: >=0.1. Scope: Prod.

###### gemini — Google Gemini CLI.
Used by the application to drive AI sessions when the active client is Gemini.
Source: npm-global (`@google/gemini-cli`). Version: >=0.1. Scope: Prod.

###### tmux — Terminal multiplexer.
Keeps agent client sessions alive across SSH disconnects so a developer can resume work after a drop.
Source: apt. Version: >=3.2. Scope: Dev.

###### zstd — Zstandard decompression tool.
Lets the Codex agent launcher read compressed session rollouts; without it, those rollouts are silently skipped.
Source: apt. Version: any. Scope: Dev (optional).

###### rg — Ripgrep, a fast recursive grep.
Optional faster engine for `code-search.php`; the PHP fallback is used when ripgrep is not installed.
Source: apt. Version: any. Scope: Dev (optional).

## Maintenance

This page mirrors `scripts/resources/dependencies.yaml`, which is the authoritative manifest consumed by `php scripts/setup.php install`. When a dependency is added, removed, or has its constraint changed in the manifest, update the corresponding entry here in the same commit.
