<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Backlog CLI help catalog and renderer.
 */
final class BacklogCommandHelp
{
    /**
     * @return array<array{name: string, description: string}>
     */
    public function getCommands(): array
    {
        return [
            ['name' => 'task-create', 'description' => 'Append one new task at the end of the todo section'],
            ['name' => 'task-todo-list', 'description' => 'List queued todo tasks in priority order'],
            ['name' => 'task-remove', 'description' => 'Remove one queued todo task by its displayed number'],
            ['name' => 'task-review-next', 'description' => 'Print the next local task currently waiting in review'],
            ['name' => 'task-review-request', 'description' => 'Submit one local task for reviewer action after a clean mechanical review'],
            ['name' => 'task-review-check', 'description' => 'Run reviewer mechanical checks on a local task'],
            ['name' => 'task-review-reject', 'description' => 'Reject a local task and record reviewer blockers'],
            ['name' => 'task-review-approve', 'description' => 'Approve a local task without changing merge permissions'],
            ['name' => 'task-rework', 'description' => 'Move a rejected local task back to development'],
            ['name' => 'feature-start', 'description' => 'Start a feature branch and move the feature to development'],
            ['name' => 'feature-release', 'description' => 'Return one untouched active feature back to the todo section'],
            ['name' => 'feature-task-add', 'description' => 'Attach the next queued task to the current feature'],
            ['name' => 'feature-task-merge', 'description' => 'Merge one local task branch into its parent feature branch'],
            ['name' => 'feature-assign', 'description' => 'Assign an existing feature to one developer agent'],
            ['name' => 'feature-unassign', 'description' => 'Remove the current agent assignment from one feature'],
            ['name' => 'feature-rework', 'description' => 'Move a rejected feature back to development'],
            ['name' => 'feature-block', 'description' => 'Mark a feature as blocked'],
            ['name' => 'feature-unblock', 'description' => 'Remove the blocked flag from one feature'],
            ['name' => 'feature-list', 'description' => 'List active features grouped by backlog section'],
            ['name' => 'worktree-list', 'description' => 'List managed and external git worktrees with cleanup guidance'],
            ['name' => 'worktree-clean', 'description' => 'Remove abandoned managed worktrees under .worktrees/ when safe'],
            ['name' => 'feature-status', 'description' => 'Print the current status of one feature'],
            ['name' => 'feature-review-next', 'description' => 'Print the next feature currently waiting in review'],
            ['name' => 'feature-review-request', 'description' => 'Request reviewer action after a clean mechanical review'],
            ['name' => 'feature-review-check', 'description' => 'Run reviewer mechanical checks on a feature'],
            ['name' => 'feature-review-reject', 'description' => 'Reject a feature and record reviewer blockers'],
            ['name' => 'feature-review-approve', 'description' => 'Approve a feature and update its PR'],
            ['name' => 'feature-close', 'description' => 'Close one active feature without merging it'],
            ['name' => 'feature-merge', 'description' => 'Merge one approved feature and remove it from the backlog'],
        ];
    }

    /**
     * @param array<array{name: string, description: string}> $executionModeOptions
     * @return array<array{name: string, description: string}>
     */
    public function getOptions(array $executionModeOptions): array
    {
        return array_merge([
            ['name' => '--agent', 'description' => 'Developer agent code (required on developer commands)'],
            ['name' => '--body-file', 'description' => 'Path to a local file used for PR or review body content when required'],
            ['name' => '--branch-type', 'description' => 'Override branch type for feature-start: feat or fix'],
            ['name' => '--feature-text', 'description' => 'Replacement feature text for the active backlog entry'],
            ['name' => '--position', 'description' => 'Insertion position for task-create: start, index, end (default: end)'],
            ['name' => '--index', 'description' => '1-based target position used when --position=index'],
            ['name' => '--force', 'description' => 'Allow taking a task that is already reserved'],
        ], $executionModeOptions);
    }

    /**
     * @return array<string>
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog.php task-create "Add toast notifications on success and error flows"',
            'php scripts/backlog.php task-todo-list',
            'php scripts/backlog.php task-remove 8',
            'php scripts/backlog.php task-review-request --agent agent-01',
            'php scripts/backlog.php task-review-approve onboarding/task-copy-review',
            'php scripts/backlog.php task-rework --agent agent-01 onboarding/task-copy-review',
            'php scripts/backlog.php feature-start --agent agent-01',
            'php scripts/backlog.php feature-release --agent agent-01',
            'php scripts/backlog.php feature-task-merge --agent agent-01',
            'php scripts/backlog.php feature-list',
            'php scripts/backlog.php worktree-list',
            'php scripts/backlog.php worktree-clean --dry-run',
            'php scripts/backlog.php feature-review-next',
            'php scripts/backlog.php feature-review-approve delete-question-reply --body-file local/tmp/pr_body.md',
        ];
    }

    public function renderCommandHelp(string $command): string
    {
        $help = $this->getCommandHelpMap()[$command] ?? null;
        if ($help === null) {
            throw new \RuntimeException("Unknown backlog command: {$command}. Run `php scripts/backlog.php help` for the available commands.");
        }

        $lines = [$command, $help['summary']];

        $arguments = $help['arguments'] ?? [];
        if ($arguments !== []) {
            $lines[] = '';
            $lines[] = 'Arguments:';
            foreach ($arguments as $argument) {
                $lines[] = "  {$argument['name']}";
                $lines[] = "    {$argument['description']}";
            }
        }

        $options = $help['options'] ?? [];
        if ($options !== []) {
            $lines[] = '';
            $lines[] = 'Options:';
            foreach ($options as $option) {
                $lines[] = "  {$option['name']}";
                $lines[] = "    {$option['description']}";
            }
        }

        $lines[] = '';
        $lines[] = 'Examples:';
        foreach ($help['usage'] as $usage) {
            $lines[] = "  {$usage}";
        }

        $notes = $help['notes'] ?? [];
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = 'Notes:';
            foreach ($notes as $note) {
                $lines[] = "  - {$note}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, array{summary: string, usage: array<string>, arguments?: array<array{name: string, description: string}>, options?: array<array{name: string, description: string}>, notes?: array<string>}>
     */
    private function getCommandHelpMap(): array
    {
        return [
            'task-create' => [
                'summary' => 'Create one queued todo task.',
                'usage' => [
                    'php scripts/backlog.php task-create "Add toast notifications on success and error flows"',
                    'php scripts/backlog.php task-create "[feat] Add audit trail view" --position=start',
                    'php scripts/backlog.php task-create "[workspace-worktree][wws-init] Verify gitignore setup" --position=index --index=2',
                ],
                'arguments' => [
                    ['name' => '<text>', 'description' => 'Task text to append to the todo section'],
                ],
                'options' => [
                    ['name' => '--position', 'description' => 'Insert at start, index, or end (default: end)'],
                    ['name' => '--index', 'description' => '1-based insert position used with --position=index'],
                ],
                'notes' => [
                    'Supports optional task type prefixes such as [feat] or [fix].',
                    'Supports optional scoped task prefixes such as [feature-slug][task-slug].',
                ],
            ],
            'task-todo-list' => [
                'summary' => 'List queued todo tasks in display order.',
                'usage' => ['php scripts/backlog.php task-todo-list'],
            ],
            'task-remove' => [
                'summary' => 'Remove one queued todo task by its displayed number.',
                'usage' => ['php scripts/backlog.php task-remove 8'],
                'arguments' => [
                    ['name' => '<number>', 'description' => '1-based number shown by task-todo-list'],
                ],
            ],
            'task-review-next' => [
                'summary' => 'Print the next child task waiting in review.',
                'usage' => ['php scripts/backlog.php task-review-next'],
            ],
            'task-review-request' => [
                'summary' => 'Move one child task to local review after mechanical checks pass.',
                'usage' => [
                    'php scripts/backlog.php task-review-request --agent agent-01',
                    'php scripts/backlog.php task-review-request --agent agent-01 onboarding/task-copy-review',
                ],
                'arguments' => [
                    ['name' => '[<task>|<feature/task>]', 'description' => 'Optional explicit child task identifier'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the task'],
                ],
                'notes' => [
                    'Without an explicit task reference, resolves the single active child task assigned to the provided agent.',
                    'Runs `php scripts/review.php` in the task worktree before moving the task to review.',
                ],
            ],
            'task-review-check' => [
                'summary' => 'Run reviewer mechanical checks on one child task.',
                'usage' => [
                    'php scripts/backlog.php task-review-check onboarding/task-copy-review',
                ],
                'arguments' => [
                    ['name' => '<feature/task>', 'description' => 'Child task identifier to inspect'],
                ],
                'notes' => [
                    'The task must already be in the review stage.',
                    'On mechanical review failure, the task is automatically rejected with generated review notes.',
                ],
            ],
            'task-review-reject' => [
                'summary' => 'Reject one child task and store reviewer notes.',
                'usage' => [
                    'php scripts/backlog.php task-review-reject onboarding/task-copy-review --body-file local/tmp/review.md',
                ],
                'arguments' => [
                    ['name' => '<feature/task>', 'description' => 'Child task identifier to reject'],
                ],
                'options' => [
                    ['name' => '--body-file', 'description' => 'Local file containing the rejection notes'],
                ],
                'notes' => [
                    'The task must already be in the review stage.',
                    'Review notes are stored under `local/backlog-review.md` with the `<feature>/<task>` key.',
                ],
            ],
            'task-review-approve' => [
                'summary' => 'Approve one child task locally.',
                'usage' => [
                    'php scripts/backlog.php task-review-approve onboarding/task-copy-review',
                ],
                'arguments' => [
                    ['name' => '<feature/task>', 'description' => 'Child task identifier to approve'],
                ],
                'notes' => [
                    'The task must already be in the review stage.',
                    'Approval stays local and does not create or update any GitHub PR.',
                ],
            ],
            'task-rework' => [
                'summary' => 'Move one rejected child task back to development.',
                'usage' => [
                    'php scripts/backlog.php task-rework --agent agent-01 onboarding/task-copy-review',
                ],
                'arguments' => [
                    ['name' => '[<task>|<feature/task>]', 'description' => 'Optional explicit child task identifier'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the task'],
                ],
                'notes' => [
                    'Without an explicit task reference, resolves the single rejected child task assigned to the provided agent.',
                    'Reopens the task branch in the agent worktree and preserves the stored review notes.',
                ],
            ],
            'feature-start' => [
                'summary' => 'Start the next queued task as an active feature or child task.',
                'usage' => [
                    'php scripts/backlog.php feature-start --agent agent-01',
                    'php scripts/backlog.php feature-start --agent agent-01 --branch-type=fix',
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code that takes the next task'],
                    ['name' => '--branch-type', 'description' => 'Override branch type with feat or fix'],
                ],
                'notes' => [
                    'Reads the next queued entry from the board `À faire` section.',
                    'Accepts plain task text and derives the feature slug from that text.',
                    'Accepts optional task type prefixes such as [feat] or [fix] to choose the branch type.',
                    'Accepts scoped task entries in the form [feature-slug][task-slug] Task text to start or attach a local child task under an existing parent feature.',
                ],
            ],
            'feature-release' => [
                'summary' => 'Return one untouched active feature back to the todo section.',
                'usage' => [
                    'php scripts/backlog.php feature-release --agent agent-01',
                    'php scripts/backlog.php feature-release delete-question-reply --agent agent-01',
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                ],
                'notes' => [
                    'Without an explicit argument, resolves the current active task or feature owned by the provided agent.',
                    'Only works when the active entry has no development work yet.',
                    'A parent feature cannot be released while local child tasks are still active under it.',
                ],
            ],
            'feature-task-add' => [
                'summary' => 'Attach the next queued task to the current feature.',
                'usage' => [
                    'php scripts/backlog.php feature-task-add --agent agent-01 --feature-text "Workspace worktree"',
                    'php scripts/backlog.php feature-task-add --agent agent-01 --feature-text "Workspace worktree" --body-file local/tmp/pr_body.md',
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                    ['name' => '--feature-text', 'description' => 'Feature text used to resolve the target feature'],
                    ['name' => '--body-file', 'description' => 'Optional local file used to refresh the PR body when a PR already exists'],
                    ['name' => '--force', 'description' => 'Allow taking a task that is already reserved'],
                ],
                'notes' => [
                    'Consumes the next queued entry from the board `À faire` section.',
                    'A plain queued task is appended to the current feature details only when that feature does not already use local child tasks.',
                    'A scoped queued task `[feature-slug][task-slug] Task text` creates a dedicated local child task branch for the current feature.',
                ],
            ],
            'feature-task-merge' => [
                'summary' => 'Merge one approved child task branch into its parent feature branch locally.',
                'usage' => [
                    'php scripts/backlog.php feature-task-merge --agent agent-01',
                    'php scripts/backlog.php feature-task-merge onboarding/task-copy-review',
                ],
                'arguments' => [
                    ['name' => '[<feature/task>]', 'description' => 'Optional explicit child task identifier'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code for the current task merge flow'],
                ],
                'notes' => [
                    'Without an explicit task reference, resolves the single active child task assigned to the provided agent.',
                    'Without `--agent`, an explicit `<feature/task>` reference is required.',
                    'Runs `php scripts/review.php` again before merging and then deletes the merged local task branch.',
                ],
            ],
            'feature-assign' => [
                'summary' => 'Assign one existing feature to a developer agent.',
                'usage' => [
                    'php scripts/backlog.php feature-assign delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to assign'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code to assign'],
                ],
            ],
            'feature-unassign' => [
                'summary' => 'Remove the current agent assignment from one feature.',
                'usage' => [
                    'php scripts/backlog.php feature-unassign --agent agent-01',
                    'php scripts/backlog.php feature-unassign delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Agent code currently assigned to the feature'],
                ],
                'notes' => [
                    'The caller role is controlled by `SOMANAGER_ROLE`.',
                    'With `SOMANAGER_ROLE=developer`, `SOMANAGER_AGENT` must match `--agent` and the developer may only unassign itself.',
                ],
            ],
            'feature-rework' => [
                'summary' => 'Move one rejected feature back to development.',
                'usage' => [
                    'php scripts/backlog.php feature-rework --agent agent-01',
                    'php scripts/backlog.php feature-rework delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                ],
                'notes' => [
                    'Without an explicit feature argument, resolves the single rejected feature assigned to the provided agent.',
                    'Reopens the feature branch in the agent worktree.',
                ],
            ],
            'feature-block' => [
                'summary' => 'Mark one active feature as blocked.',
                'usage' => [
                    'php scripts/backlog.php feature-block --agent agent-01',
                    'php scripts/backlog.php feature-block delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                ],
                'notes' => [
                    'Without an explicit feature argument, resolves the single active feature assigned to the provided agent.',
                    'If the feature already has a PR, the command updates the PR title to include the blocked marker.',
                ],
            ],
            'feature-unblock' => [
                'summary' => 'Remove the blocked flag from one feature.',
                'usage' => [
                    'php scripts/backlog.php feature-unblock --agent agent-01',
                    'php scripts/backlog.php feature-unblock delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                ],
                'notes' => [
                    'Without an explicit feature argument, resolves the single active feature assigned to the provided agent.',
                    'If the feature already has a PR, the command restores the current non-blocked PR title.',
                ],
            ],
            'feature-list' => [
                'summary' => 'List active features grouped by backlog section.',
                'usage' => ['php scripts/backlog.php feature-list'],
            ],
            'worktree-list' => [
                'summary' => 'List managed and external git worktrees with cleanup guidance.',
                'usage' => ['php scripts/backlog.php worktree-list'],
            ],
            'worktree-clean' => [
                'summary' => 'Remove abandoned managed worktrees under .worktrees/ when safe.',
                'usage' => [
                    'php scripts/backlog.php worktree-clean',
                    'php scripts/backlog.php worktree-clean --dry-run --verbose',
                ],
                'options' => [
                    ['name' => '--dry-run', 'description' => 'Preview cleanup without mutating anything'],
                    ['name' => '--verbose', 'description' => 'Print detailed cleanup steps'],
                ],
                'notes' => [
                    'Only managed worktrees under `.worktrees/` are auto-cleaned.',
                    'External worktrees are reported by `worktree-list` and must be cleaned manually.',
                ],
            ],
            'feature-status' => [
                'summary' => 'Print the current status of one feature.',
                'usage' => [
                    'php scripts/backlog.php feature-status delete-question-reply',
                    'php scripts/backlog.php feature-status --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Resolve the active feature owned by this agent'],
                ],
                'notes' => [
                    'With `--agent`, prints the current child task status first when the agent owns an active task.',
                    'The output includes the next workflow action derived from the current stage.',
                ],
            ],
            'feature-review-next' => [
                'summary' => 'Print the next feature currently waiting in review.',
                'usage' => ['php scripts/backlog.php feature-review-next'],
                'notes' => [
                    'Prints the first feature entry currently in the review stage.',
                ],
            ],
            'feature-review-request' => [
                'summary' => 'Request reviewer action for one feature after checks pass.',
                'usage' => [
                    'php scripts/backlog.php feature-review-request --agent agent-01',
                    'php scripts/backlog.php feature-review-request delete-question-reply --agent agent-01',
                ],
                'arguments' => [
                    ['name' => '[<feature>]', 'description' => 'Optional explicit feature slug'],
                ],
                'options' => [
                    ['name' => '--agent', 'description' => 'Developer agent code owning the feature'],
                ],
                'notes' => [
                    'Without an explicit feature argument, resolves the single active feature assigned to the provided agent.',
                    'Runs `php scripts/review.php` in the feature worktree before moving the feature to review.',
                    'Blocked while local child tasks remain active for the feature.',
                ],
            ],
            'feature-review-check' => [
                'summary' => 'Run reviewer mechanical checks on one feature.',
                'usage' => [
                    'php scripts/backlog.php feature-review-check delete-question-reply',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to inspect'],
                ],
                'notes' => [
                    'The feature must already be in the review stage.',
                    'Blocked while local child tasks remain active for the feature.',
                    'On mechanical review failure, the feature is automatically rejected with generated review notes.',
                ],
            ],
            'feature-review-reject' => [
                'summary' => 'Reject one feature and record reviewer blockers.',
                'usage' => [
                    'php scripts/backlog.php feature-review-reject delete-question-reply --body-file local/tmp/review.md',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to reject'],
                ],
                'options' => [
                    ['name' => '--body-file', 'description' => 'Local file containing reviewer notes'],
                ],
                'notes' => [
                    'The feature must already be in the review stage.',
                    'Review notes are stored under `local/backlog-review.md` with the feature slug as key.',
                ],
            ],
            'feature-review-approve' => [
                'summary' => 'Approve one feature and update its PR.',
                'usage' => [
                    'php scripts/backlog.php feature-review-approve delete-question-reply --body-file local/tmp/pr_body.md',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to approve'],
                ],
                'options' => [
                    ['name' => '--body-file', 'description' => 'Local file used for the PR update body'],
                ],
                'notes' => [
                    'The feature must already be in the review stage.',
                    'Blocked while local child tasks remain active for the feature.',
                    'Pushes the branch, creates or updates the PR, then records the PR number in the backlog metadata.',
                ],
            ],
            'feature-close' => [
                'summary' => 'Close one active feature without merging it.',
                'usage' => [
                    'php scripts/backlog.php feature-close delete-question-reply',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to close'],
                ],
                'notes' => [
                    'Blocked while local child tasks remain active for the feature.',
                    'Closes the remote PR when one exists, then removes the feature from the local backlog state.',
                ],
            ],
            'feature-merge' => [
                'summary' => 'Merge one approved feature and remove it from the backlog.',
                'usage' => [
                    'php scripts/backlog.php feature-merge delete-question-reply --body-file local/tmp/pr_body.md',
                ],
                'arguments' => [
                    ['name' => '<feature>', 'description' => 'Feature slug to merge'],
                ],
                'options' => [
                    ['name' => '--body-file', 'description' => 'Local file used for the merge PR body when required'],
                ],
                'notes' => [
                    'The feature must already be approved, unblocked, and have no active local child tasks.',
                    'Requires an open PR for the feature branch.',
                    'After the remote merge, removes the feature from backlog state and deletes the local and remote feature branches.',
                ],
            ],
        ];
    }
}
