# Agent Manager Workflow

Detailed instructions for the `Manager` role defined in `AGENTS.md`.

Read this file only when the active task requires backlog management or workflow/documentation/script changes.

## Scope

- manage the local backlog workflow and its operational states
- author and maintain specs under `local/specs/` and `doc/development/spec-conventions.md` (manager owns the spec lifecycle)
- **correct** existing documentation under `doc/` when wording is wrong, inconsistent, or out of date; do **not** add new sections or new normative content to non-spec doc — that is the developer's job during implementation
- edit workflow and tooling scripts under `scripts/` for manager-level workflow concerns (not application code)
- perform backlog, review, and merge workflow actions when needed
- run `SOMANAGER_ROLE=manager SOMANAGER_AGENT=<code> php scripts/backlog.php ...` from `WP` only; backlog commands are not allowed from `WA`

## Do Not

- edit application source code under `backend/`, `frontend/`, or `.docker/`
- implement product or technical changes in the real application code
- add new sections, new tables, or new normative content to existing files under `doc/` (developer territory); manager-side edits to non-spec doc are limited to fixing what is already in place
- bypass `php scripts/backlog.php` when it already covers the needed backlog action

## Allowed Commands

- any backlog workflow command documented in `doc/development/agent-workflow.md`
- reviewer flow commands documented in `doc/development/agent-reviewer.md`
- developer backlog-management commands documented in `doc/development/agent-developer.md`
- `review-reopen <entry-ref>` with `SOMANAGER_ROLE=manager`: transitions an approved entry from `approved` to `review` and clears `meta.reviewer`, putting it back in the open review queue without assigning it to a specific reviewer

## Assignment Authority

- `Manager` can assign any unassigned active feature or task to any developer agent, and can refresh an existing assignment for the same target agent.
- Missing `agent` metadata and legacy `agent: none` both mean the entry is unassigned; a different real agent code must be unassigned first before a new assignment.
- `Manager` can unassign any active entry (feature or task) from any developer agent through `entry-unassign`.
- For `entry-unassign`, `--agent=<code>` identifies the manager caller. Use an explicit `<entry-ref>` to choose the entry to unassign.
- Every manager backlog command must be prefixed exactly as `SOMANAGER_ROLE=manager SOMANAGER_AGENT=<code> php scripts/backlog.php ...`.

## Session Environment

Manager sessions are started by the operator with:

```
php scripts/backlog-agent.php start <client> --manager [--code=<mXX>]
```

Default model profile is `premium+medium`. The operator may override it with `--tier=economy|balanced|premium`, `--effort=low|medium|high`, or `--model=<raw-name>`.

Manager sessions run in WP by default. No `.agent-worktrees/<mXX>` directory is created automatically. The `--reset` flag is not supported for the manager role.

**No auto-pick:** unlike developer and reviewer roles, `start --manager` does not reserve any backlog entry automatically. The manager operates interactively from WP and triggers backlog actions manually.

A manager may inspect or switch to a WA when the documented manager workflow allows it; this must be done manually from within the WP session.

Supported clients:

- `claude`: supported end to end by `ClaudeAgentLauncher`.
- `codex`: supported end to end by `CodexAgentLauncher`.
- `opencode`: supported end to end by `OpenCodeAgentLauncher`.
- `gemini`: supported end to end by `GeminiAgentLauncher`. Context is injected via the `GEMINI_SYSTEM_MD` env var.

The following environment variables are injected into every session:

| Variable | Value |
|---|---|
| `SOMANAGER_AGENT` | Agent code (e.g. `m10`) |
| `SOMANAGER_ROLE` | `manager` |
| `SOMANAGER_CLIENT` | `claude`, `codex`, `opencode`, or `gemini` |
| `SOMANAGER_WP` | Absolute path to the main workspace |

A context file is generated at `<WP>/local/agent-context.md` on every session start and resume. It summarises the current task, allowed commands, and backlog vocabulary. Do not commit or push this file.

The launcher spawns the AI client via the active **session driver** and records the client PID (and tmux session name when applicable) in `local/tmp/agent-sessions.json`:

- **tmux driver** (default): wraps the session in a named tmux session (`somanagent-<code>`). SSH-resilient — the client keeps running after a terminal disconnect. `stop` kills the tmux session; `resume` re-attaches to it.
- **direct driver** (`BACKLOG_AGENT_SESSION_DRIVER=direct`): spawns the client via `proc_open`. Not SSH-resilient. `stop` sends SIGTERM then SIGKILL after 5 seconds.

A `resume` re-attaches to a detached tmux session, but is refused while the PHP wrapper is still alive or when the direct driver still has a live client process. See `doc/development/agent-workflow.md` for the full lifecycle and `last_seen_at` semantics.

`php scripts/backlog-agent.php prune` batch-cleans invalid entries from `agent-sessions.json` (launches never finalised, dead processes, orphan worktrees) without targeting one code. Pass `--dry-run` to preview or `--force` to also drop warning entries with a still-live process. See `doc/development/agent-workflow.md` for the full ruleset.

Run `php scripts/backlog-agent.php whoami` from WP to confirm the session identity.

## Guidance

- use `doc/development/agent-workflow.md` for the shared backlog model and state transitions
- use `doc/development/agent-developer.md` when the task is about developer-side backlog operations
- use `doc/development/agent-reviewer.md` when the task is about review, approval, close, or merge flow
- use a `tech/` branch prefix for script, workflow tooling, or script documentation changes
- when a change touches the backlog model itself, update scripts and documentation together
- prefixed backlog tasks of the form `[feature-slug][task-slug]` create one shared `kind=feature` parent plus one local-only `kind=task` child per task; manager-side workflow changes must preserve that distinction explicitly
