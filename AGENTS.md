# Agents

Single entrypoint for AI agents working on this repository. Read this file first; open other files only when the active task requires them.

- **Common rules (all projects)** — conventions, working method, process discipline: `scripts/toolkit/doc/AGENTS-common.md`. They apply here and are not repeated below.
- **Backlog workflow, roles, sessions, board/review** — `scripts/backlog/doc/using/` (`agent-developer.md`, `agent-reviewer.md`, `agent-manager.md`, `agent-workflow.md`, `backlog-glossary.md`); architecture & ops under `scripts/backlog/doc/{developing,operating}/`. The backlog subsystem is the `sowapps/web-backlog` package.

Pose the portals once (relative symlinks to sibling clones, not committed):
`ln -s ../../web-toolkit/scripts/toolkit scripts/toolkit` and `ln -s ../../web-backlog/scripts/backlog scripts/backlog`

## SoManAgent-specific rules

- Work from `~/projects/somanagent` in the WSL native filesystem, never from `/mnt/c/...`.
- Use `rg` for local text/file searches. If `rg` is unavailable, stop and report the environment issue to the user instead of falling back silently.
- `doc/README.md` is the documentation index — read it when project documentation is needed.
- Product, architecture, workflow, exposure, and library-choice changes with meaningful tradeoffs require explicit user agreement before implementation.

## Roles & backlog

Use one active role only (`Developer`, `Reviewer`, or `Manager`); do not infer or mix roles from chat. Sessions are started by the operator with `php scripts/backlog/agent.php start <client> --developer|--reviewer|--manager`, which injects `SOMANAGER_ROLE` / `SOMANAGER_AGENT` / `SOMANAGER_CLIENT` / `SOMANAGER_WP`. Role rules, allowed commands, the workflow state machine and the local source of truth (board/review) live in `scripts/backlog/doc/`. Run backlog actions via `php scripts/backlog/backlog.php …`; never edit the backlog data files by hand when a command covers the action.

## Git

- Use `php scripts/toolkit/github.php` for GitHub operations, never `gh`.
- `git add .` unless selective staging is actually required; use `git -C <path>` instead of `cd <path> && git …`.
- Never amend a published commit. Developers do not push manually; reviewers/managers push only when the documented workflow requires it.
