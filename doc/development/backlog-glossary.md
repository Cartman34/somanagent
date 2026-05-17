# Backlog Glossary

Definitions of the terms and acronyms used by the local backlog tooling (`scripts/backlog.php`, `local/backlog-board.yaml`, `local/backlog-review.md`).

Scope is limited to the local backlog. Application domain terms (Project, Module, Team, Skill, Workflow…) belong to [`functional/concepts.md`](../functional/concepts.md) and are intentionally excluded.

---

## WP — Workspace Principal

The main repository checkout, typically `~/projects/somanagent`.

The only workspace where backlog state lives (`local/backlog-board.yaml`, `local/backlog-review.md`) and where workflow commands are run.

## WA — Worktree Agent

A dedicated Git worktree owned by exactly one agent, located at `<WP>/.agent-worktrees/<code>`.

Created and removed by `backlog.php`. An agent reads, edits, and commits source files inside its own `WA`.

## Agent code

A local workflow identifier for one agent.

Format: a one-letter role prefix followed by a two-digit zero-padded number.

| Prefix | Role | Example |
|---|---|---|
| `d` | Developer | `d10` |
| `r` | Reviewer | `r10` |
| `m` | Manager | `m10` |

Used exactly as assigned, with no truncation, alias, or nickname. Auto-allocation starts at `10`; numbers `01-09` are reserved for explicit operator allocation via `--code=<code>`.

## Feature

An integration unit of work tracked in the backlog.

A Feature owns one branch in the repository and may have one corresponding GitHub Pull Request. A Feature can aggregate one or more child Tasks.

## Task

A child delivery unit attached to one Feature.

A Task is local-only: never pushed and never gets its own GitHub PR. It is merged into its parent Feature branch through `backlog.php`.

## Stage

The workflow state of an active backlog entry, recorded in `stage`.

| Stage | Meaning |
|---|---|
| `development` | Open for editing on the entry branch. |
| `review` | Submitted for review, frozen for the developer. |
| `reviewing` | A reviewer has claimed the entry and is actively reviewing it. |
| `rejected` | Review returned the entry with notes recorded in `local/backlog-review.md`. |
| `approved` | Reviewer marked the entry as OK. |

## Change type

The nature of work captured in a backlog entry, taking one of three values.

| Type | Meaning |
|---|---|
| `feat` | User-facing feature, enhancement, or new capability. |
| `fix` | User-facing bug fix. |
| `tech` | Purely technical change with no user-facing dimension (typically under `scripts/`). |

When the work spans several types, classification priority is `feat > fix > tech`: a user-facing feature stays `feat` and a user-facing bug stays `fix`, even when the work also has technical aspects. The reverse is also true: a bug fix with no user-facing dimension (for example a fix in `scripts/`) stays `tech`, it does not become `fix`.

Test for "user-facing": would a non-developer using the deployed product notice the change? If no, the entry is `tech`, regardless of whether it adds a capability ("feature-ish") or repairs a defect ("fix-ish"). Examples that are always `tech`: agent tooling improvements (backlog command outputs, agent context generation, launcher behavior), CI/PHPStan/validation pipeline changes, dev infrastructure, refactors of internal services, doc updates without behavior change.
