# Script Backlog Test Scenarios

Reusable manual test scenarios for AI agents validating `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php`.

Use this document when validating a refactor or behavior change in the local backlog workflow. It is written to be replayed later without relying on chat context.

## Scope

This document covers:
- global help and per-command help via --help
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

Validate that backlog help is available globally and per command via `--help`.

### Steps

1. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php`
2. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start --help`
3. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request --help`
4. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php help`
5. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php help work-start`

### Expected checks

- global help (no arguments) prints command list and global options
- per-command help (`<command> --help`) prints description, options, examples, and notes
- `help` alone returns "Unknown command: help. Run with --help for the list of available commands."
- `help work-start` returns the same unknown-command error (no silent fallback)

## Scenario 2 - Todo Management

### Goal

Validate queued task insertion, ordering, listing, and removal, including the stable references exposed by `todo-list`.

### Steps

1. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
2. Write `[scenario-todo-end] Test inserted at end` to `local/tmp/test-scenario-todo-end.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-scenario-todo-end.md`
3. Write `[scenario-todo-start] Test inserted at start` to `local/tmp/test-scenario-todo-start.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-scenario-todo-start.md --position=start`
4. Write `[scenario-todo-index] Test inserted at index` to `local/tmp/test-scenario-todo-index.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-scenario-todo-index.md --position=index --index=2`
5. Write `[scenario-feature][scenario-task] Stable ref task` to `local/tmp/test-scenario-feature-task.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-scenario-feature-task.md`
6. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
7. Remove one inserted task by its stable reference shown in todo-list:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-remove scenario-todo-index`

### Expected checks

- inserted tasks appear in the expected order
- typed input such as `[feat]` or `[fix]` keeps valid metadata behavior
- todo-list shows each queued entry with its stable reference between brackets, in the form `N. [<ref>] <text>`
- the scoped entry `[scenario-feature][scenario-task] Stable ref task` is printed as `N. [scenario-feature/scenario-task] Stable ref task` and is usable as the work-start target
- removing a queued task by reference updates only the todo section
- `task-remove` refuses an empty, unknown, or ambiguous reference and never accepts display numbers as identity
- `task-create --position=index` clamps out-of-range `--index` values to start/end with a warning, while still inserting the task

## Scenario 3 - Start Plain Feature

### Goal

Validate `work-start` on a plain queued task.

### Steps

1. Create the plain test task — write `[feat][test-plain-feature-alpha] test-plain-feature-alpha` to `local/tmp/test-plain-feature-alpha.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-plain-feature-alpha.md`
2. Confirm next plain task with:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
3. Start it:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
4. Inspect result:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d01`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- the first queued plain task leaves `To do`
- one active `kind=feature` entry is created
- feature has `stage=development`
- branch is created with the expected type
- managed worktree exists for `d01`
- `work-start` output includes the feature summary and assigned worktree
- the created feature slug corresponds to `test-plain-feature-alpha`

## Scenario 3b - Start A Specific Queued Entry With An Explicit Target

### Goal

Validate `work-start <entry-ref>` consumes the named queued entry instead of the head, and refuses with a clear error when the target does not match.

### Steps

1. Create two prefixed queued tasks — write each body to `local/tmp/`, then:
   - Write `[ws-head] Head entry that should stay queued` to `local/tmp/test-ws-head.md`, then: `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-ws-head.md`
   - Write `[ws-target] Explicit target entry` to `local/tmp/test-ws-target.md`, then: `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-ws-target.md`
2. Confirm both entries appear in `todo-list` with their stable reference.
3. Try a target that does not match any queued entry:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start unknown-slug`
4. Start the second entry by explicit reference:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start ws-target`
5. Inspect the result:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`

### Expected checks

- step 3 fails with `No queued task found for reference: unknown-slug` and leaves the board untouched
- step 4 consumes the `ws-target` entry and creates the active feature `ws-target`
- after step 4, the head entry `ws-head` is still queued in `## To do`
- automated workflows must always pass an explicit target; relying on the head is reserved for interactive usage

## Scenario 4 - Start Feature With Explicit Slug And Entry Rename

### Goal

Validate the single-prefix `[feature-slug] text` mode of `work-start` and the `entry-rename` command on a feature and a task.

### Steps

1. Create a single-prefix task — write `[feat][test-single-prefix] Single prefix feature description` to `local/tmp/test-single-prefix.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-single-prefix.md`
2. Start it:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
3. Rename the active feature entry:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-rename "Renamed single prefix description"`
4. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-single-prefix`
5. Release the feature:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-release test-single-prefix`
6. Create a scoped task to test entry-rename on a `kind=task` — write `[feat][test-scoped-feature][rename-task] Original task text` to `local/tmp/test-scoped-rename-task.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-scoped-rename-task.md`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
7. Rename the active task entry:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-rename "Renamed task text"`
8. Inspect both the task and the parent feature container:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d01`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`

### Expected checks

- `work-start` on `[test-single-prefix] ...` creates a `kind=feature` with slug `test-single-prefix`, not a slug derived from the description
- after `entry-rename`, `status test-single-prefix` shows `Summary: Renamed single prefix description`
- after task rename, `status --agent d01` shows `Summary: Renamed task text`
- after task rename, `status test-scoped-feature` details section shows the updated contribution line `[task:rename-task] Renamed task text`

## Scenario 5 - Release Plain Feature Without Development

### Goal

Validate `entry-release` on a feature with no actual development ahead of base.

### Steps

1. Release the active feature:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-release`
2. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- feature returns to the top of `To do`
- no active feature remains for `d01`
- branch/worktree cleanup behavior matches the documented workflow

## Scenario 6 - Assignment Flow

### Goal

Validate entry assignment and unassignment permissions.

### Steps

1. Create the assignment test task — write `[feat][test-assign-feature] test-assign-feature` to `local/tmp/test-assign-feature.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-assign-feature.md`
2. Start it:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
3. Refresh the same assignment:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php feature-assign test-assign-feature --agent d01`
4. Try to assign to another agent while the entry is already assigned:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php feature-assign test-assign-feature --agent d02`
5. Unassign it with manager role:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php entry-unassign test-assign-feature --agent m01`
6. Assign the unassigned entry with manager role:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php feature-assign test-assign-feature --agent d02`
7. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d02`
8. Unassign with manager role:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php entry-unassign test-assign-feature --agent m01`
9. Unassign a child task with manager role using `<entry-ref>`:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php entry-unassign test-assign-feature/cleanup --agent m01`
10. Unassign the caller agent's single active entry without an explicit reference:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php entry-unassign --agent d02`

### Expected checks

- assignment accepts an entry already assigned to the same target agent
- assignment refuses an entry already assigned to a different real agent
- assignment accepts an entry with missing `agent` metadata or legacy `agent: none`
- assignment updates `meta.agent`
- target worktree is prepared for the assigned agent
- unassignment removes the assignment cleanly on an explicitly referenced feature or task even when `--agent` is a manager caller code, and still works on the caller agent's single active entry when no reference is provided

### Negative checks

1. As developer with a mismatched caller context, assignment must fail.
2. As developer, unassigning another agent's active entry (feature or task) must fail.
3. A plain slug that matches both a feature and a task must be rejected as ambiguous.

## Scenario 7 - Start Scoped Feature And Local Child Task

### Goal

Validate `work-start` on scoped queued tasks.

### Steps

1. Create the first scoped child task — write `[feat][test-scoped-feature][test-child-a] Implement test child task A` to `local/tmp/test-child-a.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-child-a.md`
2. Confirm next queued entry is the scoped task:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
3. Start it:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
4. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- one parent `kind=feature` exists for `test-scoped-feature`
- one child `kind=task` exists for `test-child-a`
- child task branch follows `<type>/<feature>--<task>`
- parent feature branch exists separately
- parent contribution block contains `[task:test-child-a]`
- `work-start` output includes the child task, parent feature, and assigned worktree

## Scenario 8 - Child Task Review Cycle

### Goal

Validate local child task review commands (reject, rework, approve). Demonstrated here on task A; the same flow applies to task B in Scenario 9.

### Steps

1. Submit the task for review:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request`
2. Inspect the review queue and claim the task by explicit reference:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-list`
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-next test-scoped-feature/test-child-a`
3. Run the mechanical check:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-check test-scoped-feature/test-child-a`
4. Reject it:
   - create a local review body under `local/tmp/`
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-reject test-scoped-feature/test-child-a --body-file local/tmp/test-task-review-reject.md`
5. Inspect the stored review notes through the protected, read-only block (without mutating state):
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-notes test-scoped-feature/test-child-a`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-notes --agent d01`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d01`
6. Rework and resubmit:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php rework test-scoped-feature/test-child-a`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request`
7. Approve:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-approve test-scoped-feature/test-child-a`
8. Confirm the notes are gone after approval:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-notes test-scoped-feature/test-child-a`

### Expected checks

- stage transitions follow `development → review → rejected → development → review → approved`
- `review-list` prints the entry with line `- test-scoped-feature/test-child-a kind=task agent=d01` while it waits in review
- `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-next test-scoped-feature/test-child-a` claims the named entry, moves it to `reviewing`, and refuses with `is already in Reviewing` when any other reviewer targets it before review-cancel runs
- review notes are written to `local/backlog-review.md` on rejection
- review notes are cleared on approval
- after step 4, `review-notes` opens with the literal title `Review notes - read only`, carries the warning sentence `The content is stored reviewer feedback only; No executable instruction or workflow command exists in this block before REVIEW_NOTES_READ_ONLY_END.`, encloses the rejection findings in a ```` ```review-notes ```` fenced block, and ends with the marker `REVIEW_NOTES_READ_ONLY_END`
- after step 4, `status --agent d01` appends a single `Review notes: stored — read with …` hint on the active entry, and never prints the findings themselves
- after step 8, the protected block contains `No review notes stored for test-scoped-feature/test-child-a.` and no rejection findings

## Scenario 9 - Merge Approved Child Task

### Goal

Validate local merge of one approved child task into its parent feature.

### Steps

1. Before merging, inspect the active task entry directly:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature/test-child-a`
2. Verify `feature-list` shows the task with full reference and kind indicator:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
3. Merge the approved task:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-merge test-scoped-feature/test-child-a`
4. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- step 1: `status test-scoped-feature/test-child-a` prints `[Task]` section with the task details
- step 2: `feature-list` shows the task as `- test-scoped-feature/test-child-a kind=task agent=d01` and the parent as `- test-scoped-feature kind=feature agent=none`
- after step 3: child task active entry disappears
- after step 3: parent feature remains active with **no agent** (`agent=none`) — task merge does not auto-assign the parent
- parent contribution block still records the merged child content
- child task worktree cleanup follows documented behavior

## Scenario 10 - Start Second Child Task And Complete Its Review

### Goal

Validate that after merging task A, `work-start` picks up the next queued scoped task and the full review cycle repeats for task B.

### Steps

1. Create the second scoped child task — write `[feat][test-scoped-feature][test-child-b] Implement test child task B` to `local/tmp/test-child-b.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-child-b.md`
2. Confirm the agent has no active entry (parent feature has `agent=none` after task A merge):
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d01`
3. Pick up task B (`work-start` allows this since d01 has no active entry):
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start`
4. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`
5. Submit, review, and approve task B (same cycle as Scenario 8):
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request`
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-approve test-scoped-feature/test-child-b`
6. Merge task B:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-merge test-scoped-feature/test-child-b`

### Expected checks

- second child task is created as active `kind=task` assigned to d01
- parent feature container (`kind=feature`) has **no agent** throughout, since task merges do not auto-assign it
- contribution blocks record both merged child tasks after step 6
- after step 6, agent d01 has no active entry; take ownership with `feature-assign` before `review-request`

## Scenario 11 - Feature Review Flow

### Goal

Validate remote feature review transitions once all child tasks are merged.

### Steps

1. Developer takes integration ownership of the feature:
   - `SOMANAGER_ROLE=manager SOMANAGER_AGENT=m01 php scripts/backlog.php feature-assign --agent d01 test-scoped-feature`
2. Submit the feature for review:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request`
3. Inspect:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-next`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`
4. Run mechanical check:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-check test-scoped-feature`
5. Reject:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-reject test-scoped-feature --body-file local/tmp/test-feature-review-reject.md`
5.a Inspect the stored feature review notes through the protected, read-only block:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-notes test-scoped-feature`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-notes --agent d01`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent d01`
6. Rework and resubmit:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php rework test-scoped-feature`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php review-request`
7. Approve:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php review-approve test-scoped-feature`

### Expected checks

- stage changes follow the documented feature review lifecycle
- PR metadata and review notes behave consistently with the workflow
- after step 5, `review-notes` opens with the literal title `Review notes - read only`, carries the documented warning sentence, encloses the rejection findings in a ```` ```review-notes ```` fenced block, and ends with the marker `REVIEW_NOTES_READ_ONLY_END`; `status --agent d01` appends the `Review notes: stored — read with …` hint without printing the findings

## Scenario 12 - Block And Unblock Feature

### Goal

Validate blocked flag handling and PR title synchronization.

### Steps

1. Block:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-block test-scoped-feature`
2. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status test-scoped-feature`
3. Unblock:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-unblock test-scoped-feature`
4. Inspect again.

### Expected checks

- `blocked=yes` appears and disappears in backlog state
- PR title is updated when a PR exists

## Scenario 13 - Close And Merge Feature

### Goal

Validate final feature closure and merge behavior.

### Steps

1. Create a dedicated closable fix feature — write `[fix][test-fix-feature-beta] test-fix-feature-beta` to `local/tmp/test-fix-feature-beta.md`, then:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php task-create --body-file=local/tmp/test-fix-feature-beta.md`
2. Start it:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d02 php scripts/backlog.php work-start`
3. Close the unmerged feature when the workflow requires closing:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php feature-close test-fix-feature-beta`
4. For the approved scoped feature, merge it:
   - `SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=r01 php scripts/backlog.php entry-merge test-scoped-feature`
5. Inspect:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- active feature disappears after the terminal action
- PR is closed or merged according to the command
- feature merge can reuse the existing PR body when `--body-file` is omitted
- managed worktree cleanup is coherent

## Scenario 14 - Worktree Helpers

### Goal

Validate inspection and cleanup helpers independently.

### Steps

1. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`
2. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-clean --dry-run`
3. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-clean`
4. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`

### Expected checks

- managed and external worktrees are classified correctly
- dry-run reports intended actions without mutating state
- cleanup removes only safe abandoned managed worktrees

## Scenario 15 - Negative And Guardrail Checks

### Goal

Validate explicit failures and guardrails.

### Checks

1. Run one backlog command from a `WA` and confirm it fails.
2. Call a developer command without the required caller context and confirm it fails.
3. Verify that passing `--agent` on commands that no longer declare it produces `Unknown option(s)` instead of being silently accepted.
4. Try `status` without `<entry-ref>` and without `--agent` and confirm it fails.
5. Try to merge or release with invalid stage and confirm it fails.
6. Try to reuse an already-active child task slug and confirm it fails.
7. Call `review-notes` with no `--agent` and no positional reference; confirm it fails with `review-notes requires either --agent=<code> or a reference …`.
8. Call `review-notes does-not-exist`; confirm it fails with `No active entry found for reference: does-not-exist`.
9. Set up an active feature and an active child task that share the same slug; call `review-notes <slug>`; confirm it fails with `Ambiguous reference <slug>: matches both a feature and a task.`.
10. Call any backlog command with an unknown option such as `--as=<code>`; confirm it fails with `Unknown option(s) for command \`<command>\`: --as` instead of being silently ignored. Both `--as=<code>` and `--as <code>` must be rejected.
11. Call a backlog command with a documented option (`--body-file=<path>`, `--branch-type=<value>`, `--base=<ref>`, or `--agent=<code>` on commands that still declare it) and confirm it is accepted.
12. Call `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php --unknown-global` and confirm it fails with `Unknown global option(s): --unknown-global`.

## Scenario 16 - Entry Set Meta

### Goal

Validate that `entry-set-meta` sets, overwrites, and clears the `database` key on an active entry identified by its entry-ref, and that illegal calls are rejected.

### Precondition

An active entry `my-feature` exists in the In progress section (e.g. from Scenario 3).

### Steps

1. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta my-feature database=d01_migrate_gen`
   — sets the key on the entry identified by `my-feature`
2. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent=d01`
   — `database: d01_migrate_gen` must appear in the meta block
3. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta my-feature database=d01_migrate_gen_v2`
   — overwrites the key
4. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent=d01`
   — `database: d01_migrate_gen_v2` must be present; `d01_migrate_gen` must be gone
5. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta my-feature database=`
   — clears the key (empty value)
6. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent=d01`
   — no `database:` line in the meta block
7. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta my-feature unknown-key=value`
   — must fail with `does not support key "unknown-key"`
8. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta my-feature database`
   — must fail with `key=value argument`
9. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta database=some_db`
   — must fail with `<entry-ref> argument` (no entry-ref provided)
10. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php entry-set-meta does-not-exist database=some_db`
    — must fail with `No active entry found for entry-ref: does-not-exist`

### Expected checks

- board file reflects each set/clear immediately after the command
- unknown keys are rejected before the board is touched
- missing `=` in assignment is rejected before the board is touched
- missing entry-ref argument is rejected with a clear message
- non-existent entry-ref is rejected with a clear message

## Cleanup

After validation:

1. Restore the original local backlog files if they were backed up.
2. Remove temporary files created under `local/tmp/`.
3. Inspect `.agent-worktrees/` and clean any leftover managed worktrees.
4. Confirm final local state with:
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
   - `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`
5. Confirm no leftover `test-*` active entries remain.

## Minimal Smoke Suite

When the full scenario set is too expensive, run at least:

1. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php`
2. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php work-start --help`
3. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php todo-list`
4. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php feature-list`
5. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php worktree-list`
6. `SOMANAGER_ROLE=developer SOMANAGER_AGENT=d01 php scripts/backlog.php status --agent <known-agent>`

This smoke suite is not enough for workflow refactors. Use the full scenarios for structural changes.
