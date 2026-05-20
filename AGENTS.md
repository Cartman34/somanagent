# Agents

Single entrypoint for AI agents working on this repository.

Read this file first. Open additional files only when the active task requires them.

## Core Rules

- Work from `~/projects/somanagent` in the WSL native filesystem, never from `/mnt/c/...`.
- Prefer project wrappers in `scripts/` over raw container commands.
- Use relative paths in commands. Do not rely on `cd` into subfolders.
- For temporary workflow files such as PR bodies, write under `local/tmp/`, not `/tmp`.
- Keep chat updates concise.
- Never run dependent commands in parallel. Any command sequence where one step relies on the previous step having completed, especially Git flows such as `add` then `commit`, must be executed strictly sequentially.
- When executing requested operations, avoid chaining multiple operational commands with `&&` unless there is a real technical need.
- Anticipate permission needs as early as possible, and request permissions in grouped batches whenever the workflow allows it.
- Do not infer backlog or review state from chat alone.
- If a tool is known to be unavailable, stop probing for it until the user explicitly asks to install it or confirms it is available again.
- In this repository, treat `rg` as unavailable by default unless the user explicitly says otherwise.
- Keep `doc/` updated when code changes require it.
- `doc/README.md` is the documentation index. It tells agents where to find the relevant project information. Read it when documentation is needed instead of repeating documentation rules in this file.
- Product, architecture, workflow, exposure, and library-choice changes with meaningful tradeoffs require explicit user agreement before implementation.
- For network-dependent workflow commands, prefer idempotent script steps. On transient failures, retry when the script supports it, then report the network issue clearly.
- Never improvise outside the documented process. If a needed action, cleanup, exception path, or recovery step is not explicitly covered, stop and escalate to the user instead of deciding unilaterally.
- In case of command error, workflow inconsistency, or behavioral failure, report it to the user immediately. The user is the only one allowed to decide whether to leave the documented process.
- Never extend the scope of a request on your own initiative, even to apply a consistency that seems legitimate. Any adjacent correction, technical alignment, or workflow change that was not explicitly requested must be proposed first and implemented only after the user gives explicit approval.
- No implicit instructions. A factual statement ("you didn't do X") is not an order — ask if the action seems legitimate. An explicit order ("do X") must be executed even outside the default role scope, unless it poses a real danger (not authorized ≠ forbidden).
- For any explicit workflow keyword from the documented process, execute the documented procedure exactly as defined. Do not substitute your own interpretation of the task state, do not short-circuit the procedure based on memory, and do not refuse the action unless the documented procedure itself fails.
- When reporting problems, questions, or decisions to the user, number each item (1, 2, 3…) and label each solution with a letter (A, B, C…).

## Local Source Of Truth

- Pending backlog: `local/backlog-board.md`
- Review state: `local/backlog-review.md`
- Files under `local/` are local-only and must not be committed.
- Never edit local backlog files manually when `php scripts/backlog.php` covers the action.
- For detailed backlog rules and workflow behavior, read `doc/development/agent-workflow.md` when needed.

## Role Selection

Use one active role only.

- Agents operate with one active role at a time.
- Each role has strict rules, allowed actions, and workflow constraints.
- Do not infer or mix roles from chat context alone.
- Read the detailed instructions only for the active role:
  - `Developer`: `doc/development/agent-developer.md`
  - `Manager`: `doc/development/agent-manager.md`
  - `Reviewer / CP`: `doc/development/agent-reviewer.md`
- Shared backlog and workflow rules are documented in `doc/development/agent-workflow.md`.
- After a context compression, recover role and agent code from the conversation summary before asking the user. Only ask if they are genuinely absent from the summary.

## Agent Session Launcher

Sessions are started by the operator using `php scripts/backlog-agent.php start <client> --developer|--reviewer|--manager`.

When a session is active, the following environment variables are injected into the process:

| Variable | Value |
|---|---|
| `SOMANAGER_AGENT` | Agent code (e.g. `d10`) |
| `SOMANAGER_ROLE` | `developer`, `reviewer`, or `manager` |
| `SOMANAGER_CLIENT` | `claude`, `codex`, `opencode`, or `gemini` |
| `SOMANAGER_WP` | Absolute path to the main workspace |

A context file is generated at `<WA>/local/agent-context.md` on every session start and resume. It contains the working directory, current task, allowed commands, user keywords, and backlog vocabulary. Read it when context is needed.

Run `php scripts/backlog-agent.php whoami` from inside a WA to confirm the session identity.

## Git Rules

- Always use `git add .` unless selective staging is actually required.
- Developers do not push manually.
- Reviewers may push existing feature branches only when the workflow requires it and no script wrapper exists yet.
- Never amend a published commit.
- Use `php scripts/github.php` for GitHub operations instead of `gh`.
- Use `git -C <path>` instead of `cd <path> && git ...`.

For local troubleshooting and useful runtime checks, read `doc/development/troubleshooting.md` when needed.
