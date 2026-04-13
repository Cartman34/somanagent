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
- Do not infer backlog or review state from chat alone.
- If a tool is known to be unavailable, stop probing for it until the user explicitly asks to install it or confirms it is available again.
- In this repository, treat `rg` as unavailable by default unless the user explicitly says otherwise.
- Keep `doc/` updated when code changes require it.
- `doc/README.md` is the documentation index. It tells agents where to find the relevant project information. Read it when documentation is needed instead of repeating documentation rules in this file.
- Product, architecture, workflow, exposure, and library-choice changes with meaningful tradeoffs require explicit user agreement before implementation.
- For network-dependent workflow commands, prefer idempotent script steps. On transient failures, retry when the script supports it, then report the network issue clearly.
- Never improvise outside the documented process. If a needed action, cleanup, exception path, or recovery step is not explicitly covered, stop and escalate to the user instead of deciding unilaterally.
- In case of command error, workflow inconsistency, or behavioral failure, report it to the user immediately. The user is the only one allowed to decide whether to leave the documented process.

## Local Source Of Truth

- Pending backlog: `local/backlog-board.md`
- Review state: `local/backlog-review.md`
- Files under `local/` are local-only and must not be committed.
- For `local/backlog-board.md` and `local/backlog-review.md`, always follow the `## Règles d'usage` section in each file.
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

## Git Rules

- Always use `git add .` unless selective staging is actually required.
- Developers do not push manually.
- Reviewers may push existing feature branches only when the workflow requires it and no script wrapper exists yet.
- Never amend a published commit.
- Use `php scripts/github.php` for GitHub operations instead of `gh`.
- Use `git -C <path>` instead of `cd <path> && git ...`.

For local troubleshooting and useful runtime checks, read `doc/development/troubleshooting.md` when needed.
