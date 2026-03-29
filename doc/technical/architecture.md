# Technical Architecture

> See also: [Entities](entities.md) · [Adapters](adapters.md) · [REST API](api.md)

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

Application translations are managed through Symfony translation files under `backend/translations/`.

## Hexagonal Architecture (Partial)

The hexagonal architecture is applied **only to external integration points** (AI, VCS, Skills), not to the entire application. Symfony and Doctrine are fixed choices.

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
│                                  └── SkillsShAdapter │
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

### Controllers
Controllers are **thin**: they decode the request, call a service, and return JSON.

When a Symfony method uses both a PHPDoc block and PHP attributes such as `#[Route(...)]`, keep the order:
- PHPDoc first
- attribute second
- method declaration last

This keeps the style consistent across controllers and makes reviews predictable.

```php
/**
 * Creates a project from the request payload.
 */
#[Route('/api/projects', methods: ['POST'])]
public function create(Request $request): JsonResponse
{
    $data    = $request->toArray();
    $project = $this->projectService->create($data['name'], $data['description'] ?? null);
    return $this->json(['id' => (string) $project->getId()], 201);
}
```

### TypeScript and React

JSDoc/TSDoc is mandatory on application code:
- every exported component, hook, function and utility
- every non-trivial internal helper

The intent is that reusable frontend code can be treated as a black box when needed:
- the documentation must explain the role
- the important inputs/outputs
- the side effects or behavioral constraints worth knowing before reuse

Avoid comments that merely paraphrase the code line by line, but do not omit JSDoc on the assumption that the implementation is "obvious enough".

### Translations

User-facing text must not be hardcoded in French in application source code.

Use Symfony translation keys and domains instead.

For the detailed domain/key conventions and the persisted-message strategy, use [`translations.md`](translations.md).

### Services
Services contain the business logic and call the repositories.

```php
public function create(string $name, ?string $description): Project
{
    $project = new Project($name, $description);
    $this->em->persist($project);
    $this->em->flush();
    $this->audit->log(AuditAction::ProjectCreated, 'Project', ...);
    return $project;
}
```

### Entities
Entities use **UUID v7** generated in the constructor, **Doctrine attributes** as metadata, and **lifecycle callbacks** for `updatedAt`.

```php
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        // ...
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
```

### Ports and Adapters
Adapters implement the port. Selection is handled via `AgentPortRegistry` (Symfony tagged services) for AI agents, and via direct injection for VCS.

### Development Command Rule

When a project script already exists in `scripts/`, use it in priority over direct container commands.

Examples:
- prefer `php scripts/console.php cache:clear` over `docker exec ... bin/console cache:clear`
- prefer `php scripts/logs.php worker` over raw `docker logs ...`

Direct Docker commands remain acceptable only when no script covers the operation.

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
