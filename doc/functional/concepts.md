# Key Concepts

> See also: [Overview](overview.md) · [Teams and Roles](teams-and-roles.md) · [Skills](skills.md) · [Agents](agents.md) · [Workflows](workflows.md)

This glossary precisely defines all terms used in SoManAgent.

---

## Project

A **Project** represents an overall software product (e.g. "MySaaS", "MobileApp").

- A project has a name and an optional description
- A project contains one or more **Modules**
- A project can be associated with one or more **Teams**

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

## Relationship Summary

```
Project
  └── Module (1..n)

Team
  └── Role (1..n)
        └── skillSlug → Skill

Agent
  └── Role (optional)
  └── ConnectorType → AI Adapter

Workflow
  └── Team
  └── WorkflowStep (1..n)
        ├── roleSlug  → Role → Agent
        └── skillSlug → Skill
```
