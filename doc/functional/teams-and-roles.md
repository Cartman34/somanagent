# Teams and Roles

> See also: [Key Concepts](concepts.md) · [Agents](agents.md) · [Skills](skills.md) · [Workflows](workflows.md)

## Creating a Team

A team can be created from the web interface (`/teams → New team`) or via the API:

```http
POST /api/teams
Content-Type: application/json

{
  "name": "Web Development Team",
  "description": "Full-stack web development team"
}
```

Teams are **generic**: they are not tied to a specific project and can be reused.

## Managing a Team's Roles

From the team detail page, you can add, edit, and delete roles directly through the interface.

```http
POST /api/teams/{id}/roles
Content-Type: application/json

{
  "name": "Reviewer",
  "description": "Code review and quality control"
}
```

Role-to-skill association is managed separately.

Current model:
- a role may reference multiple compatible skills
- workflow routing does not come from the role
- runtime routing comes from `AgentAction` (`requiredRole` + `requiredSkill`)

Skill assignment endpoints:

```http
POST /api/roles/{id}/skills
Content-Type: application/json

{ "skillId": "..." }
```

## Web Development Team Example

SoManAgent includes a command to create the example team:

```bash
php scripts/console.php somanagent:seed:web-team
```

It creates the team with 6 roles:

| Role | Associated Skill | Responsibility |
|---|---|---|
| Tech Lead | `architect` | Architecture, technical decisions |
| Backend Developer | `backend-dev` | Server code, API |
| Frontend Developer | `frontend-dev` | UI, integration |
| Reviewer | `code-reviewer` | Code review, quality |
| QA | `qa-tester` | Testing, validation |
| DevOps | `devops` | CI/CD, infrastructure |

## Assigning an Agent to a Role

An agent can be assigned to a role from its configuration page. When a task executes, SoManAgent resolves eligible agents from the `AgentAction` role inside the relevant project team.

→ See [Agents](agents.md) to configure an agent.

## Exporting / Importing a Team

> Feature planned for a future version. Teams are currently managed exclusively through the interface.

The YAML export of a team will have the following format:

```yaml
name: "Web Development Team"
description: "..."
roles:
  - name: "Reviewer"
    description: "Code review"
    skills:
      - "code-reviewer"
```
