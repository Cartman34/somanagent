# Entités et modèle de données

> Voir aussi : [Architecture](architecture.md) · [API REST](api.md)

## Diagramme des relations

```
Project ─────── Module (1..n)
│                 status: active|archived
└─────────────── Feature (1..n)
                   status: open|in_progress|closed
                 └── Task (1..n, auto-référence parent/children)
                       type: user_story|bug|task
                       status: backlog|todo|in_progress|review|done|cancelled
                       priority: low|medium|high|critical
                       progress: 0-100
                       assignedAgent → Agent (nullable)
                       └── TaskLog (1..n)

Role ──────────── Skill (ManyToMany via role_skill)
  slug (unique)

Agent ──────────── Role (0..1, spécialisation fixe)
   connector: claude_api|claude_cli
   config: JSON (AgentConfig)
   └── Teams (ManyToMany via agent_team)

Team ──────────── Agent (ManyToMany via agent_team)

Workflow ────────── Team (0..1)
   └─── WorkflowStep (1..n)
          roleSlug → Role.slug, skillSlug → Skill.slug

ExternalReference  — lien hexagonal vers GitHub/GitLab/Jira
TokenUsage         — consommation tokens par appel IA
ChatMessage        — conversation humain ↔ agent dans un projet
AuditLog           — trace de toutes les actions (indépendant)
```

## Entités détaillées

### Project

| Colonne | Type | Contrainte | Description |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `name` | VARCHAR(255) | NOT NULL | Nom du projet |
| `description` | TEXT | NULL | Description |
| `repository_url` | VARCHAR(512) | NULL | URL du dépôt principal (monorepo) |
| `created_at` / `updated_at` | TIMESTAMP | NOT NULL | — |

Relations : `modules` → `Module[]` (OneToMany, cascade), `features` référencées via FK inverse.

---

### Module

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `name` | VARCHAR(255) | Nom du module |
| `repository_url` | VARCHAR(512) | URL du dépôt du module |
| `stack` | VARCHAR(255) | Stack technique |
| `status` | VARCHAR | `active` \| `archived` |

---

### Role

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `slug` | VARCHAR(100) | UNIQUE — identifiant utilisé dans les workflows |
| `name` | VARCHAR(255) | Nom affiché |
| `description` | TEXT | Description |

Relations : `skills` → `Skill[]` (ManyToMany via `role_skill`).

> Le rôle est la **spécialisation permanente** d'un agent. Un agent ne change pas de rôle.

---

### Agent

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `role_id` | UUID | FK → role (SET NULL, nullable) |
| `name` | VARCHAR(255) | Nom |
| `description` | TEXT | Description |
| `connector` | VARCHAR | `claude_api` \| `claude_cli` |
| `config` | JSON | Sérialisé depuis `AgentConfig` |
| `is_active` | BOOLEAN | Actif/inactif |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations : `teams` → `Team[]` (ManyToMany via `agent_team`).

---

### Team

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `name` | VARCHAR(255) | Nom |
| `description` | TEXT | Description |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations : `agents` → `Agent[]` (ManyToMany via `agent_team`).

---

### Feature

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `name` | VARCHAR(255) | Nom de la feature |
| `description` | TEXT | Description |
| `status` | VARCHAR | `open` \| `in_progress` \| `closed` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### Task

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `feature_id` | UUID | FK → feature (SET NULL, nullable) |
| `parent_id` | UUID | FK → task (CASCADE, nullable — sous-tâche) |
| `assigned_agent_id` | UUID | FK → agent (SET NULL, nullable) |
| `type` | VARCHAR | `user_story` \| `bug` \| `task` |
| `title` | VARCHAR(255) | Titre |
| `description` | TEXT | Description |
| `status` | VARCHAR | `backlog\|todo\|in_progress\|review\|done\|cancelled` |
| `priority` | VARCHAR | `low\|medium\|high\|critical` |
| `progress` | SMALLINT | Progression 0–100 |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### TaskLog

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `task_id` | UUID | FK → task (CASCADE) |
| `action` | VARCHAR(100) | Ex. `created`, `status_changed`, `validated` |
| `content` | TEXT | Détail du log |
| `created_at` | TIMESTAMP | — |

---

### ExternalReference

Table pivot hexagonale — les entités métier ne stockent pas d'ID externes directement.

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `entity_type` | VARCHAR(50) | `task` \| `feature` |
| `entity_id` | UUID | ID de l'entité interne |
| `system` | VARCHAR | `github` \| `gitlab` \| `jira` |
| `external_id` | VARCHAR(255) | ID côté système externe (numéro d'issue…) |
| `external_url` | VARCHAR(512) | URL directe vers l'issue |
| `metadata` | JSON | Labels, milestone, état GitHub, etc. |
| `synced_at` | TIMESTAMP | Dernière synchronisation |

Contrainte UNIQUE sur `(entity_type, entity_id, system)`.

---

### TokenUsage

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `agent_id` | UUID | FK → agent (SET NULL) |
| `task_id` | UUID | FK → task (SET NULL, nullable) |
| `workflow_step_id` | UUID | FK → workflow_step (SET NULL, nullable) |
| `model` | VARCHAR(100) | Modèle utilisé |
| `input_tokens` | INT | Tokens en entrée |
| `output_tokens` | INT | Tokens en sortie |
| `duration_ms` | INT | Durée de l'appel |
| `created_at` | TIMESTAMP | — |

---

### ChatMessage

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `agent_id` | UUID | FK → agent (CASCADE) |
| `author` | VARCHAR | `human` \| `agent` |
| `content` | TEXT | Contenu du message |
| `created_at` | TIMESTAMP | — |

---

### Skill

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `slug` | VARCHAR(255) | UNIQUE |
| `name` | VARCHAR(255) | Nom affiché |
| `description` | TEXT | Description courte |
| `source` | VARCHAR | `imported` \| `custom` |
| `content` | TEXT | Contenu complet SKILL.md |
| `file_path` | VARCHAR(512) | Chemin relatif depuis `skills/` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### AuditLog

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `action` | VARCHAR | Ex. `task.validated`, `team.agent.added` |
| `entity_type` | VARCHAR(100) | Ex. `Task`, `Team` |
| `entity_id` | VARCHAR(36) | UUID de l'entité concernée |
| `data` | JSON | Données contextuelles |
| `created_at` | TIMESTAMP | — |

---

## Tables de jointure

| Table | Relations |
|---|---|
| `agent_team` | Agent ↔ Team (ManyToMany) |
| `role_skill` | Role ↔ Skill (ManyToMany) |
