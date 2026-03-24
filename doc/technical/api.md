# REST API Reference

> See also: [Architecture](architecture.md) · [Entities](entities.md)

**Base URL**: `http://localhost:8080/api`
**Format**: JSON (`Content-Type: application/json`)
**Authentication**: none (local access only)

---

## Health

### `GET /api/health`
Checks that the application is running.

**Response 200:**
```json
{ "status": "ok", "version": "1.0.0", "app": "SoManAgent" }
```

### `GET /api/health/connectors`
Checks the availability of each configured AI connector.

**Response 200 (all OK):**
```json
{ "status": "ok", "connectors": { "claude_api": true, "claude_cli": false } }
```
**Response 207 (degraded):** same format, `status: "degraded"`.

---

## Projects

### `GET /api/projects`
Lists all projects.

```json
[
  {
    "id": "019...",
    "name": "MonSaaS",
    "description": null,
    "modules": 3,
    "createdAt": "2026-03-24T10:00:00+00:00",
    "updatedAt": "2026-03-24T10:00:00+00:00"
  }
]
```

### `POST /api/projects`
Creates a project.

**Body:** `{ "name": "MonSaaS", "description": "..." }`
**Response 201:** `{ "id": "...", "name": "..." }`
**Error 422:** if `name` is missing.

### `GET /api/projects/{id}`
Project detail with its modules.

```json
{
  "id": "...",
  "name": "MonSaaS",
  "description": null,
  "modules": [
    {
      "id": "...",
      "name": "api-php",
      "description": null,
      "repositoryUrl": "https://github.com/user/api-php",
      "stack": "PHP 8.4, Symfony 7.2",
      "status": "active"
    }
  ],
  "createdAt": "...",
  "updatedAt": "..."
}
```

### `PUT /api/projects/{id}`
Updates a project. Body: `{ "name": "...", "description": "..." }`

### `DELETE /api/projects/{id}`
Deletes a project (cascades to its modules). **204 No Content.**

---

## Modules (sub-resource of Project)

### `POST /api/projects/{id}/modules`
Adds a module to a project.

**Body:**
```json
{
  "name": "api-php",
  "description": "REST API",
  "repositoryUrl": "https://github.com/user/api-php",
  "stack": "PHP 8.4, Symfony 7.2"
}
```
**Response 201:** `{ "id": "...", "name": "...", "repositoryUrl": "...", "status": "active" }`

### `PUT /api/projects/modules/{id}`
Updates a module. Same fields as creation.

### `DELETE /api/projects/modules/{id}`
Deletes a module. **204 No Content.**

---

## Teams

### `GET /api/teams`
Lists all teams (with role count).

### `POST /api/teams`
Creates a team. Body: `{ "name": "...", "description": "..." }`

### `GET /api/teams/{id}`
Detail with list of roles:
```json
{
  "id": "...",
  "name": "Web Dev Team",
  "roles": [
    { "id": "...", "name": "Reviewer", "description": "...", "skillSlug": "code-reviewer" }
  ]
}
```

### `PUT /api/teams/{id}` / `DELETE /api/teams/{id}`
Update / delete.

### `POST /api/teams/{id}/roles`
Adds a role. Body: `{ "name": "Reviewer", "description": "...", "skillSlug": "code-reviewer" }`

### `PUT /api/teams/roles/{id}` / `DELETE /api/teams/roles/{id}`
Update / delete a role.

---

## Agents

### `GET /api/agents`
Lists all agents.

```json
[{
  "id": "...",
  "name": "Claude Reviewer",
  "connector": "claude_api",
  "isActive": true,
  "role": { "id": "...", "name": "Reviewer" },
  "config": { "model": "claude-opus-4-5", "max_tokens": 8192, "temperature": 0.3, "timeout": 120 }
}]
```

### `POST /api/agents`
Creates an agent.

```json
{
  "name": "Claude Reviewer",
  "connector": "claude_api",
  "config": { "model": "claude-opus-4-5", "temperature": 0.3 },
  "roleId": "uuid-of-the-role"
}
```

### `GET /api/agents/{id}` · `PUT /api/agents/{id}` · `DELETE /api/agents/{id}`
Standard CRUD.

---

## Skills

### `GET /api/skills`
Lists all skills (without content for lighter payload).

```json
[{
  "id": "...",
  "slug": "code-reviewer",
  "name": "Code Reviewer",
  "source": "imported",
  "sourceLabel": "Imported (skills.sh)",
  "updatedAt": "..."
}]
```

### `GET /api/skills/{id}`
Detail with full SKILL.md content.

### `POST /api/skills/import`
Imports a skill from skills.sh.
Body: `{ "source": "anthropics/code-reviewer" }`

### `POST /api/skills`
Creates a custom skill.
Body: `{ "slug": "my-skill", "name": "...", "content": "---\n...", "description": "..." }`

### `PUT /api/skills/{id}/content`
Updates the SKILL.md content.
Body: `{ "content": "---\nname: ...\n---\n\n..." }`

### `DELETE /api/skills/{id}`
Deletes a skill. **204 No Content.**

---

## Error Codes

| Code | Meaning |
|---|---|
| 400 | Malformed request (invalid JSON) |
| 404 | Resource not found |
| 422 | Invalid data (missing required field) |
| 500 | Server error |

Error format:
```json
{ "error": "Message describing the error" }
```
