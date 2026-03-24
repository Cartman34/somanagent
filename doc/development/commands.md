# Symfony Commands

> See also: [Scripts](scripts.md) · [Installation](installation.md)

Symfony commands are executed via `bin/console`. In the Docker context, use:

```bash
php scripts/console.php <command> [args...]
```

## SoManAgent Commands

These commands are specific to SoManAgent (prefix `somanagent:`).

### `somanagent:health`
Checks the status of AI connectors (Claude API, Claude CLI).

```bash
php scripts/console.php somanagent:health
```

Output:
```
SoManAgent — Connector check
 ✓ claude_api
 ✗ claude_cli
 [WARNING] Some connectors are unreachable.
```

---

### `somanagent:skill:import`
Imports a skill from the skills.sh registry and saves it to the database.

```bash
php scripts/console.php somanagent:skill:import anthropics/code-reviewer
php scripts/console.php somanagent:skill:import vercel-labs/agent-skills
```

Equivalent to `POST /api/skills/import` but usable from the command line.

---

### `somanagent:seed:web-team`
Creates the example "Web Development Team" with its 6 roles.

```bash
php scripts/console.php somanagent:seed:web-team
```

Roles created: Tech Lead, Backend Developer, Frontend Developer, Reviewer, QA, DevOps.

---

## Doctrine Commands (Migrations)

### Migration Status
```bash
php scripts/console.php doctrine:migrations:status
php scripts/console.php doctrine:migrations:list
```

### Run Migrations
```bash
php scripts/console.php doctrine:migrations:migrate --no-interaction
# or via the dedicated script:
php scripts/migrate.php
```

### Create a New Migration
```bash
php scripts/console.php doctrine:migrations:diff
```
Automatically generates a migration from the diff between entities and the database.

### Rollback
```bash
php scripts/console.php doctrine:migrations:execute --down 'DoctrineMigrations\Version20260324000001'
```

---

## Useful Symfony Commands

### Cache
```bash
php scripts/console.php cache:clear
php scripts/console.php cache:warmup
```

### Debug
```bash
php scripts/console.php debug:router          # list all routes
php scripts/console.php debug:container       # list services
php scripts/console.php debug:config doctrine # effective Doctrine config
```

### Schema Validation
```bash
php scripts/console.php doctrine:schema:validate   # check entity ↔ DB consistency
php scripts/console.php doctrine:schema:create --dump-sql  # SQL of the current schema
```
