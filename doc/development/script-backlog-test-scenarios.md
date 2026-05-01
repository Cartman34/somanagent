# Script Backlog Test Scenarios

Reusable manual test scenarios for AI agents validating `php scripts/backlog.php`.

Use this document when validating a refactor or behavior change in the local backlog workflow. It is written to be replayed later without relying on chat context.

## Scope

This document covers:
- global help and per-command help
- todo task management
- feature start/release/assignment/status flows
- child task start/review/rework/merge flows
- feature review/approve/reject/merge flows
- worktree inspection and cleanup helpers

It does not replace the workflow rules in [agent-workflow.md](agent-workflow.md). It is a validation scenario document.

## Preconditions

Before running the scenarios:

1. Run all commands from `WP`, never from a `WA`.
2. Ensure scripts dependencies are installed:
   - `php scripts/scripts-install.php`
3. Ensure the repository is in a controlled local test state:
   - no uncommitted user work in `WP`
   - no accidental worktrees to preserve under `.agent-worktrees/`
4. Back up the local-only workflow files if needed:
   - `local/backlog-board.md`
   - `local/backlog-review.md`
5. Use a dedicated local test agent code set, for example:
   - `d01`
   - `d02`
   - `d03`

## Execution Rules

During validation:

1. Follow scenarios in order unless the tested change is explicitly isolated.
2. After each command, verify both:
   - CLI output
   - resulting backlog/review/worktree state
3. If one scenario fails, stop and record:
   - command
   - observed output
   - expected output
   - resulting state drift
4. Do not manually fix `local/backlog-board.md` or `local/backlog-review.md` in the middle of the run unless the test itself is about manual recovery.

## Test Naming Rules

All test-created entries must use the `test-` prefix.

Recommended names in this document:

- plain feature text:
  - `test-plain-feature-alpha`
- typed plain feature text:
  - `[fix] test-fix-feature-beta`
- scoped feature:
  - `test-scoped-feature`
- scoped child tasks:
  - `[test-scoped-feature][test-child-a] Implement test child task A`
  - `[test-scoped-feature][test-child-b] Implement test child task B`

Temporary review files must also use `test-` names under `local/tmp/`.

Each scenario below must create its own `test-*` backlog input immediately before the command that consumes it.

## Scenario 1 - Help And Command Discovery

### Goal

Validate that backlog help is available globally and per command.

### Steps

1. `php scripts/backlog.php`
2. `php scripts/backlog.php help`
3. `php scripts/backlog.php help work-start`
4. `php scripts/backlog.php work-start --help`
5. `php scripts/backlog.php help task-review-request`

### Expected checks

- global help prints command list and global options
- per-command help prints description, options, examples, and notes
- `help <command>` and `<command> --help` are equivalent

## Scenario 2 - Todo Management

### Goal

Validate queued task insertion, ordering, listing, and removal.

### Steps

1. `php scripts/backlog.php task-todo-list`
2. `php scripts/backlog.php task-create Test inserted at end`
3. `php scripts/backlog.php task-create --position=start Test inserted at start`
4. `php scripts/backlog.php task-create --position=index --index=2 Test inserted at index`
5. `php scripts/backlog.php task-todo-list`
6. Remove one inserted task with:
   - `php scripts/backlog.php task-remove <exact task reference expected by the command>`

### Expected checks

- inserted tasks appear in the expected order
- typed input such as `[feat]` or `[fix]` keeps valid metadata behavior
- removing a queued task updates only the todo section

## Scenario 3 - Start Plain Feature

### Goal

Validate `work-start` on a plain queued task.

### Steps

1. Create the plain test task:
   - `php scripts/backlog.php task-create test-plain-feature-alpha`
2. Confirm next plain task with:
   - `php scripts/backlog.php task-todo-list`
3. Start it:
   - `php scripts/backlog.php work-start --agent d01`
4. Inspect result:
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php status --agent d01`
   - `php scripts/backlog.php worktree-list`

### Expected checks

- the first queued plain task leaves `To do`
- one active `kind=feature` entry is created
- feature has `stage=development`
- branch is created with the expected type
- managed worktree exists for `d01`
- `work-start` output includes the feature summary and assigned worktree
- the created feature slug corresponds to `test-plain-feature-alpha`

## Scenario 4 - Release Plain Feature Without Development

### Goal

Validate `feature-release` on a feature with no actual development ahead of base.

### Steps

1. Release the active feature:
   - `php scripts/backlog.php feature-release --agent d01`
2. Inspect:
   - `php scripts/backlog.php task-todo-list`
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php worktree-list`

### Expected checks

- feature returns to the top of `To do`
- no active feature remains for `d01`
- branch/worktree cleanup behavior matches the documented workflow

## Scenario 5 - Assignment Flow

### Goal

Validate feature assignment and unassignment permissions.

### Steps

1. Create the assignment test task:
   - `php scripts/backlog.php task-create test-assign-feature`
2. Start it:
   - `php scripts/backlog.php work-start --agent d01`
3. Run with manager role:
   - `SOMANAGER_ROLE=manager php scripts/backlog.php feature-assign test-assign-feature --agent d02`
4. Inspect:
   - `php scripts/backlog.php status --agent d02`
5. Unassign with manager role:
   - `SOMANAGER_ROLE=manager php scripts/backlog.php feature-unassign test-assign-feature --agent d02`

### Expected checks

- assignment updates `meta.agent`
- target worktree is prepared for the assigned agent
- unassignment removes the assignment cleanly

### Negative checks

1. As developer with mismatched `SOMANAGER_AGENT`, assignment must fail.
2. As developer, unassigning another agent’s feature must fail.

## Scenario 6 - Start Scoped Feature And Local Child Task

### Goal

Validate `work-start` on scoped queued tasks.

### Steps

1. Create the first scoped child task:
   - `php scripts/backlog.php task-create [test-scoped-feature][test-child-a] Implement test child task A`
2. Confirm next queued entry is the scoped task:
   - `php scripts/backlog.php task-todo-list`
3. Start it:
   - `php scripts/backlog.php work-start --agent d01`
4. Inspect:
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php status test-scoped-feature`
   - `php scripts/backlog.php worktree-list`

### Expected checks

- one parent `kind=feature` exists for `test-scoped-feature`
- one child `kind=task` exists for `test-child-a`
- child task branch follows `<type>/<feature>--<task>`
- parent feature branch exists separately
- parent contribution block contains `[task:test-child-a]`
- `work-start` output includes the child task, parent feature, and assigned worktree

## Scenario 7 - Child Task Review Cycle

### Goal

Validate local child task review commands (reject, rework, approve). Demonstrated here on task A; the same flow applies to task B in Scenario 9.

### Steps

1. Submit the task for review:
   - `php scripts/backlog.php review-request --agent d01`
2. Inspect the review queue:
   - `php scripts/backlog.php review-next`
3. Run the mechanical check:
   - `php scripts/backlog.php task-review-check test-scoped-feature/test-child-a`
4. Reject it:
   - create a local review body under `local/tmp/`
   - `php scripts/backlog.php task-review-reject test-scoped-feature/test-child-a --body-file local/tmp/test-task-review-reject.md`
5. Rework and resubmit:
   - `php scripts/backlog.php rework --agent d01 test-scoped-feature/test-child-a`
   - `php scripts/backlog.php review-request --agent d01`
6. Approve:
   - `php scripts/backlog.php task-review-approve test-scoped-feature/test-child-a`

### Expected checks

- stage transitions follow `development → review → rejected → development → review → approved`
- review notes are written to `local/backlog-review.md` on rejection
- review notes are cleared on approval

## Scenario 8 - Merge Approved Child Task

### Goal

Validate local merge of one approved child task into its parent feature.

### Steps

1. Merge the approved task:
   - `php scripts/backlog.php feature-task-merge test-scoped-feature/test-child-a`
2. Inspect:
   - `php scripts/backlog.php status test-scoped-feature`
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php worktree-list`

### Expected checks

- child task active entry disappears
- parent feature remains active with `agent=none`
- parent contribution block still records the merged child content
- child task worktree cleanup follows documented behavior

## Scenario 9 - Start Second Child Task And Complete Its Review

### Goal

Validate that after merging task A, `work-start` picks up the next queued scoped task and the full review cycle repeats for task B.

### Steps

1. Create the second scoped child task:
   - `php scripts/backlog.php task-create [test-scoped-feature][test-child-b] Implement test child task B`
2. Confirm the agent has no active entry after the merge:
   - `php scripts/backlog.php status --agent d01`
3. Pick up task B:
   - `php scripts/backlog.php work-start --agent d01`
4. Inspect:
   - `php scripts/backlog.php status test-scoped-feature`
5. Submit, review, and approve task B (same cycle as Scenario 7):
   - `php scripts/backlog.php review-request --agent d01`
   - `php scripts/backlog.php task-review-approve test-scoped-feature/test-child-b`
6. Merge task B:
   - `php scripts/backlog.php feature-task-merge test-scoped-feature/test-child-b`

### Expected checks

- second child task is created as active `kind=task` assigned to d01
- parent feature container (`kind=feature`) remains with `agent=none` throughout
- contribution blocks record both merged child tasks after step 6
- agent has no active entry after the merge

## Scenario 10 - Feature Review Flow

### Goal

Validate remote feature review transitions once all child tasks are merged.

### Steps

1. Developer takes integration ownership of the feature:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-assign --agent d01 test-scoped-feature`
2. Submit the feature for review:
   - `php scripts/backlog.php review-request --agent d01`
3. Inspect:
   - `php scripts/backlog.php review-next`
   - `php scripts/backlog.php status test-scoped-feature`
4. Run mechanical check:
   - `php scripts/backlog.php feature-review-check test-scoped-feature`
5. Reject:
   - `php scripts/backlog.php feature-review-reject test-scoped-feature --body-file local/tmp/test-feature-review-reject.md`
6. Rework and resubmit:
   - `php scripts/backlog.php rework --agent d01 test-scoped-feature`
   - `php scripts/backlog.php review-request --agent d01`
7. Approve:
   - `php scripts/backlog.php feature-review-approve test-scoped-feature`

### Expected checks

- stage changes follow the documented feature review lifecycle
- PR metadata and review notes behave consistently with the workflow

## Scenario 11 - Block And Unblock Feature

### Goal

Validate blocked flag handling and PR title synchronization.

### Steps

1. Block:
   - `php scripts/backlog.php feature-block --agent d01 test-scoped-feature`
2. Inspect:
   - `php scripts/backlog.php status test-scoped-feature`
3. Unblock:
   - `php scripts/backlog.php feature-unblock --agent d01 test-scoped-feature`
4. Inspect again.

### Expected checks

- `blocked=yes` appears and disappears in backlog state
- PR title is updated when a PR exists

## Scenario 12 - Close And Merge Feature

### Goal

Validate final feature closure and merge behavior.

### Steps

1. Create a dedicated closable fix feature:
   - `php scripts/backlog.php task-create [fix] test-fix-feature-beta`
2. Start it:
   - `php scripts/backlog.php work-start --agent d02`
3. Close the unmerged feature when the workflow requires closing:
   - `php scripts/backlog.php feature-close test-fix-feature-beta`
4. For the approved scoped feature, merge it:
   - `php scripts/backlog.php feature-merge test-scoped-feature`
5. Inspect:
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php worktree-list`

### Expected checks

- active feature disappears after the terminal action
- PR is closed or merged according to the command
- feature merge can reuse the existing PR body when `--body-file` is omitted
- managed worktree cleanup is coherent

## Scenario 13 - Worktree Helpers

### Goal

Validate inspection and cleanup helpers independently.

### Steps

1. `php scripts/backlog.php worktree-list`
2. `php scripts/backlog.php worktree-clean --dry-run`
3. `php scripts/backlog.php worktree-clean`
4. `php scripts/backlog.php worktree-list`

### Expected checks

- managed and external worktrees are classified correctly
- dry-run reports intended actions without mutating state
- cleanup removes only safe abandoned managed worktrees

## Scenario 14 - Negative And Guardrail Checks

### Goal

Validate explicit failures and guardrails.

### Checks

1. Run one backlog command from a `WA` and confirm it fails.
2. Call a developer command without `--agent` and confirm it fails.
3. Call a reviewer command with `--agent` only if the command explicitly forbids it and confirm it fails when expected.
4. Try `status` without `<feature>` and without `--agent` and confirm it fails.
5. Try to merge or release with invalid stage and confirm it fails.
6. Try to reuse an already-active child task slug and confirm it fails.

## Cleanup

After validation:

1. Restore the original local backlog files if they were backed up.
2. Remove temporary files created under `local/tmp/`.
3. Inspect `.agent-worktrees/` and clean any leftover managed worktrees.
4. Confirm final local state with:
   - `php scripts/backlog.php feature-list`
   - `php scripts/backlog.php worktree-list`
5. Confirm no leftover `test-*` active entries remain.

## Minimal Smoke Suite

When the full scenario set is too expensive, run at least:

1. `php scripts/backlog.php`
2. `php scripts/backlog.php help work-start`
3. `php scripts/backlog.php task-todo-list`
4. `php scripts/backlog.php feature-list`
5. `php scripts/backlog.php worktree-list`
6. `php scripts/backlog.php status --agent <known-agent>`

This smoke suite is not enough for workflow refactors. Use the full scenarios for structural changes.
