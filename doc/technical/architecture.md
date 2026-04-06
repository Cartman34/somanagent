# Technical Architecture

> See also: [Entities](entities.md) · [Adapters](adapters.md) · [REST API](api.md) · [Realtime Updates](realtime.md)

## Overview

SoManAgent is a **full-stack** application composed of:
- A **PHP backend** (Symfony 7.2) exposing a REST API
- A **React + TypeScript frontend** (Vite)
- A **PostgreSQL 16** database
- A `skills/` directory containing SKILL.md files

The centralized observability chain is shared across layers:
- backend and worker services record log events through `LogService`
- the frontend can ingest runtime/API failures through `POST /api/logs/events`
- infra degradations are surfaced as `infra` log events from health endpoints
- warning/error/critical events are aggregated into `LogOccurrence` for the `/logs` UI

The centralized realtime chain is also shared across layers:
- backend services emit normalized business updates through `RealtimeUpdateService`
- a Mercure adapter publishes them to the hub
- the frontend subscribes through one shared Mercure client

Application translations are managed through Symfony translation files under `backend/translations/`.

## Hexagonal Architecture (Partial)

The hexagonal architecture is applied **only to external integration points** (AI, VCS, Skills, Realtime), not to the entire application. Symfony and Doctrine are fixed choices.

```
┌─────────────────────────────────────────────────────┐
│                   Application                        │
│                                                      │
│  Controller ──→ Service ──→ Repository (Doctrine)    │
│                    │                                 │
│                    └──→ Port ──→ Adapter             │
│                                  ├── ClaudeApiAdapter│
│                                  ├── ClaudeCliAdapter│
│                                  ├── GitHubAdapter   │
│                                  ├── GitLabAdapter   │
│                                  ├── SkillsShAdapter │
│                                  └── MercureRealtime │
└─────────────────────────────────────────────────────┘
```

**Ports** are PHP interfaces (`src/Port/`). **Adapters** are their implementations (`src/Adapter/`). Symfony injects the correct adapter via the `services.yaml` configuration.

## Backend Structure (`backend/src/`)

```
src/
├── Entity/          Doctrine entities (9 classes)
│                    Project, Module, Team, Role, Agent,
│                    Skill, Workflow, WorkflowStep, AuditLog
│
├── Enum/            PHP Backed Enums (6)
│                    ConnectorType, ModuleStatus, SkillSource,
│                    WorkflowTrigger, WorkflowStepStatus, AuditAction
│
├── ValueObject/     Immutable non-persisted objects
│                    AgentConfig, Prompt, AgentResponse
│
├── Repository/      Doctrine ServiceEntityRepository (9)
│
├── Port/            Hexagonal interfaces (3)
│                    AgentPort, VCSPort, SkillPort
│
├── Adapter/         Concrete implementations
│   ├── AI/          ClaudeApiAdapter, ClaudeCliAdapter
│   ├── VCS/         GitHubAdapter, GitLabAdapter
│   └── Skill/       SkillsShAdapter
│
├── Service/         Business logic
│                    ProjectService, TeamService, AgentService,
│                    SkillService, AuditService, AgentPortRegistry
│
├── Controller/      REST endpoints (thin controllers)
│                    HealthController, ProjectController, TeamController,
│                    AgentController, SkillController
│
├── Command/         Symfony console commands
│                    HealthCheckCommand, ImportSkillCommand, SeedWebTeamCommand
│
└── ValueObject/     (see above)
```

## Code Conventions

See [`conventions.md`](conventions.md) for the full reference: PHPDoc, JSDoc/TSDoc, translations, entity CSS classes, author header, services, entities, ports and adapters, development command rule.

## Tech Stack

| Component | Technology | Version |
|---|---|---|
| Backend | PHP + Symfony | 8.4 / 7.2 |
| ORM | Doctrine | 3.x |
| Database | PostgreSQL | 16 |
| Frontend | React + TypeScript | 18 / 5 |
| Frontend build | Vite | 5 |
| HTTP client | Guzzle | 7 |
| Containerisation | Docker + Compose | — |

## Data Flow

```
React (fetch)
     │
     ▼
Nginx (proxy)
     │
     ▼
PHP-FPM (Symfony)
     │
     ├── Controller
     │       │
     │       ▼
     │   Service ──→ Repository ──→ PostgreSQL
     │       │
     │       └──→ AgentPortRegistry ──→ Claude API / CLI
     │
     └── JSON Response
```
