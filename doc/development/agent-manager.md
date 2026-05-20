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
- `review-reopen <entry-ref>` with `SOMANAGER_ROLE=manager`: transitions an approved entry from `approved` to `review` and clears `reviewer`, putting it back in the open review queue without assigning it to a specific reviewer

## Assignment Authority

- `Manager` can assign any unassigned active feature or task to any developer agent, and can refresh an existing assignment for the same target agent.
- Missing `agent` metadata and legacy `agent: none` both mean the entry is unassigned; a different real agent code must be unassigned first before a new assignment.
- `Manager` can unassign any active entry (feature or task) from any developer agent through `entry-unassign`.
- For `entry-unassign`, `--developer=<code>` identifies the caller developer. Use an explicit `<entry-ref>` to choose the entry to unassign.
- Every manager backlog command must be prefixed exactly as `SOMANAGER_ROLE=manager SOMANAGER_AGENT=<code> php scripts/backlog.php ...`.

## Explicit Entry Overrides

Managers do not have an auto-picked active entry, so manager calls that mutate an existing entry must name the target explicitly.

- `entry-rename <entry-ref> "<new text>"` renames the targeted active feature or task, including unassigned entries. For tasks, the parent feature contribution line is updated too.
- `feature-block <feature>` and `feature-unblock <feature>` mutate the targeted active feature, including an unassigned feature container.
- `review-cancel <entry-ref>` moves any `reviewing` entry back to `review` and clears its reviewer, regardless of which reviewer claimed it.
- `entry-release <entry-ref>` returns an untouched `development` entry to `todo`, with the same no-development and child-task constraints as the developer flow.
- `entry-merge <entry-ref>` may be invoked by a manager for an explicit merge instruction. Its output prints both the stored reviewer (`Reviewer:`) and the manager caller (`Caller:`) for traceability.

If the explicit `<entry-ref>` or `<feature>` is omitted in manager mode, these commands fail instead of falling back to a caller-owned active entry.

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

- **tmux driver** (default): wraps the session in a named tmux session (`somanagent-<code>`). SSH-resilient — the client keeps running after a terminal disconnect. `stop` kills the tmux session.
- **direct driver** (`BACKLOG_AGENT_SESSION_DRIVER=direct`): spawns the client via `proc_open`. Not SSH-resilient. `stop` sends SIGTERM then SIGKILL after 5 seconds.

`php scripts/backlog-agent.php prune` batch-cleans invalid entries from `agent-sessions.json` (launches never finalised, dead processes, orphan worktrees) without targeting one code. Pass `--dry-run` to preview or `--force` to also drop warning entries with a still-live process. See `doc/development/agent-workflow.md` for the full ruleset.

Run `php scripts/backlog-agent.php whoami` from WP to confirm the session identity.

## Guidance

- use `doc/development/agent-workflow.md` for the shared backlog model and state transitions
- use `doc/development/agent-developer.md` when the task is about developer-side backlog operations
- use `doc/development/agent-reviewer.md` when the task is about review, approval, close, or merge flow
- use a `tech/` branch prefix for script, workflow tooling, or script documentation changes
- when a change touches the backlog model itself, update scripts and documentation together
- prefixed backlog tasks of the form `[feature-slug][task-slug]` create one shared `kind=feature` parent plus one local-only `kind=task` child per task; manager-side workflow changes must preserve that distinction explicitly

## Task Body Convention

Every backlog entry created via `entry-create --body-file=<path>` must follow the convention below. A basic developer agent must be able to execute the task without re-analysing the project from scratch; the reviewer is presumed more capable of analysis and re-verifies impact.

### Formatting Rules

- No blank lines anywhere in the body — blank lines break the markdown list rendering.
- The whole body is a hierarchy of lists: `-` at root, four spaces then `-` for sub-items.
- No free-form prose paragraphs.
- A section with nothing to declare is omitted, not left empty.

### Section Structure (in this order)

- `Motivation` — why this task exists. Any arbitration between alternatives ("we chose X over Y because…") goes here, not in a separate section.
- `État actuel` — current state of the relevant code or system.
- `Comportement attendu` — functional contract the system must satisfy once the task is done.
- `Périmètre` — concrete code modifications expected (files, areas, symbols touched). **Plancher rule applies.**
- `Hors scope` — items explicitly excluded. Closed list, not extensible.
- `Tests` — minimum tests to add or extend. **Plancher rule applies.**
- `Doc à mettre à jour` — minimum documentation and help YAML to update. **Plancher rule applies.**

### Plancher Rule And Standard Header

`Périmètre`, `Tests`, and `Doc à mettre à jour` are minimum floors, not exhaustive lists. They must carry the standard header marker so the developer and reviewer know to extend by impact analysis. The exact header form is:

- `Périmètre (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)`
- `Tests (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)`
- `Doc à mettre à jour (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)`

The developer extends each plancher section by running impact analysis (grep call-sites, follow call-graph, run existing test suite) before opening the PR. The reviewer re-runs the same analysis and rejects the PR if any detectable impact is not covered. See `agent-developer.md` and `agent-reviewer.md` for the corresponding obligations on each role.

### Conformant Example

Task bodies are written in French by project convention; the example below uses French throughout, consistent with how the standard plancher headers are phrased.

```
- Motivation
    - Raison d'être de la tâche ; arbitrage entre alternatives si pertinent.
- État actuel
    - Description factuelle de l'état du code ou du système avant la tâche.
- Comportement attendu
    - Comportement observable 1 une fois la tâche livrée.
    - Comportement observable 2 une fois la tâche livrée.
- Périmètre (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)
    - Zone 1 à modifier (sous-système, fichiers, symboles).
    - Zone 2 à modifier.
- Hors scope
    - Élément explicitement exclu (liste fermée).
- Tests (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)
    - Test minimal 1.
- Doc à mettre à jour (plancher non-exhaustif — analyse d'impact obligatoire pour étendre)
    - Page de doc minimale 1.
```
