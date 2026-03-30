# SoManAgent — Documentation

**SoManAgent** (Squad of Managed Agents) is a web application for managing AI agent teams in software development.

It lets you assemble generic AI agent teams, assign them roles and skills, then orchestrate them through workflows to produce code, perform reviews, generate tests, and more.

---

## Navigation

### Functional documentation — understanding SoManAgent

| Document | Description |
|---|---|
| [Overview](functional/overview.md) | What SoManAgent is and what it does |
| [Key concepts](functional/concepts.md) | Glossary: Project, Module, Team, Role, Agent, Skill, Workflow |
| [Teams & roles](functional/teams-and-roles.md) | Creating and managing teams, defining roles |
| [Skills](functional/skills.md) | Importing, creating and editing skills |
| [AI Agents](functional/agents.md) | Configuring agents and their connectors |
| [Workflows](functional/workflows.md) | Defining and running workflows |

### Technical documentation — understanding the code

| Document | Description |
|---|---|
| [Architecture](technical/architecture.md) | Code structure, conventions, hexagonal architecture |
| [Entities](technical/entities.md) | Data model, Doctrine entities and their relationships |
| [REST API](technical/api.md) | Complete reference for all endpoints |
| [Adapters](technical/adapters.md) | Hexagonal ports and their implementations |
| [Configuration](technical/configuration.md) | Environment variables, .env file |
| [Translations Strategy](technical/translations.md) | Conventions and migration strategy for translator-backed application messages |

### Development documentation — working on SoManAgent

| Document | Description |
|---|---|
| [Installation](development/installation.md) | Prerequisites and full setup |
| [Scripts](development/scripts.md) | Available scripts in `scripts/` |
| [Symfony commands](development/commands.md) | Available `bin/console` commands |
| [Fixtures](development/fixtures.md) | Reference seed data and sample workflows |

---

## Quick start

```bash
# 1. Copy and configure the environment
cp .env.example .env
# Edit .env: CLAUDE_API_KEY, GITHUB_TOKEN, etc.

# 2. Full installation
php scripts/setup.php

# 3. Verify everything works
php scripts/health.php
```

**API**: `http://localhost:8080/api/health`
**UI**: `http://localhost:5173`

---

## Project structure

```
somanagent/
├── backend/          # PHP API (Symfony 7.2)
├── frontend/         # Web UI (React + TypeScript)
├── skills/           # Local skills (SKILL.md format)
│   ├── imported/     # Imported from skills.sh
│   └── custom/       # Created in SoManAgent
├── scripts/          # Maintenance scripts
└── doc/              # This documentation
```
