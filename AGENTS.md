# Agents

> This is `AGENTS.md`; `CLAUDE.md` is a symlink to it.

Single entrypoint for AI agents working on this repository. Read this file first; open other files only when the active task requires them.

- **Common rules (all projects)** — conventions, working method, process discipline: `scripts/toolkit/doc/AGENTS-common.md`. They apply here and are not repeated below.
- **Backlog (board command, roles, workflow)** — see the shared common rules for how it works and how to use the `board` command; backlog architecture and ops live under
  `scripts/backlog/doc/developing/` and `scripts/backlog/doc/operating/`. The backlog subsystem is the `sowapps/web-backlog` package.
- **Portals** — `scripts/toolkit/` and `scripts/backlog/` are the portals through which this host reaches the packages' scripts and docs: gitignored symlinks to the sibling packages, posed by the
  operator on Main Worktree, by provisioning in Agent Worktree.

## SoManAgent-specific rules

- Work from `~/projects/somanagent` in the WSL native filesystem, never from `/mnt/c/...`.
- Use `rg` for local text/file searches. If `rg` is unavailable, stop and report the environment issue to the user instead of falling back silently.
- `doc/README.md` is the documentation index — read it when project documentation is needed.
- Product, architecture, workflow, exposure, and library-choice changes with meaningful tradeoffs require explicit user agreement before implementation.

## Roles & backlog

Use one active role only (`Developer`, `Reviewer`, or `Manager`); do not infer or mix roles from chat. Sessions are started by the operator with
`php scripts/backlog/agent.php start <client> --developer|--reviewer|--manager`, which injects `AGENT_ROLE` / `AGENT_CODE` / `AGENT_CLIENT` / `PROJECT_MAIN_WORKTREE`. Role rules, allowed commands, the
workflow state machine and the local source of truth (board/review) live in `scripts/backlog/doc/`. Run backlog actions via `php scripts/backlog/board.php …`; never edit the backlog data files by hand
when a command covers the action.

## Git

- Use `php scripts/toolkit/github.php` for GitHub operations, never `gh`.
- `git add .` unless selective staging is actually required; use `git -C <path>` instead of `cd <path> && git …`.
- Never amend a published commit. Developers do not push manually; reviewers/managers push only when the documented workflow requires it.
