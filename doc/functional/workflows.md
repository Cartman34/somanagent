# Workflows

> See also: [Key Concepts](concepts.md) · [Teams and Roles](teams-and-roles.md) · [Agents](agents.md) · [Skills](skills.md)

## What is a workflow?

A workflow is a **reusable automation template** — a sequence of steps executed by agents in a defined order. Each step assigns a task to an agent (identified by its role), providing the right skill and the right context.

> **Important distinction:** A Workflow is a *template* that defines *how* an automation runs. It is not the same as a Story's lifecycle. A Story has a `storyStatus` field that tracks *where it is* in development. Each workflow step has a `storyStatusTrigger` field that links it to the appropriate stage of the story lifecycle.

→ See [Key Concepts — Workflow Template vs Story Lifecycle](concepts.md#workflow-template-vs-story-lifecycle)

## Triggers

| Value | Description |
|---|---|
| `manual` | Launched manually from the interface or the API |
| `vcs_event` | Triggered by a Git event (PR, MR opened) |
| `scheduled` | Scheduled (future) |

## Anatomy of a Workflow

```
Workflow "Code Review"
├── trigger: manual
├── team: Web Development Team
└── Steps:
    ├── [1] Analyse the diff
    │       role: reviewer
    │       skill: code-reviewer
    │       input: VCS diff of the PR
    │       output_key: review_report
    │
    ├── [2] Fix the issues
    │       role: backend-dev
    │       skill: backend-dev
    │       input: review_report (previous step)
    │       condition: "review_report contains critical errors"
    │       output_key: fixed_code
    │
    └── [3] Validate the fixes
            role: reviewer
            skill: code-reviewer
            input: fixed_code
            output_key: validation_report
```

## Creating a Workflow

**Via the interface**: Workflows → "New workflow" → add steps

**Via the API**:
```http
POST /api/workflows
Content-Type: application/json

{
  "name": "Code Review",
  "description": "Analyses a PR and suggests fixes",
  "trigger": "manual",
  "teamId": "uuid-of-the-team"
}
```

Then add the steps (to be defined).

## Step Configuration

| Field | Type | Description |
|---|---|---|
| `stepOrder` | int | Position in the sequence (1, 2, 3…) |
| `name` | string | Step label |
| `roleSlug` | string | Slug of the role executing the step |
| `skillSlug` | string | Skill to inject into the prompt |
| `inputConfig` | object | Input source and format |
| `outputKey` | string | Output variable name |
| `condition` | string | Execution condition (null = always) |

### Input Sources (`inputConfig`)

```json
{ "source": "vcs", "type": "pr_diff" }          // Diff of a PR/MR
{ "source": "previous_step", "key": "review_report" }  // Output of a previous step
{ "source": "manual", "prompt": "Your text..." }        // Manually entered text
```

## Workflow Status

A workflow has a lifecycle of its own, independent of the story lifecycle:

| Status | Description | Editable | Usable |
|---|---|---|---|
| `draft` | Being configured | ✅ Yes | ❌ No |
| `validated` | Ready for story execution | ❌ No | ✅ Yes |
| `locked` | Locked (currently executing) | ❌ No | ✅ Yes |

**Validating a workflow:** On the workflow detail page, a "Valider" button appears when the status is `draft`. Clicking it calls `POST /api/workflows/{id}/validate` and transitions the workflow to `validated`.

## Visual Lifecycle Pipeline

The workflow detail page displays a **visual pipeline** showing the full story lifecycle (`new → ready → approved → planning → graphic_design → development → code_review → done`). Each stage where a workflow step has a matching `storyStatusTrigger` is highlighted (brand colour), showing the assigned role and skill. Stages without a matching step appear as muted numbered nodes.

This gives a clear overview of which stages are automated and which require manual intervention.

## Step Fields

Each step includes:
- `storyStatusTrigger` — the story status that triggers this step (e.g. `approved`, `development`)
- `status` — step execution state (`pending`, `running`, `done`, `error`, `skipped`)
- `lastOutput` — last output produced by this step

## Step Statuses

| Status | Description |
|---|---|
| `pending` | Waiting to be executed |
| `running` | Currently executing |
| `done` | Completed successfully |
| `error` | Error during execution |
| `skipped` | Skipped (condition not met) |

## Dry-Run Mode

Dry-run mode lets you **simulate a workflow** without sending requests to AI agents. Useful for validating a workflow's configuration before running it for real.

```http
POST /api/workflows/{id}/run
Content-Type: application/json

{ "dryRun": true }
```

In dry-run mode:
- Steps transition to `done` status with a fictitious output
- No API calls to Claude are made
- The audit log records `workflow.dry_run`

## Audit Log

Each workflow execution generates entries in the log:
- `workflow.run` — started
- `workflow.step.completed` — step completed
- `workflow.step.failed` — step failed
- `workflow.completed` / `workflow.failed` — finished

→ Viewable via `GET /api/audit` or in the interface.
