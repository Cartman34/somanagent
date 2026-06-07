# Available Scripts

> See also: [Installation](installation.md) · [Symfony Commands](commands.md) · [Script Conventions](scripts-conventions.md)

Scripts are located in `scripts/`. All PHP scripts follow this convention: a **commented header** just after the shebang, with `Description:` and `Usage:` tags.

Project rule:
- always use a script from `scripts/` first when it already covers the operation
- only fall back to direct `docker exec`, raw `bin/console`, or container-specific commands when no script exists
- this keeps commands shorter, more consistent, and cheaper to use during day-to-day work

## Invocation

Two equivalent forms are supported from the project root for any script that carries a shebang:

```bash
php scripts/migrate.php          # explicit PHP interpreter
./scripts/migrate.php            # rely on the script's shebang (#!/usr/bin/env php)
```

Both work because every runnable script under `scripts/` declares a `#!/usr/bin/env php` (or equivalent) shebang **and** carries the exec bit in the git index. The exec bit is enforced by `scripts/toolkit/validate-files.php` at review time — see [Script Conventions / Executable Bit](scripts-conventions.md#executable-bit).

```bash
# See all available scripts
php scripts/toolkit/help.php

# See the help for a specific script
php scripts/toolkit/help.php migrate.php
```

## Overview

| Script | Type | Role |
|---|---|---|
| `check-php.sh` | Bash | Check that PHP 8.4+ is installed |
| `help.php` | PHP | Display help for all scripts |
| `backlog.php` | PHP | Run the local backlog workflow for features, child tasks, reviews, and merges |
| `backlog-agent.php` | PHP | Start and manage AI agent sessions in dedicated worktrees |
| `worktree-info.php` | PHP | Display the git worktree context for the current script (linked vs main worktree, roots) |
| `test-backlog-workflow.php` | PHP | Run reusable sequential validation campaigns for `backlog.php` on temporary backlog files |
| `test-backlog-agent.php` | PHP | Run unit tests for backlog-agent.php classes |
| `test-server.php` | PHP | Run tests for server.php |
| `test-dev-env.php` | PHP | Run unit tests for the DevEnv manifest/lockfile/resolver/planner classes |
| `test-setup.php` | PHP | Run subprocess integration tests for setup.php |
| `test-validation.php` | PHP | Run unit tests for `scripts/src/Validation/` classes (ScriptExecBitValidator, ExecBitFixer, …) |
| `setup.php` | PHP | Manage host-level dependencies and project setup (install, update, verify, uninstall, reset, status, dep-config) |
| `server.php` | PHP | Manage Docker Compose services (start, stop, restart, status, health) |
| `migrate.php` | PHP | Run Doctrine migrations |
| `console.php` | PHP | Run a Symfony command |
| `node.php` | PHP | Run reusable commands inside the Node container |
| `db.php` | PHP | Run database commands (PostgreSQL + Doctrine reset) |
| `code-search.php` | PHP | Search a term across backend, frontend, scripts, doc, and YAML resource files |
| `github.php` | PHP | GitHub CLI helper for PR creation, listing, view and merge |
| `logs.php` | PHP | Display Docker logs |
| `health.php` | PHP | Check application status |
| `review.php` | PHP | Run mechanical review checks (French strings, PHPDoc, JSDoc, translations, targeted validation, PHPStan) |
| `validate-files.php` | PHP | Run targeted backend/frontend validations for an explicit file list |
| `validate-backend-tests.php` | PHP | Run isolated local PHPUnit checks for backend unit tests from WSL without Docker services |
| `validate-agent-launchers.php` | PHP | Cross-check `AgentClientLauncher` CLI flags against the local binary `--help` output |
| `fix-permissions.php` | PHP | Restore the exec bit on shebang-bearing `scripts/*.php` entrypoints |
| `git-cleanup-branches.php` | PHP | Delete stale git branches merged into main (and, for a human operator, on the remote) |
| `phpunit.php` | PHP | Run PHPUnit on the project scopes |
| `phpstan.php` | PHP | Run PHPStan static analysis on backend and/or scripts PHP sources |
| `rector.php` | PHP | Apply automated code fixes to backend and/or scripts PHP sources via Rector |
| `code-refacto.php` | PHP | Local code refactoring tools for backend and scripts source files |
| `claude-auth.php` | PHP | Sync Claude CLI auth from WSL to the Docker runtime |
| `codex-auth.php` | PHP | Sync Codex CLI ChatGPT auth from WSL to the Docker runtime |
| `opencode-auth.php` | PHP | Sync OpenCode provider credentials from WSL to the Docker runtime |
| `wsl-claude-install.sh` | Bash | Install Claude CLI inside the configured WSL distro |
| `wsl-codex-install.sh` | Bash | Install or upgrade OpenAI Codex CLI inside WSL |
| `wsl-migrate.sh` | Bash | Copy the project to the WSL native filesystem for faster Docker I/O |

## Script Details

### `check-php.sh`
Checks that PHP >= 8.4 is available in the PATH.

```bash
bash scripts/check-php.sh
# ✓ PHP 8.4.5 detected
```

---

### `help.php`
Displays the list of all scripts with their description and usage examples. Automatically parses the header of each script file.

```bash
php scripts/toolkit/help.php              # list all scripts
php scripts/toolkit/help.php migrate.php  # detail for one script
```

---

### `backlog.php`
Runs the documented local backlog workflow, including feature start/review/merge and local child task submit/review/merge flows. Can be run from a `WA`: execution is automatically proxied to the equivalent script in `WP` so backlog state always lives in `WP`'s `local/`.

```bash
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php base-update my-feature
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php start --help
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php start my-feature
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php review-request
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php rename "New description"
SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog/backlog.php review-approve my-feature/my-task
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php rework my-feature/my-task
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php merge my-feature/my-task
SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog/backlog.php worktree-restore --agent d01 --force
```

Notes:
- run `SOMANAGER_ROLE=<role> SOMANAGER_AGENT=<code> php scripts/backlog/backlog.php` for the global backlog help
- use `SOMANAGER_ROLE=<role> SOMANAGER_AGENT=<code> php scripts/backlog/backlog.php <command> --help` for one command
- agent commands must keep the prefix order `SOMANAGER_ROLE` then `SOMANAGER_AGENT`
- `--agent` is used only by commands where it identifies a target or explicit lookup, not to repeat the caller
- `base-update` refreshes the recorded Git base after a rebase; features update `origin/main` before using the merge base with it, and local child tasks default to the merge base with their parent feature branch
- `worktree-restore` validates copied PHP vendors with `autoload.php` witnesses and can recreate a clean managed worktree with `--force`
- `start` reads the next queued board entry, accepts plain text, the type prefixes `[feat]` / `[fix]` / `[tech]` (case-insensitive, at any position in the leading bracket sequence), a single `[feature-slug]` prefix, and scoped entries like `[feature-slug][task-slug] Task text`, then prints the started task or feature details with the assigned worktree
- branch prefix matches the type 1:1 (`feat → feat/<slug>`, `fix → fix/<slug>`, `tech → tech/<slug>`); `--branch-type=<feat|fix|tech>` overrides the queued prefix and rejects unknown values
- `start` validates the queued entry fully (type, slugs, conflicts) before any worktree, branch or backlog mutation; a refusal leaves no leftover state. With `--dry-run` it prints the resolved interpretation and performs no Git, worktree or backlog mutation (Git fetch / `origin/main` reads remain enabled)
- child task review stays local; only the parent feature uses the remote PR flow
- `user-merge` lists all approved entries in board order, shows a preview (commits, diff stat, PR info), and prompts the user interactively (y/n/d/q); no SOMANAGER_ROLE or SOMANAGER_AGENT required; use `--dry-run` for a non-interactive preview
- `rebase` rebases an active entry branch onto its canonical target and pushes on success: task → parent feature branch (local-only, no push) ; root feature → `origin/main` (fetch + rebase + push with `--force-with-lease`). Prints "Already up to date with <target>" (exit 0) if no rebase is needed, "Rebased on <target> and pushed" (exit 0) on success, or lists conflicting files and exits non-zero on conflict. Use `--dry-run` to check whether a rebase is needed without running it. Prefer this command over raw git rebase for any backlog entry to ensure consistent fetch + rebase + push handling. The agent launcher also uses this service automatically when a developer starts with an approved entry
- `list` covers all backlog sections (todo queue + all active stages) in a single command. Without flags it prints all entries grouped by stage with a header per stage. Use `--stage=<stage>` (repeatable, or CSV `--stage=todo,review`) to filter to a positive selection of stages. Use `--no-stage=<stage>` (same syntax) to exclude stages from the full list. `--stage` and `--no-stage` are mutually exclusive. Allowed stage values: `todo`, `development`, `review`, `reviewing`, `approved`, `rejected`. Use `--format=<format>` to choose the output format: `default` (rich `- <ref> kind=… agent=… pr=… reviewer=… title=…`), `numbered` (same prefixed `N. `), `ref` (one stable ref per line), `json` (structured array). Absent fields are shown as `none`, never omitted. Use `--flat` (requires exactly one `--stage` value) to suppress stage headers and print entries directly.
- `list` and the list-style active sections of `status` always show `reviewer=<code>` or `reviewer=none` for active entries, including `development`, `review`, `reviewing`, `approved`, and `rejected` stages. Detailed entry output keeps the `Reviewer: <code|none>` line.

---

### `backlog-agent.php`
Starts and manages AI agent sessions for Claude, Codex, OpenCode, and Gemini. Developer sessions run in dedicated `WA` worktrees, reviewer sessions reuse the target developer `WA`, and manager sessions run from `WP` by default.

Subcommands:
- `start` - start a new agent client session for one role
- `list` - list recorded agent sessions
- `status` - show one recorded agent session
- `stop` - stop the recorded client session; also kills an orphan driver session (e.g. a tmux session) when no registry entry exists, which is the correct remedy for the "A live driver session already exists" error from `start`
- `prune` - remove stale registry entries AND kill driver-side sessions with no registry entry; two complementary passes ensure full symmetry between the registry and the driver
- `whoami` - display the current agent context
- `sessions` - list sessions exposed by the configured client

```bash
php scripts/backlog/agent.php help
php scripts/backlog/agent.php help start
php scripts/backlog/agent.php start claude --developer --code=d04
php scripts/backlog/agent.php start codex --developer --code=d04 --tier=economy --effort=low
php scripts/backlog/agent.php start gemini --manager --code=m01 --model=gemini-2.5-pro
php scripts/backlog/agent.php start codex --reviewer --code=r01
php scripts/backlog/agent.php start opencode --manager --code=m01
php scripts/backlog/agent.php list
php scripts/backlog/agent.php status --code=d04
php scripts/backlog/agent.php stop --code=d04
php scripts/backlog/agent.php whoami
php scripts/backlog/agent.php sessions --code=d04
```

Notes:
- `start` accepts `--tier=economy|balanced|premium`, `--effort=low|medium|high`, and `--model=<raw-name>`
- default profile is `developer=balanced+medium`, `reviewer=balanced+medium`, `manager=premium+medium`
- `--model` bypasses tier model selection and is mutually exclusive with `--tier`; canonical effort still applies on clients that support effort
- after a developer auto-pick or reviewer auto-claim, `start` sends the role prompt from the backlog package's launch-prompts resource as the initial user message; manager, reuse, and no-auto-pick paths send no launch prompt
- when the generated context contains an active entry (developer) or a reviewing entry (reviewer), the `next`/`review` keyword is omitted from the User Keywords section and an inline `## Workflow` section is injected with the role-specific steps to follow; `doc/development/agent-developer.md` and `doc/development/agent-reviewer.md` are intentionally left unchanged so the keywords remain documented for non-backlog-agent sessions
- `claude` uses `--model` and `--effort`; Claude Code documents aliases such as `haiku`, `sonnet`, and `opus`
- `codex` uses `--model` and `--config model_reasoning_effort="<level>"`; Codex config documents `model_reasoning_effort`
- `opencode` uses `--model provider/model`; the project mapping uses models listed by the local OpenCode provider cache
- `gemini` uses `--model`; `--effort=low/high` prints a warning and is ignored because Gemini CLI has no effort flag
- every client receives permission flags at launch so agents can operate in their WA without interactive approval prompts; these flags are CLI-scoped and do not modify any global user config file (`~/.claude.json`, `~/.codex/config.toml`, etc.)

| Client | Permission flag(s) injected | Rationale |
|---|---|---|
| `claude` | `--permission-mode acceptEdits` | Approves edits/writes without full bypass; relies on `.claude/settings.local.json` for Bash whitelist |
| `codex` | `--ask-for-approval never` + `--config sandbox_workspace_write.writable_roots=[…]` | Removes prompts; `sandbox_mode=workspace-write` in `~/.codex/config.toml` limits writes to the WA; `writable_roots` extends the whitelist to WP paths needed by agents (today: `local/backlog/`, `.git/`). To add a path, pass it in `extraWritableRoots` to `CodexAgentLauncher`. |
| `gemini` | `--approval-mode auto_edit --skip-trust` | Mirrors Claude's acceptEdits; `--skip-trust` suppresses the session-level trust dialog only |
| `opencode` | `--dangerously-skip-permissions` | Only available auto-approval flag in OpenCode CLI; impact limited because opencode is used infrequently via backlog-agent |
- `BACKLOG_AGENT_SESSION_DRIVER=tmux|direct` selects the session driver
- `tmux` is the default driver and keeps the live client session recoverable after terminal or SSH disconnects; mouse mode and a 50 000-line scrollback buffer are applied automatically so the mouse wheel can scroll the pane history; the window tab shows the agent code (e.g. `d04`); the right side of the status bar shows `role · client · date`
- `direct` is a degraded driver that keeps the previous interactive process behavior, without live terminal recovery
- every client launch (start or resume, regardless of driver) appends one line to `local/tmp/agent-launches.log`; the file is append-only, not versioned, and never read by the workflow — it exists solely for post-mortem diagnostics (e.g. verifying which flags were passed to a client for a past session); format is tab-separated: `timestamp ISO 8601`, `agent code`, `role`, `client`, `driver`, `full command line (binary + shell-quoted args)`, `client PID`
- when `start --reviewer` (without `--code`) auto-picks a review-stage entry whose developer WA is already occupied by an existing reviewer session, the command resolves the conflict as follows:
  - **Dead session** (process gone): the registry entry is silently removed and the entry is claimed normally as a fresh session
  - **Alive + tmux attached elsewhere**: the entry is auto-skipped with an informational message; the next review-stage entry is tried
  - **Alive + tmux detached**: an interactive prompt is shown with up to three choices:
    - `[A] Accept` — assigns the entry to the existing session via `review-next` and re-attaches the tmux session; no new session is created
    - `[P] Pass` — skips this entry and continues to the next review-stage candidate; only shown when at least one other candidate exists
    - `[Q] Quit` — aborts the picker; no entry is claimed and no session is started
  - if all candidates are exhausted without a successful pick the command exits with an error

Client option references:

| Client | Model option | Effort option | Reference |
|---|---|---|---|
| `claude` | `--model <model>` | `--effort low|medium|high` | `https://code.claude.com/docs/en/cli-usage` |
| `codex` | `--model <model>` | `--config model_reasoning_effort="<level>"` | `https://developers.openai.com/codex/config-reference` |
| `opencode` | `--model provider/model` | none in project mapping | `https://dev.opencode.ai/docs/cli/` |
| `gemini` | `--model <model>` | none | `https://google-gemini.github.io/gemini-cli/docs/get-started/configuration.html` |

---

### `worktree-info.php`
Displays the git worktree context detected for the current script: whether it runs inside a linked worktree or the main worktree, and the resolved roots of both.

```bash
php scripts/worktree-info.php
```

---

### `test-backlog-workflow.php`
Runs reusable sequential validation campaigns for `php scripts/backlog/backlog.php` against test campaign artifacts under `local/tests/`.

```bash
php scripts/tests/test-backlog-workflow.php
php scripts/tests/test-backlog-workflow.php --campaign help
php scripts/tests/test-backlog-workflow.php --campaign scoped-task-lifecycle
php scripts/tests/test-backlog-workflow.php --allow-remote --campaign feature-review-lifecycle
php scripts/tests/test-backlog-workflow.php --keep-artifacts
```

Notes:
- the script never uses `local/backlog-board.yaml` or `local/backlog-review.md` directly
- it passes `--test-mode`, `--board-file`, and `--review-file` to `backlog.php` with campaign files under `local/tests/`
- it passes `--worktree-dir` to `backlog.php` with isolated test worktrees under `local/tests/test-worktrees/`
- by default it injects `SOMANAGER_GIT_OFFLINE=1` into backlog subprocesses, so `GitClient` logs and skips network commands while local Git commands still run
- `--allow-remote` removes that offline guard for the whole run and allows real Git network operations such as push, fetch, pull, and remote branch deletion
- `feature-review-lifecycle` is skipped unless `--allow-remote` is enabled
- the remote campaign creates a temporary PR base branch instead of targeting `main`
- cleanup always runs in best effort and only acts on resources recorded by the test context
- use `--keep-artifacts` to inspect test campaign artifacts after the run
- detailed reusable campaign intent is documented in `doc/development/script-backlog-test-scenarios.md`

---

### `test-validation.php`
Runs unit tests for the `scripts/src/Validation/` classes (currently `ScriptExecBitValidator`). No Docker, no network.

```bash
php scripts/tests/test-validation.php
php scripts/tests/test-validation.php --suite=ScriptExecBitValidatorTest
```

Notes:
- available suites: `ScriptExecBitValidatorTest`
- `--suite=<name>` runs only the named suite; omit to run all

---

### `test-dev-env.php`
Runs unit tests for the DevEnv manifest/lockfile/resolver/planner classes. No Docker required.

```bash
php scripts/tests/test-dev-env.php
php scripts/tests/test-dev-env.php --suite=ManifestParserTest
php scripts/tests/test-dev-env.php --suite=InstallPlannerTest
```

Notes:
- available suites: `VersionConstraintTest`, `ManifestParserTest`, `ManifestResolverTest`, `LockfileManagerTest`, `StateInspectorTest`, `InstallPlannerTest`
- `--suite=<name>` runs only the named suite; omit to run all

---

### `test-setup.php`
Runs subprocess integration tests for `setup.php`. Spawns the script as a child process; no Docker or actual package installation required.

```bash
php scripts/tests/test-setup.php
```

---

### `setup.php`
Manages host-level dependencies and the project setup. Subcommand-based runner.

Subcommands:
- `update` — re-resolve the manifest against available sources and write the lockfile
- `install` — install or upgrade host dependencies from the lockfile, then run project setup (composer, npm, Doctrine migrations)
- `verify` — compare system state, lockfile, and manifest without mutating (exit `0` aligned, `1` discrepancies)
- `uninstall` — remove installed deps according to `pre_existing` flags and `on_uninstall_pre_existing` policy
- `reset` — drop the database and remove Docker volumes (does **not** touch host deps or client binaries)
- `status` — show manifest, lockfile, installed versions, Docker service status, last migration (no mutation)
- `dep-config` — read/write per-dep overrides in the lockfile (`get`/`set`/`unset`)

```bash
php scripts/setup.php help                           # show help (also displayed when no subcommand is passed)
php scripts/setup.php help <subcommand>              # detail one subcommand

php scripts/setup.php update                         # resolve + write lockfile
php scripts/setup.php update --preview-only          # resolution diff + plan, no apply
php scripts/setup.php update --dry-run               # plan + simulated commands, no apply
php scripts/setup.php update --force                 # apply without confirmation

php scripts/setup.php install                        # apply lockfile + composer/npm/migrations
php scripts/setup.php install --preview-only
php scripts/setup.php install --dry-run
php scripts/setup.php install --force

php scripts/setup.php verify                         # alignment check, no mutation
php scripts/setup.php status                         # full system / lockfile / docker overview, no mutation

php scripts/setup.php uninstall                      # remove non-pre-existing deps
php scripts/setup.php uninstall --restore            # one-shot: pre-existing deps downgrade to previous_version
php scripts/setup.php uninstall --keep               # one-shot: pre-existing deps untouched

php scripts/setup.php reset                          # drop DB + remove docker volumes (confirm prompt)
php scripts/setup.php reset --keep-volumes           # stop containers but keep volumes
php scripts/setup.php reset --force                  # skip confirmation

php scripts/setup.php dep-config get claude
php scripts/setup.php dep-config set claude on_uninstall_pre_existing restore
php scripts/setup.php dep-config unset claude on_uninstall_pre_existing
```

Notes:
- Lockfile is local: `scripts/resources/dependencies.lock` is **not committed** on this project — it stores per-host `pre_existing` state and side-effect paths. Each machine generates its own via `setup.php update`. `install` rejects an absent or sentinel lockfile (`generated_at: ~`).
- Mutation subcommands (`update`, `install`, `uninstall`, `reset`) accept `--preview-only`, `--dry-run`, and `--force`. `--preview-only` and `--dry-run` are mutually exclusive. `--force` still prints the preview for traceability.
- `dep-config` mutations are local and reversible (`unset`); no `--force` flag.
- `install` runs Doctrine migrations via **host PHP CLI** (`php backend/bin/console doctrine:migrations:migrate --no-interaction`), not via `docker compose exec`. Requires the `db` container up; the `php` container is not required (compatible with `server.php start --minimal`). `DATABASE_URL` is normalised from `db:5432` to `localhost:5432` automatically.
- `verify`: `0` if aligned, `1` for missing/outdated/orphaned/unlocked deps. Run `setup.php update` first if deps appear unlocked.
- `uninstall` policy chain: `--restore`/`--keep` flag > lockfile override (`dep-config`) > manifest per-dep `on_uninstall_pre_existing` > manifest default > framework default (`keep`).
- `reset` is destructive: explicit confirmation required unless `--force`. Host dependencies (apt packages, npm clients) are **not** removed by `reset` — use `uninstall` for that.
- BLOCKED items (version below minimum with `on_existing_below_min: error`) make the command exit before the preview is shown.

---

### `server.php`
Manages Docker Compose services for the development environment. Subcommand-based runner.

Subcommands:
- `start` — bring services up (full profile by default, `--minimal` for db + redis only)
- `stop` — bring services down
- `restart` — stop then start
- `status` — `docker compose ps` for the project
- `health` — health checks via native PHP probes (PDO / TCP socket / HTTP); no `pg_isready` or `redis-cli` required on the host

Docker Compose profiles: `db` and `redis` have no profile (always started); `php`, `worker`, `nginx`, `node`, and `mercure` use the `full` profile.

```bash
php scripts/server.php help                  # show help (also displayed when no subcommand is passed)

php scripts/server.php start                 # start all services (profile full)
php scripts/server.php start --minimal       # start db + redis only
php scripts/server.php start --preview-only  # show plan, no apply
php scripts/server.php start --dry-run       # plan + simulated commands, no apply
php scripts/server.php start --force         # apply without confirmation

php scripts/server.php stop                  # stop all services
php scripts/server.php restart               # stop then start
php scripts/server.php status                # docker compose ps
php scripts/server.php health                # native PHP probes (db via PDO, redis via TCP RESP PING, http via file_get_contents)
```

Notes:
- Mutation subcommands (`start`, `stop`, `restart`) accept `--preview-only`, `--dry-run`, and `--force`. `--preview-only` and `--dry-run` are mutually exclusive.
- `start --minimal` is the recommended mode for remote dev servers where AI agents run on the host: it keeps `db` and `redis` up while skipping the heavier `full`-profile services.
- `health` deliberately depends only on PHP-native facilities (PDO, raw TCP socket, HTTP) so the host manifest does not need to ship `postgresql-client` or `redis-tools` for diagnostic purposes.

---

### `migrate.php`
Runs Doctrine migrations in the PHP container, or generates a new migration diff against an isolated temporary database.

```bash
php scripts/migrate.php             # run migrations
php scripts/migrate.php --dry-run   # simulate without applying
php scripts/migrate.php --generate  # generate a migration diff using an isolated temp DB
```

`--generate` creates a temporary database named `{agentCode}_migrate_gen`, applies all existing migrations on it, then runs `doctrine:migrations:diff`. The temporary database is dropped after the diff.
When invoked from a WA, the agent code is derived from the worktree path. Outside a WA, `SOMANAGER_AGENT` is used as fallback.

`--generate` runs entirely locally without Docker or `psql`: it uses PHP/PDO to create and drop the temporary database on `localhost:5432`, and runs `php bin/console` from the checkout's `backend/` directory. The Docker PostgreSQL service must be running and accessible on `localhost:5432`. If the PHP/DB connection fails, the command exits with a structured error indicating the DSN, working directory, cause, and action expected.

---

### `claude-auth.php`
Manages Claude CLI auth with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/claude-auth.php status
php scripts/claude-auth.php sync
php scripts/claude-auth.php login
php scripts/claude-auth.php sync --force
```

Use `login` to authenticate in WSL, then sync the resulting auth files to `./.docker/claude/shared/`.

---

### `codex-auth.php`
Manages Codex CLI auth with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/codex-auth.php status
php scripts/codex-auth.php sync
php scripts/codex-auth.php login
php scripts/codex-auth.php sync --force
```

Important rule:
- the script only accepts a ChatGPT-based Codex login
- if Codex is logged in with an API key, `sync` fails on purpose because `codex_cli` must consume account usage limits, not API credits

Use `login` to authenticate with ChatGPT in WSL, then sync the resulting auth directory to `./.docker/codex/shared/`.

---

### `opencode-auth.php`
Manages OpenCode provider credentials with WSL as the source of truth, then synchronizes the Docker shared copy used by the containers.

```bash
php scripts/opencode-auth.php status
php scripts/opencode-auth.php sync
php scripts/opencode-auth.php login
php scripts/opencode-auth.php login openrouter
```

Important rule:
- OpenCode currently authenticates through provider credentials
- no subscription-based account usage mode has been detected, so this connector cannot currently satisfy the same “use plan limits instead of API credits” constraint as `codex_cli`

Use `login [provider]` to configure a provider in WSL, then sync the resulting auth file to `./.docker/opencode/shared/`.

---

### `console.php`
Runs any `bin/console` command in the PHP container.

```bash
php scripts/console.php cache:clear
php scripts/console.php doctrine:migrations:status
php scripts/console.php somanagent:seed:web-team
```

---

### `node.php`
Runs reusable developer commands in the Node container without repeating raw `docker compose exec`.

```bash
php scripts/node.php type-check
php scripts/node.php run build
php scripts/node.php exec npm install
php scripts/node.php shell
```

Use this script in priority for repeated frontend container actions such as type-checking, builds, linting, tests, or an interactive shell.

---

### `db.php`
Runs database-related commands (PostgreSQL psql + Doctrine operations).

```bash
# PostgreSQL commands
php scripts/db.php query "SELECT 1"
php scripts/db.php exec -c "\\dt"
php scripts/db.php shell

# Database reset (recreate + migrations)
php scripts/db.php reset
php scripts/db.php reset --fixtures
php scripts/db.php reset --fixtures --force
```

Use this script in priority for repeated local database inspection instead of raw `docker exec ... psql ...`.

---

### `code-search.php`
Searches a term across `backend/src/` PHP files, `frontend/src/` TS/TSX files, `scripts/src/` PHP files, `doc/**/*.md` and root `*.md` Markdown files, and `scripts/resources/**/*.yaml` files.
Uses `rg` by default when available, with the legacy PHP scanner kept as an explicit alternative via `--engine php`.

```bash
php scripts/toolkit/code-search.php UserRepository
php scripts/toolkit/code-search.php UserRepository --engine rg
php scripts/toolkit/code-search.php UserRepository --engine php
php scripts/toolkit/code-search.php AgentController --backend
php scripts/toolkit/code-search.php useAgent --frontend --context 2
php scripts/toolkit/code-search.php CodeSearchRunner --scripts
php scripts/toolkit/code-search.php SOMANAGER_AGENT --doc
php scripts/toolkit/code-search.php review-request --resources
```

Use this script in priority for source lookup and usage discovery instead of ad hoc `grep` commands.

---

### `github.php`
Wraps a few common GitHub CLI/API flows used during delivery work, especially around pull requests.

```bash
php scripts/toolkit/github.php pr-list
php scripts/toolkit/github.php pr-view 42
php scripts/toolkit/github.php pr-create --title "My PR" --head <branch> --body-file local/tmp/pr_body.md
php scripts/toolkit/github.php pr-merge 42
php scripts/toolkit/github.php pr-close 42
php scripts/toolkit/github.php pr-edit 42 --title "Updated title" --body-file local/tmp/pr_body.md
php scripts/toolkit/github.php pr-merge --help
```

Notes:
- `--head <branch>` is required for `pr-create`
- `--body-file <file>` is preferred over `--body` to avoid shell quoting issues; the script reads and deletes the file automatically
- Requires `GITHUB_TOKEN` in `.env` and a detectable `origin` remote pointing to GitHub.
- Use `php scripts/toolkit/github.php <command> --help` for per-command option details (e.g. `pr-merge --help`).
- For backlog tasks started from a `[feature-slug][task-slug]` prefix, the local merge between the child task branch and the parent feature branch happens before any GitHub PR flow; `github.php` is only relevant once work is promoted back to a branch meant for remote review.

---

### `logs.php`
Displays a Docker container's logs in real time (tail -f).

```bash
php scripts/logs.php          # logs from the php container (default)
php scripts/logs.php db       # PostgreSQL logs
php scripts/logs.php node     # Vite logs
php scripts/logs.php nginx    # Nginx logs
```

Use this script in priority instead of raw `docker logs` when the target container is supported.

---

### `health.php`
Checks API reachability, then runs `somanagent:health` for the detailed connector battery.

```bash
php scripts/health.php
php scripts/health.php --url http://my-server:8080
```

---

### `review.php`
Runs mechanical checks on modified and untracked files. With `--base=<ref>`, it also checks files changed by commits between that base and `HEAD`. Designed to be used by AI agents during the `review` command, before manual inspection.

Blockers (exit code 1):
- French strings (accented characters) in `backend/src/` `.php` and `frontend/src/` `.ts/.tsx`
- Missing PHPDoc on `public function` declarations in `backend/src/` (migrations excluded)
- Missing JSDoc on export declarations in `frontend/src/` `.ts/.tsx`
- Failing frontend TypeScript type-check when modified files include `frontend/src/` `.ts/.tsx`
- Failing file validation for the review scope
- Missing or unused translation keys
- Failing dedicated PHPUnit tests mapped from modified `backend/src/Service/...` files
- Failing PHPStan analysis for the backend and/or scripts scope when matching PHP source files are in review scope

Informational (no exit code impact):
- List of modified files
- List of untracked files
- List of committed files since `--base`, when provided
- Modified backend services without a dedicated mapped PHPUnit test

Limitations: only detects accented characters as French strings — complement with a manual diff review for unaccented French words (`Valider`, `Commenter`, etc.). JSDoc check covers export declarations only, not re-exports.

Rules:
- PHPDoc checks ignore unit-test support code under `backend/tests/` and backlog workflow tests under `scripts/src/Test/`
- French strings are blocked in source files; backlog file-format labels must be written in English, for example `To do` and `Usage rules`

The review flow skips container-backed validations that depend on local uncommitted environment files such as `.env`. Frontend TypeScript checking remains part of review through `php scripts/toolkit/validate-files.php --with-types --review-scope ...`, which runs the local `frontend` package script instead of raw `npx tsc`.

```bash
php scripts/review.php
php scripts/review.php --base=HEAD~1
```

---

### `phpunit.php`
Runs PHPUnit using the dedicated configuration and binary for the requested scopes. By default all configured scopes are tested. Use `--scope=<name>` to restrict the scope.

```bash
php scripts/toolkit/phpunit.php
php scripts/toolkit/phpunit.php --scope=backend
php scripts/toolkit/phpunit.php --suite local-unit
php scripts/toolkit/phpunit.php backend/tests/Unit/Service/MyServiceTest.php
```

Notes:
- currently available scope is `backend`
- explicit file arguments determine their own scope; using `--scope` with files is forbidden
- invalid or non-existent files print a warning but execution proceeds for valid files
- the wrapper automatically injects the configuration file for the scope, but does **not** inject environment variables. If you need local test isolation, you must prefix your call (e.g., `SOMANAGENT_PHPUNIT_LOCAL=1 php scripts/toolkit/phpunit.php`)
- `--suite <name>` allows running a specific test suite defined in the scope's PHPUnit configuration

---

### `phpstan.php`
Runs PHPStan static analysis using `config/phpstan.neon`. By default all configured scopes are analysed. Use `--scope=<name>` to restrict the scope; repeat `--scope` to analyse several explicit scopes.

The PHPStan binary and all extensions (`phpstan-symfony`, `phpstan-doctrine`, `phpstan-phpunit`) are installed in `scripts/vendor` so that `php scripts/scripts-install.php` is the only prerequisite — no backend Docker environment needed.

```bash
php scripts/toolkit/phpstan.php
php scripts/toolkit/phpstan.php --scope=backend
php scripts/toolkit/phpstan.php --scope=scripts
php scripts/toolkit/phpstan.php --scope=backend --scope=scripts
php scripts/toolkit/phpstan.php backend/src/Controller/AgentController.php
```

Notes:
- available scopes are `backend` and `scripts`
- explicit file arguments bypass scope selection and analyse only those files
- the wrapper injects `--configuration config/phpstan.neon --debug`
- `--debug` forces single-threaded mode, required on WSL2

---

### `rector.php`
Runs Rector using `config/rector.php`. By default all configured scopes are processed. Use `--scope=<name>` to restrict the scope; repeat `--scope` to process several explicit scopes. Always prefer `--dry-run` first to review planned changes.

The Rector binary is installed in `scripts/vendor` alongside PHPStan.

```bash
php scripts/toolkit/rector.php --dry-run
php scripts/toolkit/rector.php
php scripts/toolkit/rector.php --scope=backend --dry-run
php scripts/toolkit/rector.php --scope=scripts
php scripts/toolkit/rector.php --scope=backend --scope=scripts --dry-run
```

Notes:
- available scopes are `backend` and `scripts`
- `--scope` is consumed by the wrapper; remaining arguments are forwarded to Rector
- the wrapper injects `--config config/rector.php` followed by the scope paths as positional arguments

---

### `validate-backend-tests.php`
Runs isolated local PHPUnit from WSL for backend unit tests that must stay independent from Docker services, databases, Redis, and real external APIs.

For service-driven validation, the dedicated test mapping is `backend/src/Service/...` -> `backend/tests/Unit/Service/...Test.php`.

```bash
php scripts/validate-backend-tests.php backend/src/Service/AgentModelRecommendationPolicyResolver.php
php scripts/validate-backend-tests.php backend/src/Service/VcsRepositoryUrlService.php scripts/review.php
php scripts/validate-backend-tests.php --all
```

Rules:
- modified service files are detected only from the explicit file list passed to the script
- `--all` runs the `local-unit` testsuite only
- the dedicated mapping preserves subdirectories under `Service/`
- local unit tests must live under `backend/tests/Unit/`
- local unit tests must extend `Sowapps\SoManAgent\Tests\Support\LocalUnitTestCase`
- local unit tests must not boot the Symfony kernel, access DB/Redis, or instantiate real external HTTP/API clients
- a missing dedicated test is reported but does not fail validation
- an existing dedicated test must pass with no PHPUnit warning, notice, or deprecation

---

### `validate-files.php`
Runs targeted backend/frontend validations (PHP syntax, Symfony container lint, OpenAPI consistency, ESLint, and optional TypeScript type-checking) for an explicit list of files.
Use `--with-types` for frontend changes instead of raw `npx tsc`; it runs the project type-check wrapper.
Use `--review-scope` when the command is executed from the mechanical review flow. In that mode, container-backed checks are skipped because they depend on local runtime state and uncommitted files such as `.env`, which are outside the review diff scope, but frontend TypeScript type-checking still runs through the local `frontend` package script.

```bash
php scripts/toolkit/validate-files.php backend/src/Controller/TaskController.php frontend/src/api/tickets.ts
php scripts/toolkit/validate-files.php --with-types backend/src/Service/StoryExecutionService.php
php scripts/toolkit/validate-files.php --with-types --review-scope backend/src/Service/StoryExecutionService.php
```

---

### `validate-agent-launchers.php`
Cross-checks the CLI flags declared by each `AgentClientLauncher::requiredCliFlags()` against the local `<client> --help` output, so an upstream CLI removal (e.g. `claude` dropping `--cwd` in v2.x) is caught before it breaks a real agent launch.

```bash
php scripts/backlog/validate-agent-launchers.php
```

Rules:
- exits `0` when every required flag is still advertised by the available binaries
- exits `1` when at least one required flag is missing for an installed binary
- skips a launcher (without failing) when its binary is not installed locally — keeps CI green on runners without optional clients
- combines stdout and stderr when reading `<bin> --help` so short-help CLIs that print to stderr are covered

---

### `fix-permissions.php`
Restores the exec bit on shebang-bearing `scripts/*.php` entrypoints. Mirror counterpart of the `ScriptExecBitValidator` plugged into `validate-files.php`: the validator reports regressions, this script repairs them.

Crucially, both dimensions of the exec bit are checked:
- the **filesystem** mode (`is_executable()`);
- the **git index** mode (`100755` vs `100644`, read from `git ls-files --stage`).

Under WSL with `core.filemode = false` a file can be executable on disk while still recorded as `100644` in the index — exactly the broken state a fresh clone would receive on another machine. This script catches and repairs that mismatch.

```bash
php scripts/toolkit/fix-permissions.php           # apply the fix
php scripts/toolkit/fix-permissions.php --dry-run # list what would be fixed without touching anything
```

Rules:
- scope is intentionally narrow: only `scripts/*.php` (no recursion, no other directories)
- a file is fixed when it declares a `#!/usr/bin/env` shebang and either lacks the fs exec bit, or is tracked with a `100644` index mode
- existing read/write bits are preserved — only the three exec bits are forced on
- after `chmod +x`, the runner also runs `git update-index --chmod=+x` on every tracked file so the bit lands in the next commit
- `--dry-run` lists the candidates and labels each row with its fs and index state, without changing anything

---

### `wsl-claude-install.sh`
Installs Claude CLI inside the configured WSL distro so it can be used from the project in a native Linux environment.

```bash
bash scripts/wsl-claude-install.sh
```

This script currently targets a configured WSL distro name internally.

---

### `wsl-codex-install.sh`
Installs or upgrades the OpenAI Codex CLI directly inside WSL, so it can later be started from a native Linux shell in the project.

```bash
bash scripts/wsl-codex-install.sh
bash scripts/wsl-codex-install.sh --skip-login
```

After installation:

```bash
codex login
php scripts/codex-auth.php sync
cd ~/projects/somanagent
codex
```

---

### `wsl-migrate.sh`
Copies the project from `/mnt/...` to the WSL native filesystem to avoid slow Docker bind mounts on Windows-backed filesystems.

```bash
bash scripts/wsl-migrate.sh
bash scripts/wsl-migrate.sh --dest ~/projects/somanagent
```

Use this when the repository was cloned under `/mnt/c/...` and local Docker I/O is too slow.

## Script Header Convention

Each script must start with this block (after the shebang):

**PHP:**
```php
#!/usr/bin/env php
<?php
// Description: Short one-line description
// Usage: php scripts/script-name.php [options]
// Usage: php scripts/script-name.php --flag value
```

**Bash:**
```bash
#!/usr/bin/env bash
# Description: Short one-line description
# Usage: bash scripts/script-name.sh [options]
```

`help.php` automatically parses these headers to generate its display.
