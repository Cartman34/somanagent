# Semantic CSS — SoManAgent type registry

The semantic CSS class **convention** (the `item-{type}` / `list-{type}` and `section-{region}` patterns and their rules) is generic and lives in the toolkit: [`scripts/toolkit/doc/developing/conventions/css.md`](../../scripts/toolkit/doc/developing/conventions/css.md).

This file is SoManAgent's **type registry**: the concrete `{type}` values the app uses. Add a new type here in the same change that introduces it.

## `item-{type}` / `list-{type}` — types in use

| Type | Concept |
|---|---|
| `ticket` | Story or bug (Ticket) |
| `ticket-task` | Technical task (TicketTask) |
| `agent` | Agent |
| `project` | Project |
| `team` | Team |
| `role` | Role |
| `module` | Project module |
| `feature` | Feature |
| `workflow` | Workflow |
| `workflow-step` | Workflow step |
| `audit-log` | Audit log entry |
| `occurrence` | Aggregated log occurrence |
| `log-event` | Raw log event |
| `token-usage` | Token usage entry |
| `agent-execution` | Agent task execution |
| `ticket-log` | Ticket discussion log (comment, reply) |
| `skill` | Skill |
| `chat-message` | Chat message |

## `section-{region}` — common regions

| Class | Element | Purpose |
|---|---|---|
| `section-title` | `<p>` or heading | Primary label of a UI section block |
| `section-legend` | `<p>` | Secondary label or contextual caption inside a section |
