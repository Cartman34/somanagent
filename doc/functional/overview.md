# SoManAgent Overview

> See also: [Key Concepts](concepts.md) · [Teams and Roles](teams-and-roles.md) · [Workflows](workflows.md)

## What is SoManAgent?

SoManAgent is a local web application that lets you **manage teams of AI agents** for software development. It acts as an orchestrator between:

- **You** (the developer) — who define projects, teams, and tasks
- **AI agents** (Claude, etc.) — who execute tasks (coding, reviewing, testing…)
- **Your tools** (GitHub/GitLab) — with which the agents interact

## Problem Solved

Developing software with AI agents raises several practical questions:
- How do you organise multiple agents with different roles?
- How do you give them consistent, reusable instructions?
- How do you track what they do?
- How do you switch AI providers without reconfiguring everything?

SoManAgent answers these questions with a structured interface.

## How It Works at a Glance

```
You create a Project
       │
       ├── Modules (e.g. api-php, app-mobile)
       │
       └── You assign a Team
                   │
                   ├── Roles (Tech Lead, Backend Dev, Reviewer…)
                   │         │
                   │         └── each has a Skill (SKILL.md instructions)
                   │
                   └── Agents (configured AI instances)
                               │
                               └── connected via Claude API or Claude CLI
```

You then launch a **Workflow**: a sequence of steps that put the agents to work in a defined order, with the right skills, on the right context.

## Concrete Example

> "I want an automatic code review on every Pull Request."

1. **Create a team** "Web Dev Team" with a "Reviewer" role
2. **Import a skill** `code-reviewer` from skills.sh
3. **Assign the skill** to the Reviewer role
4. **Configure an agent** Claude connected via API
5. **Create a workflow** "PR Review" with a step that sends the diff to the Reviewer
6. **Run the workflow** → the agent analyses the diff and returns its comments

## What SoManAgent Is Not

- It is not a code execution environment (no built-in CI/CD)
- It is not a deployment tool
- It is not a cloud service — it runs locally on your machine

## Interface

SoManAgent provides:
- A **web interface** (React) for visual configuration
- A **REST API** for integration with other tools
- **Symfony commands** for automation

→ See [Key Concepts](concepts.md) for a precise definition of each term.
→ See [Installation](../development/installation.md) to get started.
