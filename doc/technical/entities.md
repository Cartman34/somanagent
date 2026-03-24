# Entities and Data Model

> See also: [Architecture](architecture.md) · [REST API](api.md)

## Relationship Diagram

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

AuditLog (independent — traces all actions)
```

## Detailed Entities

### Project

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 identifier |
| `name` | VARCHAR(255) | NOT NULL | Project name |
| `description` | TEXT | NULL | Long description |
| `created_at` | TIMESTAMP | NOT NULL | Creation date |
| `updated_at` | TIMESTAMP | NOT NULL | Last modified date |

Relations: `modules` → `Module[]` (OneToMany, cascade remove)

---

### Module

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `project_id` | UUID | FK → project (CASCADE) |
| `name` | VARCHAR(255) | Module name |
| `description` | TEXT | Description |
| `repository_url` | VARCHAR(512) | Git repository URL |
| `stack` | VARCHAR(255) | Tech stack (e.g. "PHP 8.4, Symfony") |
| `status` | VARCHAR(50) | `active` \| `archived` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### Team

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `name` | VARCHAR(255) | Team name |
| `description` | TEXT | Description |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations: `roles` → `Role[]` (OneToMany, cascade remove)

---

### Role

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `team_id` | UUID | FK → team (CASCADE) |
| `name` | VARCHAR(255) | Role name |
| `description` | TEXT | Description |
| `skill_slug` | VARCHAR(255) | Associated skill slug (nullable) |

---

### Agent

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `role_id` | UUID | FK → role (SET NULL, nullable) |
| `name` | VARCHAR(255) | Agent name |
| `description` | TEXT | Description |
| `connector` | VARCHAR(50) | `claude_api` \| `claude_cli` |
| `config` | JSON | Serialised from `AgentConfig` |
| `is_active` | BOOLEAN | Active/inactive |
| `created_at` / `updated_at` | TIMESTAMP | — |

JSON structure of `config`:
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

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `slug` | VARCHAR(255) | UNIQUE — identifier (e.g. `code-reviewer`) |
| `name` | VARCHAR(255) | Display name |
| `description` | TEXT | Short description |
| `source` | VARCHAR(50) | `imported` \| `custom` |
| `original_source` | VARCHAR(255) | Import source (e.g. `anthropics/skills`) |
| `content` | TEXT | Full content of the SKILL.md |
| `file_path` | VARCHAR(512) | Relative path from `skills/` |
| `created_at` / `updated_at` | TIMESTAMP | — |

---

### Workflow

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `team_id` | UUID | FK → team (SET NULL, nullable) |
| `name` | VARCHAR(255) | Workflow name |
| `description` | TEXT | Description |
| `trigger` | VARCHAR(50) | `manual` \| `vcs_event` \| `scheduled` |
| `is_active` | BOOLEAN | Active/inactive |
| `created_at` / `updated_at` | TIMESTAMP | — |

Relations: `steps` → `WorkflowStep[]` (OneToMany, cascade remove, ordered by `step_order`)

---

### WorkflowStep

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `workflow_id` | UUID | FK → workflow (CASCADE) |
| `step_order` | INT | Position in the sequence |
| `name` | VARCHAR(255) | Step label |
| `role_slug` | VARCHAR(255) | Executing role slug (nullable) |
| `skill_slug` | VARCHAR(255) | Skill to use (nullable) |
| `input_config` | JSON | Input configuration |
| `output_key` | VARCHAR(255) | Output key |
| `condition` | TEXT | Execution condition (nullable) |
| `status` | VARCHAR(50) | `pending\|running\|done\|error\|skipped` |
| `last_output` | TEXT | Output of the last execution |

---

### AuditLog

| Column | Type | Description |
|---|---|---|
| `id` | UUID | PK |
| `action` | VARCHAR(100) | E.g. `project.created`, `workflow.run` |
| `entity_type` | VARCHAR(100) | E.g. `Project`, `Skill` |
| `entity_id` | VARCHAR(36) | UUID of the concerned entity |
| `data` | JSON | Contextual data (nullable) |
| `created_at` | TIMESTAMP | Action date |

Indexes: `action`, `(entity_type, entity_id)`, `created_at`

## Value Objects (not persisted standalone)

### AgentConfig
Immutable object serialised as JSON in `agent.config`.
Properties: `model`, `maxTokens`, `temperature`, `timeout`, `extraParams`.

### Prompt
Built on the fly during step execution.
Assembles: skill content + context + task instruction.

### AgentResponse
Returned by an AI adapter.
Contains: `content`, `inputTokens`, `outputTokens`, `durationMs`, `metadata`.
