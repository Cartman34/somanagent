# SoManAgent

**Squad of Managed Agents** — Orchestrate AI agent teams for software development.

SoManAgent is a self-hosted web application that lets you assemble teams of AI agents, assign them roles and skills, and orchestrate them through structured workflows to produce code, perform code reviews, generate tests, and more.

Think of it as a project manager for your AI agents — except it never calls for unnecessary meetings.

---

## Why SoManAgent?

Working with AI agents in software development raises practical questions:

- How do you organise agents with different responsibilities?
- How do you give them consistent, reusable instructions across projects?
- How do you track what they do and audit their outputs?
- How do you run multi-step processes without manually chaining prompts?

SoManAgent answers these questions with a structured interface built around teams, roles, skills, and workflows.

---

## How It Works

```
You create a Project
       │
       ├── Modules (api-php, app-mobile, backoffice…)
       │
       └── You assign a Team
                   │
                   ├── Roles (Tech Lead, Reviewer, QA…)
                   │         └── each has a Skill (SKILL.md instructions)
                   │
                   └── Agents (configured AI instances)
                               └── Claude API or Claude CLI
```

You define **Workflows** — sequences of steps that route tasks to the right agents with the right skills. Tickets progress through workflow steps; each step can dispatch agent tasks automatically or wait for manual authorization.

---

## Key Features

- **Team & Role Management** — Define reusable teams of AI agents with typed roles (Tech Lead, Backend Dev, Reviewer, QA…)
- **Skills System** — Import skills from [skills.sh](https://skills.sh) or write custom `SKILL.md` files; assign them to roles
- **Workflow Orchestration** — Multi-step workflows with automatic or manual transitions, conditional logic, and step dependencies
- **Ticket Board** — Track user stories and bugs through workflow steps; each ticket drives agent task execution
- **Task Execution** — Dispatch agent tasks via Claude API (HTTP) or Claude CLI (local binary), with retry and dead-letter handling
- **VCS Integration** — Connect GitHub or GitLab repositories; trigger workflows on PR/MR events
- **Full Audit Trail** — Every agent action, execution attempt, and token usage is logged
- **Observability** — Aggregated log occurrences, structured log events, and connector health monitoring

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.4 · Symfony 7.2 |
| Frontend | React 18 · TypeScript · Vite |
| Database | PostgreSQL 16 |
| Queue | Redis · Symfony Messenger |
| AI connectors | Claude API · Claude CLI (Anthropic) |
| VCS | GitHub · GitLab |
| Runtime | Docker · Docker Compose |

---

## Quick Start

**Prerequisites:** Ubuntu 22.04+ / 24.04+ host, `sudo` access, a working host `php` binary, and network access to Ubuntu repositories, `docker.com`, `ppa.launchpad.net`, and the npm registry. Docker Desktop is **not** required — `setup.php` installs Docker Engine + Compose plugin directly.

```bash
# 1. Clone and configure
cp .env.dist .env
# Edit .env: set CLAUDE_API_KEY, GITHUB_TOKEN, and any other required values

# 2. Resolve host dependencies and write the local lockfile
php scripts/setup.php update

# 3. Install host dependencies and run project setup (composer / npm / migrations)
php scripts/setup.php install

# 4. Start the dev environment
php scripts/server.php start

# 5. Verify everything is up
php scripts/health.php
```

The lockfile (`scripts/resources/dependencies.lock`) is **local-only** — it captures per-host pre-existing state and side-effect paths, so each machine generates its own with `setup.php update`.

- **Web UI**: http://localhost:5173
- **API**: http://localhost:8080/api

### Daily usage

```bash
php scripts/server.php start            # full dev environment
php scripts/server.php start --minimal  # db + redis only (lightweight remote-server mode)
php scripts/server.php health           # native PHP probes for db / redis / http
php scripts/logs.php worker             # stream worker logs
php scripts/health.php                  # full application and connector health
```

---

## Documentation

| Document | Description |
|---|---|
| [Installation](doc/development/installation.md) | Full setup guide |
| [Scripts Reference](doc/development/scripts.md) | All available `scripts/` commands |
| [Architecture](doc/technical/architecture.md) | Code structure and conventions |
| [Entities](doc/technical/entities.md) | Data model and relationships |
| [REST API](doc/technical/api.md) | Complete API reference |
| [Functional Overview](doc/functional/overview.md) | Concepts and how they relate |

---

## Project Structure

```
somanagent/
├── backend/          # Symfony 7.2 REST API
├── frontend/         # React + TypeScript web UI
├── skills/           # Local SKILL.md files
│   ├── imported/     # Imported from skills.sh
│   └── custom/       # Created in SoManAgent
├── scripts/          # Dev and maintenance scripts
└── doc/              # Full documentation (yes, there is some)
```

---

## Contributing

Read the [documentation](doc/) before submitting a PR. Key conventions:

- **UI text** goes through Symfony translation keys — no hardcoded French strings in `.php`, `.ts`, or `.tsx` source files
- **PHPDoc** is required on public PHP methods and non-trivial private helpers
- **JSDoc/TSDoc** is required on all exported TypeScript/React code and non-trivial internal helpers
- Use `scripts/` wrappers instead of raw `docker exec` or `bin/console` commands
- Symfony console output must be in English; UI text is French

---

## License

MIT
