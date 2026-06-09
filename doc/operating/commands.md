# Symfony Commands

> See also: [Scripts](scripts.md) · [Installation](installation.md)

Symfony commands are executed via `bin/console`. In the Docker context, use:

```bash
php scripts/toolkit/console.php <command> [args...]
```

Command rule:
- Symfony command descriptions, argument help, option help, and console UI output must be written in English
- French is reserved to the web interface, not the CLI layer
- Exception: command payloads may still contain French when they carry business content, for example a message sent to an agent

## SoManAgent Commands

These commands are specific to SoManAgent (prefix `somanagent:`).

### `somanagent:health`
Runs the shared connector health battery (`runtime`, `auth`, `prompt_test`, `models`) for every registered connector.

```bash
php scripts/toolkit/console.php somanagent:health
```

Each connector now prints one table row per shared check, including `Say OK` prompt execution.

---

### `somanagent:connector:send`
Sends a direct low-level request through one connector, without project context.

```bash
php scripts/toolkit/console.php somanagent:connector:send claude_cli --message "Hello"
php scripts/toolkit/console.php somanagent:connector:send codex_cli --conversation
php scripts/toolkit/console.php somanagent:connector:send claude_api --test
php scripts/toolkit/console.php somanagent:connector:send codex_api --agent <agent-uuid> --message "Explain this error"
```

Options:
- `--message` sends one one-shot message
- `--conversation` opens a local interactive loop until `/exit`
- `--test` sends the generic `Say OK` probe
- `--agent` reuses one agent configuration as the config source, while the command still forces the connector passed on the CLI

---

### `somanagent:agent:adapters`
Lists registered agent adapters with their connector, health status, and model discovery capability.

```bash
php scripts/toolkit/console.php somanagent:agent:adapters
```

---

### `somanagent:agent:models`
Lists the models available for one connector.

```bash
php scripts/toolkit/console.php somanagent:agent:models codex_api
php scripts/toolkit/console.php somanagent:agent:models opencode_cli --refresh --details
```

The detailed mode prints any extra model metadata exposed by the provider, such as pricing, release date, and capabilities.

---

### `somanagent:skill:import`
Imports a skill from the skills.sh registry and saves it to the database.

```bash
php scripts/toolkit/console.php somanagent:skill:import anthropics/code-reviewer
php scripts/toolkit/console.php somanagent:skill:import vercel-labs/agent-skills
```

Equivalent to `POST /api/skills/import` but usable from the command line.

---

### `somanagent:seed:web-team`
Creates the example "Web Development Team" with its 6 roles.

```bash
php scripts/toolkit/console.php somanagent:seed:web-team
```

Roles created: Tech Lead, Backend Developer, Frontend Developer, Reviewer, QA, DevOps.

---

## Doctrine Commands (Migrations)

### Migration Status
```bash
php scripts/toolkit/console.php doctrine:migrations:status
php scripts/toolkit/console.php doctrine:migrations:list
```

### Run Migrations
```bash
php scripts/toolkit/console.php doctrine:migrations:migrate --no-interaction
# or via the dedicated script:
php scripts/migrate.php
```

### Create a New Migration
```bash
php scripts/migrate.php --generate
```
Generates a migration from the diff between the current entities and a temporary isolated database.
The shared application database is never used as the diff target.
When run from a WA, the agent code is detected automatically from the worktree path.
The temporary database is named `{agentCode}_migrate_gen` and is dropped after the diff.

> **Do not use `php scripts/toolkit/console.php doctrine:migrations:diff` directly** — it diffs against
> the shared application database, which may contain uncommitted changes from other agents.

### Rollback
```bash
php scripts/toolkit/console.php doctrine:migrations:execute --down 'DoctrineMigrations\Version20260324000001'
```

---

## Useful Symfony Commands

### Cache
```bash
php scripts/toolkit/console.php cache:clear
php scripts/toolkit/console.php cache:warmup
```

### Debug
```bash
php scripts/toolkit/console.php debug:router          # list all routes
php scripts/toolkit/console.php debug:container       # list services
php scripts/toolkit/console.php debug:config doctrine # effective Doctrine config
```

### Schema Validation
```bash
php scripts/toolkit/console.php doctrine:schema:validate   # check entity ↔ DB consistency
php scripts/toolkit/console.php doctrine:schema:create --dump-sql  # SQL of the current schema
```
