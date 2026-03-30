# Key Concepts

> See also: [Overview](overview.md) · [Teams and Roles](teams-and-roles.md) · [Skills](skills.md) · [Agents](agents.md) · [Workflows](workflows.md)

This glossary precisely defines all terms used in SoManAgent.

---

## Project

A **Project** represents an overall software product (e.g. "MySaaS", "MobileApp").

- A project has a name and an optional description
- A project contains one or more **Modules**
- A project can exist without a **Team**, but it must have one before any progression or agent execution can happen

> **Current rule:** team assignment is optional at project creation time, but mandatory before advancing the project through story progression or agent execution.

---

## Module

A **Module** is an independent software component of a Project.

Examples for the project "MySaaS":
- `api-php` — the PHP REST API
- `app-android` — the Android client
- `app-ios` — the iOS client
- `backoffice` — the admin dashboard

Each Module:
- Has its own **Git repository** (configured URL)
- Has its own **tech stack** (e.g. "PHP 8.4, Symfony 7.2")
- Has a **status**: `active` or `archived`

---

## Team

A **Team** is a group of **Roles** to which **Agents** can be assigned.

Teams are **generic**: a "Web Dev Team" can be reused across multiple projects. It defines *who* works and *how*, independently of any project.

---

## Role

A **Role** defines a responsibility within a Team.

Examples: Tech Lead, Backend Developer, Reviewer, QA, DevOps.

A Role:
- Belongs to a Team
- Has an associated **skill** (slug of the SKILL.md to use)
- Is the target of a Workflow step

---

## Agent

An **Agent** is a configured AI instance, ready to receive tasks.

An Agent:
- Has a **connector**: `claude_api` (HTTP) or `claude_cli` (local binary)
- Has a **configuration**: model, temperature, max_tokens, timeout
- Can be assigned to a **Role**
- Has an active/inactive status

An Agent corresponds to "an AI with its parameters". Multiple agents can use the same connector with different configurations (e.g. a "creative" agent with high temperature, a "precise" agent with low temperature).

### Agent Runtime Status

An agent's runtime status is **derived** from its task and log history — no dedicated field is stored:

| Status | Condition |
|---|---|
| `working` | Has at least one task with status `in_progress` |
| `error` | Its latest execution-related signal on an assigned task is an error (`execution_error`, `planning_parse_error`) |
| `idle` | Neither of the above |

→ Endpoint: `GET /api/agents/{id}/status` (planned — Foundation F4)

---

## Story / Bug

A **Story** (user story) or **Bug** is a task of type `story` or `bug`. Unlike regular tasks, stories follow a structured lifecycle managed by a **story status** (`StoryStatus`).

### Story Lifecycle

| StoryStatus | Description | Agent execution available |
|---|---|---|
| `new` | Just created, not yet ready for work | No |
| `ready` | Ready to be estimated/approved | No |
| `approved` | Approved by PO — triggers tech planning | Yes (lead-tech / tech-planning) |
| `planning` | Tech planning in progress | No (agent is working) |
| `graphic_design` | UI/UX design phase | Yes (ui-ux-designer / ui-design) |
| `development` | Active development | Yes (php-dev / php-backend-dev) |
| `code_review` | Code review phase | Yes (lead-tech / code-reviewer) |
| `done` | Fully completed | No |

Status transitions are validated server-side via `StoryStatus::allowedTransitions()`.

---

## Skill

A **Skill** is a `SKILL.md` file that contains the **instructions** given to an agent for a type of task.

Format (compatible with [skills.sh](https://skills.sh)):

```markdown
---
name: code-reviewer
description: Review code for quality, security and best practices
---

## Instructions
When you analyse code, you must...
```

A Skill:
- Has a unique **slug** (e.g. `code-reviewer`)
- Has a **source**: `imported` (from skills.sh) or `custom` (created locally)
- Is stored on disk in `skills/imported/` or `skills/custom/`
- Can be **edited locally** even if it was imported

---

## Workflow

A **Workflow** is a sequence of **steps** to be executed by agents.

A Workflow:
- Has a **trigger**: `manual`, `vcs_event` (PR/MR), or `scheduled`
- Is associated with a **Team** (to resolve roles → agents)
- Contains **ordered steps**

### Workflow Step

Each step defines:
- The **role** that executes it (e.g. "Reviewer")
- The **skill** to use
- The **input source** (VCS diff, output of the previous step, manual)
- The **output_key**: name of the reusable output variable
- An optional **condition** (e.g. only execute if the previous step found errors)

---

## Connector

A **Connector** defines *how* SoManAgent communicates with the AI.

| Connector | Method | Use case |
|---|---|---|
| `claude_api` | HTTP to api.anthropic.com | Server-side usage, without interface |
| `claude_cli` | Local `claude` binary | Claude Code installed on the machine |

→ See [Adapters](../technical/adapters.md) for technical details.

---

## Audit Log

Every important action in SoManAgent (creation, modification, workflow execution, skill import…) generates an entry in the **audit log**.

The log can be consulted via the web interface or the API (`GET /api/audit`).

---

## Workflow Template vs Story Lifecycle

These are two distinct concepts that are **not the same**:

| Concept | What it is | Stored where |
|---|---|---|
| **Workflow** | A reusable automation template (e.g. "Code Review") | `Workflow` + `WorkflowStep` entities |
| **Story Lifecycle** | The progression states of a specific story | `Ticket.storyStatus` (enum) |

A Workflow describes *how* a type of automation runs (steps, roles, conditions). A Story's lifecycle describes *where it is* in its development journey. In a future milestone, the story lifecycle will be **driven by workflow steps** instead of the current hardcoded mapping in `StoryExecutionService`.

---

## Relationship Summary

```
Project
  └── Module (1..n)
  └── Team (1) ← planned (F1)

Team
  └── Role (1..n)
        └── skillSlug → Skill

Agent
  └── Role (optional)
  └── ConnectorType → AI Adapter
  └── RuntimeStatus (derived: working / error / idle)

Ticket
  └── StoryStatus (lifecycle)
  └── TicketTask (operational work)
        ├── TicketTaskDependency (DAG)
        ├── TicketLog (narrative history)
        └── AgentTaskExecution (technical execution history)

Workflow (template)
  └── Team
  └── WorkflowStep (1..n)
        ├── roleSlug  → Role → Agent
        └── skillSlug → Skill
```

---

## Roadmap

| ID | Description | Status |
|---|---|---|
| F1 | Link Project → Team (DB migration) | Planned |
| F2 | Scope agent search to project's team | Planned |
| F3 | Drive story execution from workflow steps | Planned |
| F4 | Agent runtime status endpoint | Planned |
