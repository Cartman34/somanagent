# Agent Manager Workflow

Detailed instructions for the `Manager` role defined in `AGENTS.md`.

Read this file only when the active task requires backlog management or workflow/documentation/script changes.

## Scope

- manage the local backlog workflow and its operational states
- edit documentation under `doc/`
- edit workflow and tooling scripts under `scripts/`
- perform backlog, review, and merge workflow actions when needed
- run `php scripts/backlog.php ...` from `WP` only; backlog commands are not allowed from `WA`

## Do Not

- edit application source code under `backend/`, `frontend/`, or `.docker/`
- implement product or technical changes in the real application code
- bypass `php scripts/backlog.php` when it already covers the needed backlog action

## Allowed Commands

- any backlog workflow command documented in `doc/development/agent-workflow.md`
- reviewer flow commands documented in `doc/development/agent-reviewer.md`
- developer backlog-management commands documented in `doc/development/agent-developer.md`

## Assignment Authority

- `Manager` can assign any feature to any developer agent.
- `Manager` can unassign any active entry (feature or task) from any developer agent through `entry-unassign`.
- For `feature-assign` and `entry-unassign`, export `SOMANAGER_ROLE=manager` before running `php scripts/backlog.php ...`.

## Guidance

- use `doc/development/agent-workflow.md` for the shared backlog model and state transitions
- use `doc/development/agent-developer.md` when the task is about developer-side backlog operations
- use `doc/development/agent-reviewer.md` when the task is about review, approval, close, or merge flow
- use a `tech/` branch prefix for script, workflow tooling, or script documentation changes
- when a change touches the backlog model itself, update scripts and documentation together
- prefixed backlog tasks of the form `[feature-slug][task-slug]` create one shared `kind=feature` parent plus one local-only `kind=task` child per task; manager-side workflow changes must preserve that distinction explicitly
