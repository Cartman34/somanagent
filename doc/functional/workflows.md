# Workflows

> See also: [Key Concepts](concepts.md) · [Teams and Roles](teams-and-roles.md) · [Agents](agents.md) · [Skills](skills.md)

## What is a workflow?

A workflow is a reusable definition of ticket progression. It describes ordered steps, the transition mode of each step, and which agent actions are allowed in each step.

The current ticket progression is stored directly on `Ticket.workflowStep`.

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
└── Steps:
    ├── [1] Analyse the diff
    │       transition: manual
    │       actions:
    │         - review.code
    │       input: VCS diff of the PR
    │       output_key: review_report
    │
    ├── [2] Fix the issues
    │       transition: automatic
    │       actions:
    │         - dev.backend.implement
    │         - dev.frontend.implement
    │       input: review_report (previous step)
    │       condition: "review_report contains critical errors"
    │       output_key: fixed_code
    │
    └── [3] Validate the fixes
            transition: manual
            actions:
              - qa.validate
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
  "trigger": "manual"
}
```

The workflow becomes immutable as soon as it is created:
- no update
- no delete
- no manual validation step

If the process needs to evolve, duplicate the workflow and work from the new copy instead of editing the existing definition in place.

## Step Configuration

| Field | Type | Description |
|---|---|---|
| `stepOrder` | int | Position in the sequence (1, 2, 3…) |
| `name` | string | Step label |
| `transitionMode` | enum | `manual` or `automatic` |
| `inputConfig` | object | Input source and format |
| `outputKey` | string | Output variable name |
| `condition` | string | Execution condition (null = always) |

Each step now owns a list of allowed actions:

| Field | Type | Description |
|---|---|---|
| `agentAction` | object | Shared action from the `AgentAction` catalog |
| `createWithTicket` | bool | Task for this action is pre-created when the ticket is created |

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
| `validated` | Ready for story execution | Only while inactive | ✅ Yes when active |
| `locked` | Locked (currently executing) | ❌ No | ✅ Yes |

New workflows are created directly active. Duplicated workflows start inactive so they can be edited before activation.

## Activation

Workflow activation is managed separately from the workflow status:

| Activation | Description |
|---|---|
| Active | Eligible for runtime resolution and story lifecycle automation |
| Inactive | Kept as a stored definition, but ignored by runtime resolution |

Current rules:
- duplicating a workflow creates an **inactive** copy
- the UI can activate an inactive workflow
- the UI can deactivate an active workflow only while it has never been used yet
- once a workflow has already been used by tickets or tasks, deactivation is blocked

## Project Assignment

Workflows are not owned by teams.

Current model:
- a **project** may reference one workflow
- a **project** may reference one team
- multiple projects may reuse the same workflow
- multiple projects may belong to the same team

The team controls available agents. The workflow controls step structure and the action catalog available in each step.

## Visual Pipeline

The workflow detail page displays the ordered workflow steps and the actions attached to each one.

This gives a clear overview of:
- which steps are manual
- which steps are automatic
- which actions are available in each step

## Step Fields

Each step includes:
- `transitionMode` — `manual` or `automatic`
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
