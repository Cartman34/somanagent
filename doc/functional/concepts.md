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

A **Story** (user story) or **Bug** is a ticket that progresses through the steps of its assigned workflow.

### Ticket progression

- the current progression state is stored on `Ticket.workflowStep`
- a ticket may expose manual next steps depending on the current workflow step
- agent execution depends on the current step and the tasks attached to that step

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
- Contains **ordered steps**
- Defines **allowed actions per step**
- Defines whether each step is **manual** or **automatic**

### Workflow Step

Each step defines:
- The **input source** (VCS diff, output of the previous step, manual)
- The **output_key**: name of the reusable output variable
- An optional **condition** (e.g. only execute if the previous step found errors)
- The **transition mode** (`manual` or `automatic`)
- The list of allowed **AgentAction** entries
- Which actions are created automatically with the ticket via `createWithTicket`

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

## Workflow Template vs Ticket Runtime

These are two distinct concepts:

| Concept | What it is | Stored where |
|---|---|---|
| **Workflow** | A reusable automation template | `Workflow` + `WorkflowStep` entities |
| **Ticket progression** | The current step of a specific ticket | `Ticket.workflowStep` |

A workflow describes *how* work is structured. A ticket progression describes *where this ticket currently is* in that structure.

---

## Relationship Summary

```
Project
  └── Module (1..n)
  └── Team (1) ← planned (F1)

Team
  └── Role (1..n)
        └── Skills (0..n)

Agent
  └── Role (optional)
  └── ConnectorType → AI Adapter
  └── RuntimeStatus (derived: working / error / idle)

Ticket
  └── WorkflowStep (current step)
  └── TicketTask (operational work)
        ├── TicketTaskDependency (DAG)
        ├── TicketLog (narrative history)
        └── AgentTaskExecution (technical execution history)

Workflow (template)
  └── WorkflowStep (1..n)
        └── WorkflowStepAction → AgentAction
```

---

## Roadmap

| ID | Description | Status |
|---|---|---|
| F1 | Link Project → Team (DB migration) | Planned |
| F2 | Scope agent search to project's team | Planned |
| F3 | Drive story execution from workflow steps | Planned |
| F4 | Agent runtime status endpoint | Planned |
