# SoManAgent — Documentation

**SoManAgent** (Squad of Managed Agents) is a web application for managing AI agent teams in software development.

Docs are organised **by activity** (Sowapps [documentation standard](../scripts/toolkit/doc/documenting/doc-standard.md)): `using/` (the product), `developing/` (the code), `operating/` (running it). Generic code conventions, architecture principles and the doc standard live in **web-toolkit** and are referenced, not duplicated; the backlog workflow lives in **web-backlog** (`scripts/backlog/doc/`).

---

## using/ — what SoManAgent does (functional / business)

| Document | Description |
|---|---|
| [using/overview.md](using/overview.md) | What SoManAgent is and what it does |
| [using/concepts.md](using/concepts.md) | Glossary: Project, Module, Team, Role, Agent, Skill, Workflow |
| [using/teams-and-roles.md](using/teams-and-roles.md) | Creating and managing teams, defining roles |
| [using/skills.md](using/skills.md) | Importing, creating and editing skills |
| [using/agents.md](using/agents.md) | Configuring agents and their connectors |
| [using/workflows.md](using/workflows.md) | Defining and running workflows |

## developing/ — understanding and extending the code

| Document | Description |
|---|---|
| [developing/architecture.md](developing/architecture.md) | Code structure, hexagonal architecture, data flow |
| [developing/adapters.md](developing/adapters.md) | Hexagonal ports and their implementations |
| [developing/entities.md](developing/entities.md) | Data model, Doctrine entities and relationships |
| [developing/api.md](developing/api.md) | REST API conventions and the OpenAPI contract |
| [developing/openapi.yaml](developing/openapi.yaml) | Versioned machine-readable HTTP contract |
| [developing/realtime.md](developing/realtime.md) | Mercure architecture, event model, topics |
| [developing/translations.md](developing/translations.md) | Translator-backed application messages |
| [developing/ui-usage-guidelines.md](developing/ui-usage-guidelines.md) | UI page structure, reusable components, action hierarchy |
| [developing/semantic-css.md](developing/semantic-css.md) | SoManAgent's semantic-CSS type registry (pattern in toolkit) |
| [developing/static-analysis.md](developing/static-analysis.md) | PHPStan backend baseline (SoManAgent specifics) |
| [developing/spec-maintenance.md](developing/spec-maintenance.md) | SoManAgent specifics for writing local specs |
| [developing/fixtures.md](developing/fixtures.md) | Reference seed data and sample workflows |
| [developing/diagrams/](developing/diagrams/) | PlantUML architecture diagrams |

**Code conventions** are generic and live in the toolkit: [`scripts/toolkit/doc/developing/conventions/`](../scripts/toolkit/doc/developing/conventions/README.md) (php, scripts, js, css).

## operating/ — running and maintaining the deployment

| Document | Description |
|---|---|
| [operating/installation.md](operating/installation.md) | Prerequisites and full setup |
| [operating/configuration.md](operating/configuration.md) | Environment variables, `.env` |
| [operating/system-requirements.md](operating/system-requirements.md) | Host-level system dependencies |
| [operating/scripts.md](operating/scripts.md) | Available scripts in `scripts/` |
| [operating/commands.md](operating/commands.md) | Available `bin/console` commands |
| [operating/troubleshooting.md](operating/troubleshooting.md) | Quick local recovery notes and useful checks |

## Backlog (workflow, roles, sessions, glossary)

Provided by the `sowapps/web-backlog` package via the portal [`scripts/backlog/doc/`](../scripts/backlog/doc/) (`using/` roles & workflow, `developing/` architecture, `operating/` migrations).

---

## Quick start

```bash
# 1. Install system dependencies — see doc/operating/system-requirements.md

# 2. Copy and configure the environment
cp .env.dist .env
# Edit .env: CLAUDE_API_KEY, GITHUB_TOKEN, etc.

# 3. Install scripts dependencies and wire packages
php scripts/scripts-install.php
php scripts/toolkit/install.php
php scripts/backlog/install.php

# 4. Start the dev environment
php scripts/toolkit/server.php start

# 5. Verify everything works
php scripts/health.php
```

**API**: `http://localhost:8080/api/health` — **UI**: `http://localhost:5173`

---

## Project structure

```
somanagent/
├── backend/          # PHP API (Symfony 7.2)
├── frontend/         # Web UI (React + TypeScript)
├── skills/           # Local skills (SKILL.md format)
├── scripts/          # Maintenance scripts (+ toolkit/ and backlog/ portals)
└── doc/              # This documentation (using/ developing/ operating/)
```

---

## Documentation maintenance

Update documentation in the **same commit** as the code change. See the toolkit's [doc-maintenance](../scripts/toolkit/doc/documenting/doc-maintenance.md) and the [documentation standard](../scripts/toolkit/doc/documenting/doc-standard.md) for how docs are organised and written.
