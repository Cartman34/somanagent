# Skills

> See also: [Key Concepts](concepts.md) · [Agents](agents.md) · [Workflows](workflows.md)

## What is a skill?

A skill is a `SKILL.md` file that contains the instructions given to an AI agent to accomplish a specific type of task. It follows the format of [skills.sh](https://skills.sh) (Vercel's open ecosystem).

### SKILL.md Format

```markdown
---
name: code-reviewer
description: Review code for quality, security and best practices
---

## Instructions

You are an expert code reviewer. When you analyse submitted code, you must:

1. Identify potential bugs
2. Check language best practices
3. Flag security issues
4. Suggest readability improvements

## Response Format

Return your review in this format:
- **Critical**: blocking issues
- **Warning**: points to improve
- **Suggestion**: optional optimisations
```

The YAML frontmatter (between `---`) contains the metadata. The Markdown body contains the instructions.

## Importing a Skill from skills.sh

**Via the interface**: Skill catalogue → "Import from skills.sh" → enter `owner/skill-name`

**Via the API**:
```http
POST /api/skills/import
Content-Type: application/json

{ "source": "anthropics/code-reviewer" }
```

**Via the command**:
```bash
php scripts/console.php somanagent:skill:import anthropics/code-reviewer
```

The skill is:
1. Downloaded via `npx skills add` into `skills/imported/`
2. Parsed (frontmatter + content)
3. Saved to the database

## Creating a Custom Skill

**Via the interface**: Skill catalogue → "New skill" → fill in the form + built-in Markdown editor

**Via the API**:
```http
POST /api/skills
Content-Type: application/json

{
  "slug": "my-skill",
  "name": "My custom skill",
  "description": "Short description",
  "content": "---\nname: my-skill\n...\n---\n\n## Instructions\n..."
}
```

The file is created at `skills/custom/my-skill/SKILL.md`.

## Editing a Skill

Any skill (imported or custom) can be edited locally. Changes are:
- Saved to the database
- Written to the corresponding `SKILL.md` file

```http
PATCH /api/skills/{id}/content
Content-Type: application/json

{ "content": "---\nname: ...\n---\n\n## Modified instructions..." }
```

## On-Disk Organisation

```
skills/
├── imported/
│   └── code-reviewer/
│       └── SKILL.md       ← imported from skills.sh
└── custom/
    └── my-skill/
        └── SKILL.md       ← created in SoManAgent
```

## Skills, Roles, and Actions

A skill may be attached to one or more roles as a compatibility hint, but workflow runtime does not route from the role alone.

Current runtime model:
- `WorkflowStepAction` declares which `AgentAction` entries are allowed in each workflow step
- `AgentAction` carries the concrete routing requirements (`role` and optional `skill`)
- task execution resolves the skill from the task's `AgentAction`

```
WorkflowStepAction → AgentAction(review.code)
                        ├── role: Reviewer
                        └── skill: code-reviewer
```

→ See [Teams and Roles](teams-and-roles.md) for role skill management.
→ See [Adapters](../technical/adapters.md) for the prompt injection mechanism.
