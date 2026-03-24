# Architecture technique

> Voir aussi : [Entités](entites.md) · [Adaptateurs](adaptateurs.md) · [API REST](api.md)

## Vue d'ensemble

SoManAgent est une application **full-stack** composée de :
- Un **backend PHP** (Symfony 7.2) exposant une API REST
- Un **frontend React + TypeScript** (Vite)
- Une **base de données PostgreSQL** 16
- Un dossier `skills/` contenant les fichiers SKILL.md

## Architecture hexagonale (partielle)

L'architecture hexagonale est appliquée **uniquement aux points d'intégration externe** (IA, VCS, Skills), pas à l'ensemble de l'application. Symfony et Doctrine sont des choix fixes.

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

Les **Ports** sont des interfaces PHP (`src/Port/`). Les **Adapters** sont leurs implémentations (`src/Adapter/`). Symfony injecte le bon adapter via la configuration `services.yaml`.

## Structure du backend (`backend/src/`)

```
src/
├── Entity/          Entités Doctrine (9 classes)
│                    Project, Module, Team, Role, Agent,
│                    Skill, Workflow, WorkflowStep, AuditLog
│
├── Enum/            PHP Backed Enums (6)
│                    ConnectorType, ModuleStatus, SkillSource,
│                    WorkflowTrigger, WorkflowStepStatus, AuditAction
│
├── ValueObject/     Objets immuables non persistés
│                    AgentConfig, Prompt, AgentResponse
│
├── Repository/      Doctrine ServiceEntityRepository (9)
│
├── Port/            Interfaces hexagonales (3)
│                    AgentPort, VCSPort, SkillPort
│
├── Adapter/         Implémentations concrètes
│   ├── AI/          ClaudeApiAdapter, ClaudeCliAdapter
│   ├── VCS/         GitHubAdapter, GitLabAdapter
│   └── Skill/       SkillsShAdapter
│
├── Service/         Logique métier
│                    ProjectService, TeamService, AgentService,
│                    SkillService, AuditService, AgentPortRegistry
│
├── Controller/      Endpoints REST (thin controllers)
│                    HealthController, ProjectController, TeamController,
│                    AgentController, SkillController
│
├── Command/         Commandes Symfony console
│                    HealthCheckCommand, ImportSkillCommand, SeedWebTeamCommand
│
└── ValueObject/     (voir ci-dessus)
```

## Conventions de code

### Controllers
Les controllers sont **fins** : ils décodent la requête, appellent un service, retournent JSON.

```php
#[Route('/api/projects', methods: ['POST'])]
public function create(Request $request): JsonResponse
{
    $data    = $request->toArray();
    $project = $this->projectService->create($data['name'], $data['description'] ?? null);
    return $this->json(['id' => (string) $project->getId()], 201);
}
```

### Services
Les services contiennent la logique métier et appellent les repositories.

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

### Entités
Les entités utilisent des **UUID v7** générés dans le constructeur, des **attributs Doctrine** comme métadonnées, et des **lifecycle callbacks** pour `updatedAt`.

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

### Ports et Adapters
Les adapters implémentent le port. La sélection se fait via `AgentPortRegistry` (tagged services Symfony) pour les agents IA, et via injection directe pour VCS.

## Stack technique

| Composant | Technologie | Version |
|---|---|---|
| Backend | PHP + Symfony | 8.4 / 7.2 |
| ORM | Doctrine | 3.x |
| Base de données | PostgreSQL | 16 |
| Frontend | React + TypeScript | 18 / 5 |
| Build frontend | Vite | 5 |
| HTTP client | Guzzle | 7 |
| Conteneurisation | Docker + Compose | — |

## Flux de données

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
     └── Response JSON
```
