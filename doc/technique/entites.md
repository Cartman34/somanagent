# Entités et modèle de données

> Voir aussi : [Architecture](architecture.md) · [API REST](api.md)

## Diagramme de relations

```
Project ─────── Module (1..n)
                  status: active|archived

Team ──────────── Role (1..n)
                    skillSlug → Skill.slug

Agent ──────────── Role (0..1)
                    connector: claude_api|claude_cli
                    config: JSON (AgentConfig)

Workflow ────────── Team (0..1)
   └─── WorkflowStep (1..n)
          roleSlug, skillSlug, inputConfig, outputKey, condition, status

AuditLog (indépendant — trace toutes les actions)
```

## Entités détaillées

### Project

| Colonne | Type | Contrainte | Description |
|---|---|---|---|
| `id` | UUID | PK | Identifiant UUID v7 |
| `name` | VARCHAR(255) | NOT NULL | Nom du projet |
| `description` | TEXT | NULL | Description longue |
| `created_at` | TIMESTAMP | NOT NULL | Date de création |
| `updated_at` | TIMESTAMP | NOT NULL | Dernière modification |

Relations : `modules` → `Module[]` (OneToMany, cascade remove)

---

### Module

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `name` | VARCHAR(255) | Nom du module |
| `description` | TEXT | Description |
| `repository_url` | VARCHAR(512) | URL du dépôt Git |
| `stack` | VARCHAR(255) | Stack technique (ex: "PHP 8.4, Symfony") |
| `status` | VARCHAR(50) | `active` \| `archived` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### Team

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `name` | VARCHAR(255) | Nom de l'équipe |
| `description` | TEXT | Description |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations : `roles` → `Role[]` (OneToMany, cascade remove)

---

### Role

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `team_id` | UUID | FK → team (CASCADE) |
| `name` | VARCHAR(255) | Nom du rôle |
| `description` | TEXT | Description |
| `skill_slug` | VARCHAR(255) | Slug du skill associé (nullable) |

---

### Agent

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `role_id` | UUID | FK → role (SET NULL, nullable) |
| `name` | VARCHAR(255) | Nom de l'agent |
| `description` | TEXT | Description |
| `connector` | VARCHAR(50) | `claude_api` \| `claude_cli` |
| `config` | JSON | Sérialisé depuis `AgentConfig` |
| `is_active` | BOOLEAN | Actif/inactif |
| `created_at` / `updated_at` | TIMESTAMP | — |

Structure JSON de `config` :
```json
{
  "model": "claude-sonnet-4-5",
  "max_tokens": 8192,
  "temperature": 0.7,
  "timeout": 120,
  "extra": {}
}
```

---

### Skill

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `slug` | VARCHAR(255) | UNIQUE — identifiant (ex: `code-reviewer`) |
| `name` | VARCHAR(255) | Nom affiché |
| `description` | TEXT | Description courte |
| `source` | VARCHAR(50) | `imported` \| `custom` |
| `original_source` | VARCHAR(255) | Source d'import (ex: `anthropics/skills`) |
| `content` | TEXT | Contenu complet du SKILL.md |
| `file_path` | VARCHAR(512) | Chemin relatif depuis `skills/` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### Workflow

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `team_id` | UUID | FK → team (SET NULL, nullable) |
| `name` | VARCHAR(255) | Nom du workflow |
| `description` | TEXT | Description |
| `trigger` | VARCHAR(50) | `manual` \| `vcs_event` \| `scheduled` |
| `is_active` | BOOLEAN | Actif/inactif |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations : `steps` → `WorkflowStep[]` (OneToMany, cascade remove, trié par `step_order`)

---

### WorkflowStep

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `workflow_id` | UUID | FK → workflow (CASCADE) |
| `step_order` | INT | Position dans la séquence |
| `name` | VARCHAR(255) | Libellé de l'étape |
| `role_slug` | VARCHAR(255) | Slug du rôle exécutant (nullable) |
| `skill_slug` | VARCHAR(255) | Skill à utiliser (nullable) |
| `input_config` | JSON | Configuration de l'input |
| `output_key` | VARCHAR(255) | Clé de sortie |
| `condition` | TEXT | Condition d'exécution (nullable) |
| `status` | VARCHAR(50) | `pending\|running\|done\|error\|skipped` |
| `last_output` | TEXT | Sortie de la dernière exécution |

---

### AuditLog

| Colonne | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `action` | VARCHAR(100) | Ex: `project.created`, `workflow.run` |
| `entity_type` | VARCHAR(100) | Ex: `Project`, `Skill` |
| `entity_id` | VARCHAR(36) | UUID de l'entité concernée |
| `data` | JSON | Données contextuelles (nullable) |
| `created_at` | TIMESTAMP | Date de l'action |

Index : `action`, `(entity_type, entity_id)`, `created_at`

## Value Objects (non persistés seuls)

### AgentConfig
Objet immuable sérialisé en JSON dans `agent.config`.
Propriétés : `model`, `maxTokens`, `temperature`, `timeout`, `extraParams`.

### Prompt
Construit à la volée lors de l'exécution d'une étape.
Assemble : contenu du skill + contexte + instruction de la tâche.

### AgentResponse
Retourné par un adapter IA.
Contient : `content`, `inputTokens`, `outputTokens`, `durationMs`, `metadata`.
