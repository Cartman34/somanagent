# AI Agents

> See also: [Key Concepts](concepts.md) · [Teams and Roles](teams-and-roles.md) · [Adapters](../technical/adapters.md)

## What is an agent?

An agent is a **configured AI instance**: it combines a connector (how to reach the AI), a model, and generation parameters. An agent can be assigned to a role within a team.

## Available Connectors

| Connector | Value | Description |
|---|---|---|
| Claude API | `claude_api` | HTTP calls to `api.anthropic.com` — requires `CLAUDE_API_KEY` |
| Claude CLI | `claude_cli` | Executes the locally installed `claude` binary — requires Claude Code |

## Creating an Agent

**Via the interface**: Agents → "New agent" → fill in the form

**Via the API**:
```http
POST /api/agents
Content-Type: application/json

{
  "name": "Claude Reviewer",
  "description": "Code review agent",
  "connector": "claude_api",
  "config": {
    "model": "claude-opus-4-5",
    "max_tokens": 8192,
    "temperature": 0.3,
    "timeout": 120
  },
  "roleId": "uuid-of-reviewer-role"
}
```

## Configuration (`AgentConfig`)

| Parameter | Type | Default | Description |
|---|---|---|---|
| `model` | string | — | AI model (e.g. `claude-sonnet-4-5`, `claude-opus-4-5`) |
| `max_tokens` | int | 8192 | Maximum number of tokens in the response |
| `temperature` | float | 0.7 | Creativity (0 = deterministic, 1 = creative) |
| `timeout` | int | 120 | Timeout in seconds |
| `extra` | object | {} | Additional connector-specific parameters |

### Recommendations by Use Case

| Use Case | Temperature | Suggested Model |
|---|---|---|
| Code review | 0.2 – 0.4 | `claude-opus-4-5` |
| Code generation | 0.5 – 0.7 | `claude-sonnet-4-5` |
| Documentation | 0.6 – 0.8 | `claude-sonnet-4-5` |
| Testing | 0.3 – 0.5 | `claude-sonnet-4-5` |

## Checking that a Connector is Reachable

```bash
php scripts/health.php
```

Or via the API:
```http
GET /api/health/connectors
```

Returns:
```json
{
  "status": "ok",
  "connectors": {
    "claude_api": true,
    "claude_cli": false
  }
}
```

## How an Agent Call Works

When a story is dispatched for execution:

1. SoManAgent identifies the agent assigned to the story's current status role
2. Retrieves the content of the associated SKILL.md
3. Builds a `Prompt` (skill + context + task instruction)
4. Sends it via the configured connector (`ClaudeApiAdapter` or `ClaudeCliAdapter`)
5. Receives an `AgentResponse` with the content + metadata (tokens, duration)
6. Records it in the audit log (via `TaskLog`)
7. For `tech-planning` skill: parses JSON output, creates subtasks + dependency DAG, sets branch name

→ See [Adapters](../technical/adapters.md) for implementation details.

## Agent Runtime Status

An agent's runtime status is derived from its task and log history:

| Status | Meaning |
|---|---|
| `working` | Has at least one `in_progress` task |
| `error` | Its latest execution-related signal on an assigned task is an error (`execution_error`, `planning_parse_error`) |
| `idle` | Neither of the above |

→ Endpoint: `GET /api/agents/{id}/status` (planned — Foundation F4)

→ See [Key Concepts — Agent Runtime Status](concepts.md#agent-runtime-status)
