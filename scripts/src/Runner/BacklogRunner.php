<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\RetryHelper;
use SoManAgent\Script\TextSlugger;

/**
 * Backlog workflow runner for the local developer/reviewer process.
 */
final class BacklogRunner extends AbstractScriptRunner
{
    private const ROLE_MANAGER = 'manager';
    private const ROLE_DEVELOPER = 'developer';
    private const ENV_ACTIVE_ROLE = 'SOMANAGER_ROLE';
    private const ENV_ACTIVE_AGENT = 'SOMANAGER_AGENT';
    private const WA_BACKEND_ENV_LOCAL_FALLBACK = "DATABASE_URL=\"postgresql://somanagent:secret@localhost:5432/somanagent?serverVersion=16&charset=utf8\"\n";
    private const PR_CREATE_HEAD_INVALID_NEEDLE = 'resource=PullRequest, field=head, code=invalid';
    private const RETRY_COUNT = 3;
    private const RETRY_BASE_DELAY = 500000; // MICROSECONDS
    private const RETRY_FACTOR = 4;
    private const FEATURE_SLUG_MAX_WORDS = 8;
    private const FEATURE_SLUG_MAX_LENGTH = 64;
    private const TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN = '/^\[(feat|fix)\](.*)$/i';
    private const TASK_SCOPE_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\]\[([A-Za-z0-9_-]+)\]\s*(.+)$/';
    private const TASK_CONTRIBUTION_PREFIX_PATTERN = '/^\s*-\s*\[task:([a-z0-9-]+)\]\s*(.+)$/';
    private const TASK_CREATE_POSITION_START = 'start';
    private const TASK_CREATE_POSITION_INDEX = 'index';
    private const TASK_CREATE_POSITION_END = 'end';
    private const NETWORK_ERROR_NEEDLES = [
        'fatal: unable to access',
        'GitHub API transport error:',
        'Could not resolve host:',
        'Connection timed out',
        'Failed to connect',
        'Operation timed out',
        'Temporary failure in name resolution',
    ];

    protected function getDescription(): string
    {
        return 'Backlog workflow helper for local developer and reviewer procedures';
    }

    protected function getCommands(): array
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

    protected function getOptions(): array
    {
        return array_merge([
            ['name' => '--agent', 'description' => 'Developer agent code (required on developer commands)'],
            ['name' => '--body-file', 'description' => 'Path to a local file used for PR or review body content when required'],
            ['name' => '--branch-type', 'description' => 'Override branch type for feature-start: feat or fix'],
            ['name' => '--feature-text', 'description' => 'Replacement feature text for the active backlog entry'],
            ['name' => '--position', 'description' => 'Insertion position for task-create: start, index, end (default: end)'],
            ['name' => '--index', 'description' => '1-based target position used when --position=index'],
            ['name' => '--force', 'description' => 'Allow taking a task that is already reserved'],
        ], $this->getExecutionModeOptions());
    }

    protected function getUsageExamples(): array
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

    /**
     * Executes one backlog workflow command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $command = array_shift($args) ?? '';
        [$commandArgs, $options] = $this->parseArgs($args);
        $this->configureExecutionModes($options);

        if ($command === '') {
            $this->printHelp();

            return 0;
        }

        if ($command === 'help') {
            $targetCommand = $commandArgs[0] ?? '';
            if ($targetCommand === '') {
                $this->printHelp();

                return 0;
            }

            $this->printCommandHelp($targetCommand);

            return 0;
        }

        if (isset($options['help'])) {
            $this->printCommandHelp($command);

            return 0;
        }

        return match ($command) {
            'task-create' => $this->createTask($commandArgs, $options),
            'task-todo-list' => $this->taskTodoList(),
            'task-remove' => $this->taskRemove($commandArgs),
            'task-review-next' => $this->taskReviewNext(),
            'task-review-request' => $this->taskReviewRequest($commandArgs, $options),
            'task-review-check' => $this->taskReviewCheck($commandArgs),
            'task-review-reject' => $this->taskReviewReject($commandArgs, $options),
            'task-review-approve' => $this->taskReviewApprove($commandArgs),
            'task-rework' => $this->taskRework($commandArgs, $options),
            'feature-start' => $this->featureStart($commandArgs, $options),
            'feature-release' => $this->featureRelease($commandArgs, $options),
            'feature-task-add' => $this->featureTaskAdd($commandArgs, $options),
            'feature-task-merge' => $this->featureTaskMerge($commandArgs, $options),
            'feature-assign' => $this->featureAssign($commandArgs, $options),
            'feature-unassign' => $this->featureUnassign($commandArgs, $options),
            'feature-rework' => $this->featureRework($commandArgs, $options),
            'feature-block' => $this->featureBlock($commandArgs, $options),
            'feature-unblock' => $this->featureUnblock($commandArgs, $options),
            'feature-list' => $this->featureList(),
            'worktree-list' => $this->worktreeList(),
            'worktree-clean' => $this->worktreeClean(),
            'feature-status' => $this->featureStatus($commandArgs, $options),
            'feature-review-next' => $this->featureReviewNext(),
            'feature-review-request' => $this->featureReviewRequest($commandArgs, $options),
            'feature-review-check' => $this->featureReviewCheck($commandArgs),
            'feature-review-reject' => $this->featureReviewReject($commandArgs, $options),
            'feature-review-approve' => $this->featureReviewApprove($commandArgs, $options),
            'feature-close' => $this->featureClose($commandArgs),
            'feature-merge' => $this->featureMerge($commandArgs, $options),
            default => throw new \RuntimeException("Unknown backlog command: {$command}. Run `php scripts/backlog.php help` for the available commands."),
        };
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

    private function printCommandHelp(string $command): void
    {
        $help = $this->getCommandHelpMap()[$command] ?? null;
        if ($help === null) {
            throw new \RuntimeException("Unknown backlog command: {$command}. Run `php scripts/backlog.php help` for the available commands.");
        }

        echo $command . "\n";
        echo $help['summary'] . "\n";

        $arguments = $help['arguments'] ?? [];
        if ($arguments !== []) {
            echo "\nArguments:\n";
            foreach ($arguments as $argument) {
                echo "  {$argument['name']}\n";
                echo "    {$argument['description']}\n";
            }
        }

        $options = $help['options'] ?? [];
        if ($options !== []) {
            echo "\nOptions:\n";
            foreach ($options as $option) {
                echo "  {$option['name']}\n";
                echo "    {$option['description']}\n";
            }
        }

        echo "\nExamples:\n";
        foreach ($help['usage'] as $usage) {
            echo "  {$usage}\n";
        }

        $notes = $help['notes'] ?? [];
        if ($notes !== []) {
            echo "\nNotes:\n";
            foreach ($notes as $note) {
                echo "  - {$note}\n";
            }
        }
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function createTask(array $commandArgs, array $options): int
    {
        $text = trim(implode(' ', $commandArgs));
        if ($text === '') {
            throw new \RuntimeException('This command requires a task description.');
        }

        $board = $this->board();
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $position = $this->resolveTaskCreatePosition($options, count($entries));
        array_splice($entries, $position, 0, [$this->createTaskEntryFromInput($text)]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, 'task-create');

        $this->console->ok(sprintf('Added task to the todo section at position %d', $position + 1));

        return 0;
    }

    /**
     * Resolves the 0-based insertion index for task-create from options.
     *
     * @param array<string, string|bool> $options
     */
    private function resolveTaskCreatePosition(array $options, int $entryCount): int
    {
        $position = (string) ($options['position'] ?? self::TASK_CREATE_POSITION_END);
        if (!in_array($position, [
            self::TASK_CREATE_POSITION_START,
            self::TASK_CREATE_POSITION_INDEX,
            self::TASK_CREATE_POSITION_END,
        ], true)) {
            throw new \RuntimeException('task-create --position must be start, index, or end.');
        }

        if ($position === self::TASK_CREATE_POSITION_START) {
            return 0;
        }

        if ($position === self::TASK_CREATE_POSITION_END) {
            return $entryCount;
        }

        $rawIndex = (int) ($options['index'] ?? 0);
        if ($rawIndex <= 0) {
            throw new \RuntimeException('task-create with --position=index requires --index=<positive-number>.');
        }

        $zeroBasedIndex = $rawIndex - 1;
        if ($zeroBasedIndex < 0) {
            return 0;
        }
        if ($zeroBasedIndex > $entryCount) {
            return $entryCount;
        }

        return $zeroBasedIndex;
    }

    private function taskTodoList(): int
    {
        $entries = $this->board()->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            $this->console->line('No queued task.');

            return 0;
        }

        foreach ($entries as $index => $entry) {
            $prefix = sprintf('%d. ', $index + 1);
            $this->console->line($prefix . $entry->getText());
        }

        return 0;
    }

    /**
     * Removes one queued todo task by its 1-based displayed number.
     *
     * @param array<string> $commandArgs
     */
    private function taskRemove(array $commandArgs): int
    {
        $position = (int) ($commandArgs[0] ?? 0);
        if ($position <= 0) {
            throw new \RuntimeException('task-remove requires a positive task number.');
        }

        $board = $this->board();
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $index = $position - 1;

        if (!isset($entries[$index])) {
            throw new \RuntimeException(sprintf('No queued task found at position %d.', $position));
        }

        $removed = $entries[$index];
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
        $this->saveBoard($board, 'task-remove');

        $this->console->ok(sprintf('Removed queued task %d', $position));
        $this->console->info($removed->getText());

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureStart(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $branchTypeOverride = $this->readBranchTypeOverride($options);

        $board = $this->board();

        if ($this->getSingleTaskForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active task.");
        }

        $target = $this->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No backlog task available to start.');
        }
        $reserved = [$target];

        $this->logVerbose(sprintf(
            'feature-start: selected=1 todo-before=%d active-before=%d',
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
        ));

        $worktree = $this->prepareAgentWorktree($agent);
        $first = $reserved[0]['entry'];
        $first->unsetMeta('feature');
        $first->unsetMeta('agent');
        $scopedTask = $this->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            $task = $scopedTask['task'];
            $parent = $this->findParentFeatureEntry($board, $scopedTask['featureGroup']);

            if ($parent === null) {
                $branchType = $this->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
                $featureBranch = $branchType . '/' . $scopedTask['featureGroup'];
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->updateLocalMainBeforeFeatureStart();
                $featureBase = trim($this->captureGitOutput('git rev-parse origin/main'));
                $this->ensureLocalBranchExists($featureBranch, 'origin/main');

                $featureEntry = new BoardEntry($scopedTask['text'], [], [
                    'kind' => 'feature',
                    'stage' => BacklogBoard::STAGE_IN_PROGRESS,
                    'feature' => $scopedTask['featureGroup'],
                    'agent' => $agent,
                    'branch' => $featureBranch,
                    'base' => $featureBase,
                    'pr' => 'none',
                ]);
                $activeEntries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $activeEntries[] = $featureEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);
                $parent = $this->requireParentFeature($board, $scopedTask['featureGroup']);
            } else {
                $branchType = $this->resolveFeatureStartBranchType($first, $parent['entry'], $branchTypeOverride);
                $featureBranch = $parent['entry']->getMeta('branch') ?: ($branchType . '/' . $scopedTask['featureGroup']);
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->invalidateFeatureReviewState($parent['entry']);
            }
            $this->assertTaskSlugAvailableForFeature($board, $parent['entry'], $scopedTask['featureGroup'], $task, 'feature-start');

            $taskBase = trim($this->captureGitOutput(sprintf(
                'git rev-parse %s',
                escapeshellarg($featureBranch),
            )));
            $this->requireLocalBranchExists($featureBranch, 'feature-start');
            $this->checkoutBranchInWorktree($worktree, $branch, true, $featureBranch);

            $taskEntry = new BoardEntry($scopedTask['text'], $first->getExtraLines(), [
                'kind' => 'task',
                'stage' => BacklogBoard::STAGE_IN_PROGRESS,
                'feature' => $scopedTask['featureGroup'],
                'task' => $task,
                'agent' => $agent,
                'branch' => $branch,
                'feature-branch' => $featureBranch,
                'base' => $taskBase,
                'pr' => 'none',
            ]);
            $this->appendTaskContribution($parent['entry'], $taskEntry);
            $featureEntry = $taskEntry;
        } else {
            $branchType = $this->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
            $feature = $this->normalizeFeatureSlug($first->getText());
            $this->updateLocalMainBeforeFeatureStart();
            $base = trim($this->captureGitOutput('git rev-parse origin/main'));
            $branch = $branchType . '/' . $feature;
            $this->checkoutBranchInWorktree($worktree, $branch, true);

            $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines(), [
                'kind' => 'feature',
                'stage' => BacklogBoard::STAGE_IN_PROGRESS,
                'feature' => $feature,
                'agent' => $agent,
                'branch' => $branch,
                'base' => $base,
                'pr' => 'none',
            ]);
        }

        foreach (array_slice($reserved, 1) as $task) {
            $reservedEntry = $task['entry'];
            $reservedEntry->unsetMeta('feature');
            $reservedEntry->unsetMeta('agent');
            $featureEntry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $featureEntry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->removeReservedTasks($board, $reserved);
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);
        $this->logVerbose(sprintf(
            'feature-start: feature=%s todo-after-remove=%d active-after-add=%d active-stage=%s',
            (string) ($featureEntry->getMeta('task') ?? $featureEntry->getMeta('feature')),
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
            (string) $featureEntry->getMeta('stage'),
        ));
        $this->saveBoard($board, 'feature-start');

        $this->console->ok(sprintf(
            'Started %s %s on %s',
            $this->entryKind($featureEntry),
            $featureEntry->getMeta('task') ?? $featureEntry->getMeta('feature') ?? '-',
            $branch,
        ));

        return 0;
    }

    private function createTaskEntryFromInput(string $text): BoardEntry
    {
        $normalizedText = trim($text);
        if (preg_match(self::TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN, $normalizedText, $matches) === 1) {
            $entry = new BoardEntry(trim($matches[2]), [], ['type' => strtolower($matches[1])]);
            $this->validateTaskEntryTypeMetadata($entry, 'task-create');

            return $entry;
        }

        $entry = BoardEntry::fromLines(['- ' . $normalizedText]);
        $this->validateTaskEntryTypeMetadata($entry, 'task-create');

        return $entry;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureRelease(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        if (isset($commandArgs[0]) && trim($commandArgs[0]) !== '') {
            $target = $this->normalizeFeatureSlug($commandArgs[0]);
            $task = $this->getSingleTaskForAgent($board, $agent, false);
            if ($task !== null && ($task->getMeta('task') ?? '') === $target) {
                $current = $this->requireSingleTaskForAgent($board, $agent);
            } else {
                $current = $this->requireSingleFeatureForAgent($board, $agent);
                if (($current['entry']->getMeta('feature') ?? '') !== $target) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s has no active feature or task matching %s.',
                        $agent,
                        $target,
                    ));
                }
            }
        } else {
            $current = $this->findTaskEntriesByAgent($board, $agent)[0] ?? $this->requireSingleFeatureForAgent($board, $agent);
        }
        $entry = $current['entry'];
        $branch = $entry->getMeta('branch') ?? '';
        if ($branch === '') {
            throw new \RuntimeException('Active entry has no branch metadata.');
        }

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException('Active entry must be in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . ' to be released.');
        }
        if (!$this->featureHasNoDevelopment($entry)) {
            throw new \RuntimeException('Active entry already has development work and cannot be released back to todo.');
        }

        if ($this->isTaskEntry($entry)) {
            $feature = $entry->getMeta('feature') ?? '';
            $task = $entry->getMeta('task') ?? '';
            $parent = $this->requireParentFeature($board, $feature);
            $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
            array_unshift($todoEntries, new BoardEntry(
                sprintf('[%s][%s] %s', $feature, $task, $entry->getText()),
                $entry->getExtraLines(),
            ));
            $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
            $this->removeActiveEntryAt($board, $current['index']);
            $hasFeatureContent = $this->removeTaskContribution($parent['entry'], $entry);
            if (!$hasFeatureContent && !$this->featureHasNoDevelopment($parent['entry'])) {
                throw new \RuntimeException("Parent feature {$feature} still has development work and cannot be removed.");
            }
            if (!$hasFeatureContent) {
                $this->removeActiveEntryAt($board, $parent['index']);
                if ($this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . ($parent['entry']->getMeta('branch') ?? ''))))) {
                    $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($parent['entry']->getMeta('branch') ?? '')));
                }
            }
            $this->saveBoard($board, 'feature-release');
            $cleaned = $this->cleanupManagedWorktreesForBranch($branch, $board);
            if ($this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)))) {
                $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($branch)));
            }

            $this->console->ok(sprintf('Released task %s back to todo', $task));
            if ($cleaned > 0) {
                $this->console->line(sprintf('Cleaned %d managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
            }

            return 0;
        }

        $feature = $entry->getMeta('feature') ?? '';
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-release');
        $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
        array_unshift($todoEntries, new BoardEntry($entry->getText(), $entry->getExtraLines()));
        $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
        $board->removeFeature($feature);
        $this->saveBoard($board, 'feature-release');

        $cleaned = $this->cleanupManagedWorktreesForBranch($branch, $board);
        if ($this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)))) {
            $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($branch)));
        }

        $this->console->ok(sprintf('Released feature %s back to todo', $feature));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureTaskMerge(array $commandArgs, array $options): int
    {
        $board = $this->board();
        $review = $this->reviewFile();
        $agent = trim((string) ($options['agent'] ?? ''));
        if ($agent !== '') {
            $match = isset($commandArgs[0])
                ? $this->requireTaskByReference($board, $commandArgs[0], 'feature-task-merge')
                : $this->requireSingleTaskForAgent($board, $agent);
        } else {
            if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
                throw new \RuntimeException('feature-task-merge requires <feature/task> when used without --agent.');
            }

            $match = $this->requireTaskByReference($board, $commandArgs[0], 'feature-task-merge');
        }
        if ($match === null) {
            throw new \RuntimeException('No task available for feature-task-merge.');
        }

        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'feature-task-merge');
        if ($agent !== '' && ($entry->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException('feature-task-merge requires the task to be assigned to the provided agent.');
        }
        $taskAgent = $entry->getMeta('agent') ?? '';

        $feature = $entry->getMeta('feature') ?? '';
        $task = $entry->getMeta('task') ?? '';
        $featureBranch = $entry->getMeta('feature-branch') ?? '';
        $taskBranch = $entry->getMeta('branch') ?? '';
        $parent = $this->requireParentFeature($board, $feature);
        $taskWorktree = $this->prepareFeatureAgentWorktree($entry);
        $this->runReviewScript($taskWorktree);
        $this->ensureBranchHasNoDirtyManagedWorktree($taskBranch);
        $mergeContext = $this->prepareFeatureMergeWorktree($featureBranch, $feature);

        try {
            $this->runGitCommand($this->gitInPath(
                $mergeContext['path'],
                sprintf(
                    'merge --no-ff %s -m %s',
                    escapeshellarg($taskBranch),
                    escapeshellarg(sprintf('Merge task %s into feature %s', $task, $feature)),
                ),
            ));
        } catch (\Throwable $exception) {
            if ($mergeContext['temporary']) {
                $this->removeTemporaryMergeWorktree($mergeContext['path']);
            }

            throw $exception;
        }

        $this->removeActiveEntryAt($board, $match['index']);
        if (($parent['entry']->getMeta('agent') ?? '') === '') {
            $parent['entry']->setMeta('agent', $taskAgent);
        }
        $this->invalidateFeatureReviewState($parent['entry']);
        $review->clearReview($this->taskReviewKey($entry));
        $this->saveBoard($board, 'feature-task-merge');
        $this->saveReviewFile($review, 'feature-task-merge');

        if ($mergeContext['temporary']) {
            $this->removeTemporaryMergeWorktree($mergeContext['path']);
        }

        $this->cleanupMergedTaskWorktree($taskAgent, $taskBranch, $board);

        if ($this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $taskBranch)))) {
            $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($taskBranch)));
        }

        $this->console->ok(sprintf('Merged task %s into feature %s locally', $task, $feature));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function taskReviewRequest(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = isset($commandArgs[0])
            ? $this->requireTaskByReference($board, $commandArgs[0], 'task-review-request')
            : $this->requireSingleTaskForAgent($board, $agent);
        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'task-review-request');
        if (($entry->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException('task-review-request requires the task to be assigned to the provided agent.');
        }

        $taskWorktree = $this->prepareFeatureAgentWorktree($entry);
        $this->runReviewScript($taskWorktree);

        $entry->setMeta('stage', BacklogBoard::STAGE_IN_REVIEW);
        $review->clearReview($this->taskReviewKey($entry));
        $this->saveBoard($board, 'task-review-request');
        $this->saveReviewFile($review, 'task-review-request');

        $this->console->ok(sprintf(
            'Task %s moved to %s',
            $this->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));

        return 0;
    }

    private function taskReviewNext(): int
    {
        $board = $this->board();
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if (!$this->isTaskEntry($entry) || $this->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $this->printEntryStatus($entry);

            return 0;
        }

        throw new \RuntimeException('No task available in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }

    /**
     * @param array<string> $commandArgs
     */
    private function taskReviewCheck(array $commandArgs): int
    {
        $board = $this->board();
        $match = $this->requireTaskByReferenceArgument($board, $commandArgs, 'task-review-check');
        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'task-review-check');

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be checked.',
                $this->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->prepareFeatureAgentWorktree($entry);

        try {
            $this->runReviewScript($reviewWorktree);
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the task again.';
            $this->taskReviewReject([$this->taskReviewKey($entry)], ['body-file' => $this->writeTempContent([$message])], true);
            throw $exception;
        }

        $this->console->ok(sprintf('Mechanical review passed for task %s', $this->taskReviewKey($entry)));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function taskReviewReject(array $commandArgs, array $options, bool $auto = false): int
    {
        $bodyFile = $this->requireBodyFile($options);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireTaskByReferenceArgument($board, $commandArgs, 'task-review-reject');
        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'task-review-reject');

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be rejected.',
                $this->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setMeta('stage', BacklogBoard::STAGE_REJECTED);
        $review->setReview($this->taskReviewKey($entry), $this->numberedReviewItems($bodyFile));
        $this->saveBoard($board, 'task-review-reject');
        $this->saveReviewFile($review, 'task-review-reject');

        $this->console->ok(sprintf(
            '%stask %s moved to %s',
            $auto ? 'Automatically rejected ' : 'Rejected ',
            $this->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_REJECTED),
        ));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     */
    private function taskReviewApprove(array $commandArgs): int
    {
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireTaskByReferenceArgument($board, $commandArgs, 'task-review-approve');
        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'task-review-approve');

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be approved.',
                $this->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setMeta('stage', BacklogBoard::STAGE_APPROVED);
        $review->clearReview($this->taskReviewKey($entry));
        $this->saveBoard($board, 'task-review-approve');
        $this->saveReviewFile($review, 'task-review-approve');

        $this->console->ok(sprintf('Approved task %s', $this->taskReviewKey($entry)));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function taskRework(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $match = isset($commandArgs[0])
            ? $this->requireTaskByReference($board, $commandArgs[0], 'task-rework')
            : $this->requireSingleTaskForAgent($board, $agent);
        $entry = $match['entry'];
        $this->assertTaskEntry($entry, 'task-rework');

        if (($entry->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException('task-rework requires the task to be assigned to the provided agent.');
        }

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException(sprintf(
                'Task %s is not in the rejected stage.',
                $this->taskReviewKey($entry),
            ));
        }

        $entry->setMeta('stage', BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, 'task-rework');

        $taskWorktree = $this->prepareFeatureAgentWorktree($entry);
        $this->checkoutBranchInWorktree($taskWorktree, $entry->getMeta('branch') ?? '', false);

        $this->console->ok(sprintf(
            'Moved task %s back to %s',
            $this->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS),
        ));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureTaskAdd(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $featureText = trim((string) ($options['feature-text'] ?? ''));
        if ($featureText === '') {
            throw new \RuntimeException('feature-task-add requires --feature-text.');
        }

        $board = $this->board();
        $current = $this->requireSingleFeatureForAgent($board, $agent);
        $feature = $current['entry']->getMeta('feature');
        $target = $this->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No queued task available to add to the current feature.');
        }
        $reserved = [$target];

        $entry = $current['entry'];
        $this->assertFeatureEntry($entry, 'feature-task-add');
        $entry->setText($featureText);
        $this->invalidateFeatureReviewState($entry);

        foreach ($reserved as $task) {
            $reservedEntry = $task['entry'];
            $reservedEntry->unsetMeta('feature');
            $reservedEntry->unsetMeta('agent');
            $scopedTask = $this->extractScopedTaskMetadata($reservedEntry->getText());

            if ($scopedTask !== null) {
                if ($scopedTask['featureGroup'] !== $feature) {
                    throw new \RuntimeException(sprintf(
                        'Next queued task belongs to feature %s, not %s.',
                        $scopedTask['featureGroup'],
                        $feature,
                    ));
                }
                if ($this->getSingleTaskForAgent($board, $agent, false) !== null) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s already owns an active task. Merge or release it before feature-task-add.',
                        $agent,
                    ));
                }

                $featureBranch = $entry->getMeta('branch') ?? '';
                $branchType = $this->detectBranchType($featureBranch);
                if ($featureBranch === '' || $branchType === '') {
                    throw new \RuntimeException('Current feature metadata is incomplete: missing branch information.');
                }
                $this->assertTaskSlugAvailableForFeature($board, $entry, (string) $feature, $scopedTask['task'], 'feature-task-add');

                $taskBranch = $branchType . '/' . $feature . '--' . $scopedTask['task'];
                $taskBase = trim($this->captureGitOutput(sprintf(
                    'git rev-parse %s',
                    escapeshellarg($featureBranch),
                )));

                $worktree = $this->prepareAgentWorktree($agent);
                $this->checkoutBranchInWorktree($worktree, $taskBranch, true, $featureBranch);

                $taskEntry = new BoardEntry($scopedTask['text'], $reservedEntry->getExtraLines(), [
                    'kind' => 'task',
                    'stage' => BacklogBoard::STAGE_IN_PROGRESS,
                    'feature' => $feature,
                    'task' => $scopedTask['task'],
                    'agent' => $agent,
                    'branch' => $taskBranch,
                    'feature-branch' => $featureBranch,
                    'base' => $taskBase,
                    'pr' => 'none',
                ]);
                $this->appendTaskContribution($entry, $taskEntry);

                $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $entries[] = $taskEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);

                continue;
            }

            if ($this->featureContributionBlocks($entry) !== [] || $this->findTaskEntriesByFeature($board, (string) $feature) !== []) {
                throw new \RuntimeException(sprintf(
                    'Current feature %s already uses local child tasks. The next queued task must use [%s][task] to be attached safely.',
                    $feature,
                    $feature,
                ));
            }

            $entry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $entry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->removeReservedTasks($board, $reserved);

        $this->saveBoard($board, 'feature-task-add');
        $bodyFile = isset($options['body-file'])
            ? $this->requireBodyFile($options)
            : null;
        if ($bodyFile !== null) {
            $this->updatePrBodyIfExists($entry->getMeta('branch') ?? '', $bodyFile);
        }

        $this->console->ok(sprintf('Added queued task to feature %s', $feature));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureAssign(array $commandArgs, array $options): int
    {
        $actorRole = $this->requireWorkflowRole();
        $agent = $this->requireAgent($options);
        $feature = $this->requireFeatureArgument($commandArgs);
        $board = $this->board();
        $actorAgent = $actorRole === self::ROLE_DEVELOPER ? $this->requireWorkflowAgent() : null;

        $this->assertCanAssignFeature($actorRole, $actorAgent, $agent, $feature, $board);

        if ($this->getSingleFeatureForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active feature.");
        }

        $match = $this->requireFeature($board, $feature);
        $previousAgent = trim((string) ($match['entry']->getMeta('agent') ?? ''));
        $match['entry']->setMeta('agent', $agent);
        $this->saveBoard($board, 'feature-assign');

        $worktree = $this->prepareAgentWorktree($agent);
        $this->checkoutBranchInWorktree($worktree, $match['entry']->getMeta('branch') ?? '', false);
        $cleaned = $previousAgent !== '' && $previousAgent !== $agent
            ? $this->cleanupAbandonedManagedWorktrees($board)
            : 0;

        $this->console->ok(sprintf('Assigned feature %s to %s', $feature, $agent));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureUnassign(array $commandArgs, array $options): int
    {
        $actorRole = $this->requireWorkflowRole();
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');
        $actorAgent = $actorRole === self::ROLE_DEVELOPER ? $this->requireWorkflowAgent() : null;

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unassign.');
        }

        $match = $this->requireFeature($board, $feature);
        $this->assertCanUnassignFeature($actorRole, $actorAgent, $agent, $feature, $match['entry']);
        if (($match['entry']->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match['entry']->unsetMeta('agent');
        $this->saveBoard($board, 'feature-unassign');
        $cleaned = $this->cleanupAbandonedManagedWorktrees($board);

        $this->console->ok(sprintf('Unassigned feature %s from %s', $feature, $agent));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }

        return 0;
    }

    private function requireWorkflowRole(): string
    {
        $role = strtolower(trim((string) getenv(self::ENV_ACTIVE_ROLE)));
        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_DEVELOPER], true)) {
            throw new \RuntimeException(sprintf(
                'Assignment commands require %s=manager or %s=developer.',
                self::ENV_ACTIVE_ROLE,
                self::ENV_ACTIVE_ROLE,
            ));
        }

        return $role;
    }

    private function requireWorkflowAgent(): string
    {
        $agent = trim((string) getenv(self::ENV_ACTIVE_AGENT));
        if ($agent === '') {
            throw new \RuntimeException(sprintf(
                'Developer assignment commands require %s=<code>.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        return $agent;
    }

    private function assertCanAssignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BacklogBoard $board,
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only assign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $match = $this->requireFeature($board, $feature);
        $assignedAgent = trim((string) ($match['entry']->getMeta('agent') ?? ''));
        if ($assignedAgent !== '' && $assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is already assigned to %s. Only manager can reassign it.',
                $feature,
                $assignedAgent,
            ));
        }
    }

    private function assertCanUnassignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BoardEntry $entry,
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only unassign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $assignedAgent = trim((string) ($entry->getMeta('agent') ?? ''));
        if ($assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is assigned to %s. Developer role can only unassign its own feature.',
                $feature,
                $assignedAgent === '' ? 'no agent' : $assignedAgent,
            ));
        }
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureRework(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-rework.');
        }

        $match = $this->requireFeature($board, $feature);
        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException("Feature {$feature} is not in the rejected stage.");
        }

        $match['entry']->setMeta('agent', $agent);
        $match['entry']->setMeta('stage', BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, 'feature-rework');

        $worktree = $this->prepareAgentWorktree($agent);
        $this->checkoutBranchInWorktree($worktree, $match['entry']->getMeta('branch') ?? '', false);

        $this->console->ok(sprintf('Moved feature %s back to %s', $feature, BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS)));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureBlock(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-block.');
        }

        $match = $this->requireFeature($board, $feature);
        $match['entry']->setMeta('blocked', 'yes');
        $this->saveBoard($board, 'feature-block');

        $prNumber = $this->storedPrNumber($match['entry']);
        if ($prNumber !== null) {
            $type = $this->featureStage($match['entry']) === BacklogBoard::STAGE_APPROVED ? $this->determinePrType($match['entry']) : 'WIP';
            $title = $this->ensureBlockedTitle($this->buildPrTitle($type, $match['entry']));
            $this->runGithubCommand(sprintf(
                'php scripts/github.php pr edit %d --title %s',
                $prNumber,
                escapeshellarg($title),
            ));
        }

        $this->console->ok(sprintf('Marked feature %s as blocked', $feature));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureUnblock(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unblock.');
        }

        $match = $this->requireFeature($board, $feature);
        if (($match['entry']->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match['entry']->unsetMeta('blocked');
        $this->saveBoard($board, 'feature-unblock');

        $prNumber = $this->storedPrNumber($match['entry']);
        if ($prNumber !== null) {
            $title = $this->buildCurrentTitle($match['entry']);
            $this->runGithubCommand(sprintf(
                'php scripts/github.php pr edit %d --title %s',
                $prNumber,
                escapeshellarg($title),
            ));
        }

        $this->console->ok(sprintf('Removed blocked flag from feature %s', $feature));

        return 0;
    }

    private function featureList(): int
    {
        $board = $this->board();
        $printed = false;
        foreach (BacklogBoard::activeStages() as $stage) {
            $entries = array_values(array_filter(
                $board->getEntries(BacklogBoard::SECTION_ACTIVE),
                fn(BoardEntry $entry): bool => $this->featureStage($entry) === $stage
            ));
            if ($entries === []) {
                continue;
            }

            $printed = true;
            $this->console->line('[' . BacklogBoard::stageLabel($stage) . ']');
            foreach ($entries as $entry) {
                $parts = [
                    'kind=' . $this->entryKind($entry),
                    $entry->getMeta('feature') ?? '-',
                    'branch=' . ($entry->getMeta('branch') ?? '-'),
                    'agent=' . ($entry->getMeta('agent') ?? '-'),
                ];
                if ($this->isTaskEntry($entry)) {
                    $parts[] = 'task=' . ($entry->getMeta('task') ?? '-');
                    $parts[] = 'feature-branch=' . ($entry->getMeta('feature-branch') ?? '-');
                }
                if ($entry->hasMeta('blocked')) {
                    $parts[] = 'blocked=yes';
                }
                $this->console->line('- ' . implode(' ', $parts));
            }
        }

        if (!$printed) {
            $this->console->line('No active feature.');
        }

        return 0;
    }

    private function worktreeList(): int
    {
        $board = $this->board();
        ['managed' => $managed, 'external' => $external] = $this->classifyWorktrees($board);

        if ($managed === [] && $external === []) {
            $this->console->line('No worktree to report.');

            return 0;
        }

        if ($managed !== []) {
            $this->console->line('[Managed worktrees]');
            foreach ($managed as $item) {
                $parts = [
                    $this->toRelativeProjectPath($item['path']),
                    'state=' . $item['state'],
                    'branch=' . ($item['branch'] ?? '-'),
                    'feature=' . ($item['feature'] ?? '-'),
                    'agent=' . ($item['agent'] ?? '-'),
                    'action=' . $item['action'],
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
        }

        if ($external !== []) {
            $this->console->line('[External worktrees]');
            foreach ($external as $item) {
                $parts = [
                    $item['path'],
                    'branch=' . ($item['branch'] ?? '-'),
                    'action=' . $item['action'],
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
            $this->console->line('Manual cleanup: verify each external worktree is disposable, then use `git worktree remove <path>` or `git worktree prune` when only metadata remains.');
        }

        return 0;
    }

    private function worktreeClean(): int
    {
        $board = $this->board();
        $cleaned = $this->cleanupAbandonedManagedWorktrees($board);

        if ($cleaned === 0) {
            $this->console->line('No abandoned managed worktree to clean.');

            return 0;
        }

        $this->console->ok(sprintf(
            '%s %d abandoned managed worktree%s',
            $this->dryRun ? 'Would clean' : 'Cleaned',
            $cleaned,
            $cleaned > 1 ? 's' : '',
        ));

        ['managed' => $managed] = $this->classifyWorktrees($board);
        $skipped = count($managed);
        if ($skipped > 0) {
            $this->console->line(sprintf('Skipped %d managed worktree%s that require manual attention.', $skipped, $skipped > 1 ? 's' : ''));
        }

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureStatus(array $commandArgs, array $options): int
    {
        $board = $this->board();
        $feature = isset($commandArgs[0]) ? $this->normalizeFeatureSlug($commandArgs[0]) : null;

        if ($feature === null) {
            $agent = (string) ($options['agent'] ?? '');
            if ($agent === '') {
                throw new \RuntimeException('feature-status requires either <feature> or --agent.');
            }
            $task = $this->getSingleTaskForAgent($board, $agent, false);
            if ($task !== null) {
                $this->printEntryStatus($task);

                return 0;
            }
            $feature = $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');
        }

        if ($feature === null) {
            throw new \RuntimeException('Unable to resolve target feature for feature-status.');
        }

        $match = $this->requireFeature($board, $feature);
        $this->printEntryStatus($match['entry']);

        return 0;
    }

    private function featureReviewNext(): int
    {
        $board = $this->board();
        $entries = array_map(
            static fn(array $match): BoardEntry => $match['entry'],
            $board->findFeaturesByStage(BacklogBoard::STAGE_IN_REVIEW),
        );
        if ($entries === []) {
            throw new \RuntimeException('No feature available in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
        }

        $entry = $entries[0];
        $feature = $entry->getMeta('feature') ?? null;
        if ($feature === null || $feature === '') {
            throw new \RuntimeException('Next review feature has no feature metadata.');
        }

        $this->printEntryStatus($entry);

        return 0;
    }

    private function printEntryStatus(BoardEntry $entry): void
    {
        $stage = $this->featureStage($entry);
        $this->console->line('Kind: ' . $this->entryKind($entry));
        if ($this->isTaskEntry($entry)) {
            $this->console->line('Feature: ' . ($entry->getMeta('feature') ?? '-'));
            $this->console->line('Task: ' . ($entry->getMeta('task') ?? '-'));
            $this->console->line('Ref: ' . $this->taskReviewKey($entry));
            $this->console->line('Feature Branch: ' . ($entry->getMeta('feature-branch') ?? '-'));
        } else {
            $this->console->line('Feature: ' . ($entry->getMeta('feature') ?? '-'));
        }
        $this->console->line('Branch: ' . ($entry->getMeta('branch') ?? '-'));
        $this->console->line('Base: ' . ($entry->getMeta('base') ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
        $this->console->line('Next: ' . $this->nextStepForEntry($entry, $stage));
        $this->console->line('Blocker: ' . ($entry->hasMeta('blocked') ? 'blocked' : '-'));
    }

    private function printEntryStatusDetails(BoardEntry $entry): void
    {
        $extraLines = $entry->getExtraLines();
        if ($extraLines === []) {
            return;
        }

        $this->console->line('Details:');
        foreach ($extraLines as $line) {
            $this->console->line($line);
        }
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureReviewRequest(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-review-request.');
        }

        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-review-request');
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-review-request');
        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.');
        }
        if (($match['entry']->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $worktree = $this->prepareFeatureAgentWorktree($match['entry']);
        $this->runReviewScript($worktree);

        $match['entry']->setMeta('stage', BacklogBoard::STAGE_IN_REVIEW);
        $this->saveBoard($board, 'feature-review-request');

        $this->console->ok(sprintf('Feature %s moved to %s', $feature, BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW)));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     */
    private function featureReviewCheck(array $commandArgs): int
    {
        $feature = $this->requireFeatureArgument($commandArgs);
        $board = $this->board();
        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-review-check');
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-review-check');
        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be checked.');
        }

        $reviewWorktree = $this->prepareFeatureAgentWorktree($match['entry']);

        try {
            $this->runReviewScript($reviewWorktree);
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before requesting review again.';
            $this->featureReviewReject([$feature], ['body-file' => $this->writeTempContent([$message])], true);
            throw $exception;
        }

        $this->console->ok(sprintf('Mechanical review passed for feature %s', $feature));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureReviewReject(array $commandArgs, array $options, bool $auto = false): int
    {
        $feature = $this->requireFeatureArgument($commandArgs);
        $bodyFile = $this->requireBodyFile($options);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-review-reject');

        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be rejected.');
        }

        $match['entry']->setMeta('stage', BacklogBoard::STAGE_REJECTED);
        $review->setReview($feature, $this->numberedReviewItems($bodyFile));
        $this->saveBoard($board, 'feature-review-reject');
        $this->saveReviewFile($review, 'feature-review-reject');

        $this->console->ok(sprintf(
            '%sfeature %s moved to %s',
            $auto ? 'Automatically rejected ' : 'Rejected ',
            $feature,
            BacklogBoard::stageLabel(BacklogBoard::STAGE_REJECTED),
        ));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureReviewApprove(array $commandArgs, array $options): int
    {
        $feature = $this->requireFeatureArgument($commandArgs);
        $bodyFile = $this->requireBodyFile($options);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-review-approve');
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-review-approve');

        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be approved.');
        }

        $type = $this->determinePrType($match['entry']);
        $title = $this->buildPrTitle($type, $match['entry']);
        $branch = $match['entry']->getMeta('branch') ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $this->pushBranchAndWaitForRemoteVisibility($branch);
        $this->createOrUpdatePr($branch, $title, $bodyFile);
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber !== null) {
            $match['entry']->setMeta('pr', (string) $prNumber);
        }

        $match['entry']->setMeta('stage', BacklogBoard::STAGE_APPROVED);
        $review->clearReview($feature);
        $this->saveBoard($board, 'feature-review-approve');
        $this->saveReviewFile($review, 'feature-review-approve');

        $this->console->ok(sprintf('Approved feature %s with [%s] PR title', $feature, $type));

        return 0;
    }

    /**
     * Closes one active feature without merging it and removes its local backlog state.
     *
     * @param array<string> $commandArgs
     */
    private function featureClose(array $commandArgs): int
    {
        $feature = $this->requireFeatureArgument($commandArgs);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-close');
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-close');

        $branch = $match['entry']->getMeta('branch') ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $this->ensureBranchHasNoDirtyManagedWorktree($branch);
        $this->pushBranchIfAhead($branch);

        $prNumber = $this->storedPrNumber($match['entry']);
        if ($prNumber !== null) {
            $this->runGithubCommand(sprintf('php scripts/github.php pr close %d', $prNumber));
        }

        $board->removeFeature($feature);
        $board->clearReservations($match['entry']->getMeta('agent') ?? '', $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, 'feature-close');
        $this->saveReviewFile($review, 'feature-close');
        $cleaned = $this->cleanupAbandonedManagedWorktrees($board);

        $this->console->ok(sprintf('Closed feature %s without merge', $feature));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureMerge(array $commandArgs, array $options): int
    {
        $feature = $this->requireFeatureArgument($commandArgs);
        $bodyFile = $this->requireBodyFile($options);
        $board = $this->board();
        $review = $this->reviewFile();
        $match = $this->requireFeature($board, $feature);
        $this->assertFeatureEntry($match['entry'], 'feature-merge');
        $this->assertNoActiveTasksForFeature($board, $feature, 'feature-merge');

        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_APPROVED) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_APPROVED) . ' before merge.');
        }
        if ($match['entry']->hasMeta('blocked')) {
            throw new \RuntimeException("Feature {$feature} is blocked and cannot be merged.");
        }

        $branch = $match['entry']->getMeta('branch') ?? '';
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $type = $this->determinePrType($match['entry']);
        $this->createOrUpdatePr($branch, $this->buildPrTitle($type, $match['entry']), $bodyFile);
        $this->runGithubCommand(sprintf('php scripts/github.php pr merge %d', $prNumber));
        $skippedMainCheckout = false;
        if ($this->workspaceCurrentBranch() === 'main') {
            $this->updateLocalMainInWorkspaceWithWarning('feature-merge');
            $skippedMainCheckout = true;
        } elseif ($this->workspaceHasLocalChanges()) {
            $this->runNetworkCommand('git fetch origin main:main', 'Git');
            $skippedMainCheckout = true;
        } else {
            $this->runGitCommand('git checkout main');
            $this->runGitCommand('git pull');
        }

        $board->removeFeature($feature);
        $board->clearReservations($match['entry']->getMeta('agent') ?? '', $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, 'feature-merge');
        $this->saveReviewFile($review, 'feature-merge');
        $cleaned = $this->cleanupManagedWorktreesForBranch($branch, $board);
        $cleaned += $this->cleanupAbandonedManagedWorktrees($board);

        $this->runGitCommand(sprintf('git push origin --delete %s', escapeshellarg($branch)));
        $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($branch)));

        $this->console->ok(sprintf('Merged feature %s', $feature));
        if ($skippedMainCheckout) {
            $this->console->line('Main was handled without checkout in WP.');
        }
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }

        return 0;
    }

    /**
     * @param array<string> $args
     * @return array{0: array<string>, 1: array<string, string|bool>}
     */
    private function parseArgs(array $args): array
    {
        $commandArgs = [];
        $options = [];

        while ($args !== []) {
            $arg = array_shift($args);

            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $value] = explode('=', $option, 2);
                    $options[$key] = $value;
                    continue;
                }

                $next = $args[0] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $options[$option] = array_shift($args);
                } else {
                    $options[$option] = true;
                }
                continue;
            }

            $commandArgs[] = $arg;
        }

        return [$commandArgs, $options];
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function requireAgent(array $options): string
    {
        $agent = trim((string) ($options['agent'] ?? ''));
        if ($agent === '') {
            throw new \RuntimeException('This command requires --agent=<code>.');
        }

        return $agent;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function readBranchTypeOverride(array $options): string
    {
        $branchType = trim((string) ($options['branch-type'] ?? ''));
        if ($branchType === '') {
            return '';
        }
        if (!in_array($branchType, ['feat', 'fix'], true)) {
            throw new \RuntimeException('feature-start --branch-type must be feat or fix.');
        }

        return $branchType;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function requireBodyFile(array $options): string
    {
        $bodyFile = trim((string) ($options['body-file'] ?? ''));
        if ($bodyFile === '') {
            throw new \RuntimeException('This command requires --body-file=<path>.');
        }
        if (!is_file($bodyFile)) {
            throw new \RuntimeException("Body file not found: {$bodyFile}");
        }

        return $bodyFile;
    }

    /**
     * @param array<string> $commandArgs
     */
    private function requireFeatureArgument(array $commandArgs): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException('This command requires <feature>.');
        }

        return $this->normalizeFeatureSlug($commandArgs[0]);
    }

    private function board(): BacklogBoard
    {
        return new BacklogBoard($this->projectRoot . '/local/backlog-board.md');
    }

    private function reviewFile(): BacklogReviewFile
    {
        return new BacklogReviewFile($this->projectRoot . '/local/backlog-review.md');
    }

    private function nextTaskText(BacklogBoard $board): string
    {
        $target = $board->findNextBookableTask(false);
        if ($target === null) {
            throw new \RuntimeException('No non-reserved task available in the todo section.');
        }

        return $target['entry']->getText();
    }

    private function normalizeFeatureSlug(string $text): string
    {
        return $this->featureSlugger()->slugify($text);
    }

    private function entryKind(BoardEntry $entry): string
    {
        $kind = trim((string) $entry->getMeta('kind'));
        if ($kind !== '') {
            return $kind;
        }

        return $entry->hasMeta('task') ? 'task' : 'feature';
    }

    private function isFeatureEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === 'feature';
    }

    private function isTaskEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === 'task';
    }

    /**
     * @return array{featureGroup: string, task: string, text: string}|null
     */
    private function extractScopedTaskMetadata(string $text): ?array
    {
        if (preg_match(self::TASK_SCOPE_PREFIX_PATTERN, trim($text), $matches) !== 1) {
            return null;
        }

        return [
            'featureGroup' => $this->normalizeFeatureSlug($matches[1]),
            'task' => $this->normalizeFeatureSlug($matches[2]),
            'text' => trim($matches[3]),
        ];
    }

    private function featureStage(BoardEntry $entry): string
    {
        return BacklogBoard::entryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS;
    }

    private function taskDeclaredBranchType(BoardEntry $entry, string $command): string
    {
        $type = trim((string) $entry->getMeta('type'));
        if ($type === '') {
            return '';
        }
        if (!in_array($type, ['feat', 'fix'], true)) {
            throw new \RuntimeException(sprintf(
                '%s only accepts [feat] or [fix] task prefixes.',
                $command,
            ));
        }

        return $type;
    }

    private function validateTaskEntryTypeMetadata(BoardEntry $entry, string $command): void
    {
        $this->taskDeclaredBranchType($entry, $command);
    }

    private function resolveFeatureStartBranchType(BoardEntry $entry, ?BoardEntry $parentFeatureEntry, string $override): string
    {
        $declaredType = $this->taskDeclaredBranchType($entry, 'feature-start');

        if ($parentFeatureEntry !== null) {
            $parentBranch = $parentFeatureEntry->getMeta('branch') ?? '';
            $parentBranchType = $this->detectBranchType($parentBranch);
            if ($parentBranchType === '') {
                throw new \RuntimeException('Parent feature metadata is incomplete: missing branch type.');
            }
            if ($override !== '' && $override !== $parentBranchType) {
                throw new \RuntimeException(sprintf(
                    'feature-start cannot use branch type %s because parent feature branch already uses %s.',
                    $override,
                    $parentBranchType,
                ));
            }
            if ($declaredType !== '' && $declaredType !== $parentBranchType) {
                throw new \RuntimeException(sprintf(
                    'feature-start cannot start task type %s in feature branch type %s.',
                    $declaredType,
                    $parentBranchType,
                ));
            }

            return $parentBranchType;
        }

        if ($override !== '' && $declaredType !== '' && $override !== $declaredType) {
            throw new \RuntimeException(sprintf(
                'feature-start cannot use branch type %s because the queued task declares type %s.',
                $override,
                $declaredType,
            ));
        }

        if ($declaredType !== '') {
            return $declaredType;
        }
        if ($override !== '') {
            return $override;
        }

        return 'feat';
    }

    private function assertFeatureEntry(BoardEntry $entry, string $command): void
    {
        if ($this->isFeatureEntry($entry)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s only applies to kind=feature entries.',
            $command,
        ));
    }

    private function assertTaskEntry(BoardEntry $entry, string $command): void
    {
        if ($this->isTaskEntry($entry)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s only applies to kind=task entries.',
            $command,
        ));
    }

    private function assertNoActiveTasksForFeature(BacklogBoard $board, string $feature, string $command): void
    {
        $tasks = $this->findTaskEntriesByFeature($board, $feature);
        if ($tasks === []) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s cannot continue while feature %s still has active task branches.',
            $command,
            $feature,
        ));
    }

    /**
     * @param array<string> $commandArgs
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireTaskByReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): array
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature/task>.', $command));
        }

        return $this->requireTaskByReference($board, $commandArgs[0], $command);
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireTaskByReference(BacklogBoard $board, string $reference, string $command): array
    {
        $normalizedReference = trim($reference);
        if ($normalizedReference === '') {
            throw new \RuntimeException(sprintf('%s requires a task reference.', $command));
        }

        if (str_contains($normalizedReference, '/')) {
            [$feature, $task] = array_pad(explode('/', $normalizedReference, 2), 2, '');
            $feature = $this->normalizeFeatureSlug($feature);
            $task = $this->normalizeFeatureSlug($task);

            foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
                if (($match['entry']->getMeta('task') ?? '') === $task) {
                    return $match;
                }
            }

            throw new \RuntimeException(sprintf('Task not found: %s/%s', $feature, $task));
        }

        $task = $this->normalizeFeatureSlug($normalizedReference);
        $matches = $this->findTaskEntriesByTaskSlug($board, $task);
        if ($matches === []) {
            throw new \RuntimeException(sprintf('Task not found: %s', $task));
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                '%s requires <feature/task> because task slug %s is not unique.',
                $command,
                $task,
            ));
        }

        return $matches[0];
    }

    private function assertTaskSlugAvailableForFeature(
        BacklogBoard $board,
        BoardEntry $featureEntry,
        string $feature,
        string $task,
        string $command,
    ): void {
        foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
            if (($match['entry']->getMeta('task') ?? '') === $task) {
                throw new \RuntimeException(sprintf(
                    '%s cannot continue because task %s is already active in feature %s.',
                    $command,
                    $task,
                    $feature,
                ));
            }
        }

        foreach ($this->featureContributionBlocks($featureEntry) as $block) {
            if ($block['task'] === $task) {
                throw new \RuntimeException(sprintf(
                    '%s cannot continue because task %s is already recorded in feature %s.',
                    $command,
                    $task,
                    $feature,
                ));
            }
        }
    }

    private function featureHasNoDevelopment(BoardEntry $entry): bool
    {
        $branch = $entry->getMeta('branch') ?? '';
        $base = $entry->getMeta('base') ?? '';
        if ($branch === '' || $base === '') {
            throw new \RuntimeException('Feature metadata is incomplete: missing branch or base.');
        }

        $this->ensureBranchHasNoDirtyManagedWorktree($branch);

        $ahead = trim($this->captureGitOutput(sprintf(
            'git rev-list --count %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch),
        )));

        return $ahead === '0';
    }

    private function featureSlugger(): TextSlugger
    {
        return new TextSlugger(
            maxWords: self::FEATURE_SLUG_MAX_WORDS,
            maxLength: self::FEATURE_SLUG_MAX_LENGTH,
        );
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireFeature(BacklogBoard $board, string $feature): array
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found: {$feature}");
        }

        return $match;
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireParentFeature(BacklogBoard $board, string $feature): array
    {
        return $this->requireFeature($board, $feature);
    }

    private function getSingleFeatureForAgent(BacklogBoard $board, string $agent, bool $required): ?BoardEntry
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            if ($required) {
                throw new \RuntimeException("Agent {$agent} has no active feature.");
            }
            return null;
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features. Resolve the backlog before continuing.");
        }

        return $matches[0]['entry'];
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireSingleFeatureForAgent(BacklogBoard $board, string $agent): array
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active feature.");
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features.");
        }

        return $matches[0];
    }

    private function getSingleTaskForAgent(BacklogBoard $board, string $agent, bool $required): ?BoardEntry
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            if ($required) {
                throw new \RuntimeException("Agent {$agent} has no active task.");
            }

            return null;
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0]['entry'];
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}
     */
    private function requireSingleTaskForAgent(BacklogBoard $board, string $agent): array
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active task.");
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0];
    }

    /**
     * @param array<int, array{index: int, entry: BoardEntry}> $reserved
     */
    private function removeReservedTasks(BacklogBoard $board, array $reserved): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $indexes = array_map(static fn(array $item): int => $item['index'], $reserved);
        rsort($indexes);

        foreach ($indexes as $index) {
            array_splice($entries, $index, 1);
        }

        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
    }

    private function removeActiveEntryAt(BacklogBoard $board, int $index): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, array_values($entries));
    }

    /**
     * @return array{index: int, entry: BoardEntry}|null
     */
    private function nextTodoTask(BacklogBoard $board): ?array
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            return null;
        }

        return ['index' => 0, 'entry' => $entries[0]];
    }

    private function detectBranchType(string $branch): string
    {
        if (preg_match('/^(feat|fix)\//', $branch, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    private function findFeaturesByAgent(BacklogBoard $board, string $agent): array
    {
        return $this->findFeatureEntriesByAgent($board, $agent);
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    private function findFeatureEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry) || ($entry->getMeta('agent') ?? '') !== $agent) {
                continue;
            }

            $matches[] = [
                'section' => BacklogBoard::SECTION_ACTIVE,
                'index' => $index,
                'entry' => $entry,
            ];
        }

        return $matches;
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    private function findTaskEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry) || ($entry->getMeta('agent') ?? '') !== $agent) {
                continue;
            }

            $matches[] = [
                'section' => BacklogBoard::SECTION_ACTIVE,
                'index' => $index,
                'entry' => $entry,
            ];
        }

        return $matches;
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}|null
     */
    private function findParentFeatureEntry(BacklogBoard $board, string $feature): ?array
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry)) {
                continue;
            }
            if (($entry->getMeta('feature') ?? '') !== $feature) {
                continue;
            }

            return ['section' => BacklogBoard::SECTION_ACTIVE, 'index' => $index, 'entry' => $entry];
        }

        return null;
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    private function findTaskEntriesByFeature(BacklogBoard $board, string $feature): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry)) {
                continue;
            }
            if (($entry->getMeta('feature') ?? '') !== $feature) {
                continue;
            }

            $matches[] = ['section' => BacklogBoard::SECTION_ACTIVE, 'index' => $index, 'entry' => $entry];
        }

        return $matches;
    }

    /**
     * @return array{section: string, index: int, entry: BoardEntry}|null
     */
    private function findTaskEntryByTaskSlug(BacklogBoard $board, string $task): ?array
    {
        return $this->findTaskEntriesByTaskSlug($board, $task)[0] ?? null;
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    private function findTaskEntriesByTaskSlug(BacklogBoard $board, string $task): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry)) {
                continue;
            }
            if (($entry->getMeta('task') ?? '') !== $task) {
                continue;
            }

            $matches[] = ['section' => BacklogBoard::SECTION_ACTIVE, 'index' => $index, 'entry' => $entry];
        }

        return $matches;
    }

    /**
     * @return array<int, array{task: string, text: string, extraLines: array<string>}>
     */
    private function featureContributionBlocks(BoardEntry $featureEntry): array
    {
        $blocks = [];
        $currentIndex = null;

        foreach ($featureEntry->getExtraLines() as $line) {
            if (preg_match(self::TASK_CONTRIBUTION_PREFIX_PATTERN, trim($line), $matches) === 1) {
                $blocks[] = ['task' => $matches[1], 'text' => trim($matches[2]), 'extraLines' => []];
                $currentIndex = array_key_last($blocks);
                continue;
            }

            if ($currentIndex === null) {
                continue;
            }

            $blocks[$currentIndex]['extraLines'][] = '  ' . ltrim($line);
        }

        return $blocks;
    }

    /**
     * @param array<int, array{task: string, text: string, extraLines: array<string>}> $blocks
     */
    private function rebuildFeatureFromContributionBlocks(BoardEntry $featureEntry, array $blocks): void
    {
        $lines = [];

        foreach ($blocks as $block) {
            $lines[] = sprintf('  - [task:%s] %s', $block['task'], $block['text']);
            foreach ($block['extraLines'] as $line) {
                $lines[] = '    ' . ltrim($line);
            }
        }

        $featureEntry->setExtraLines($lines);
    }

    private function appendTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): void
    {
        $blocks = $this->featureContributionBlocks($featureEntry);
        $task = (string) ($taskEntry->getMeta('task') ?? '');
        foreach ($blocks as $block) {
            if ($block['task'] === $task) {
                return;
            }
        }

        $blocks[] = [
            'task' => $task,
            'text' => $taskEntry->getText(),
            'extraLines' => $taskEntry->getExtraLines(),
        ];
        $this->rebuildFeatureFromContributionBlocks($featureEntry, $blocks);
    }

    /**
     * @return bool
     */
    private function removeTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): bool
    {
        $blocks = $this->featureContributionBlocks($featureEntry);
        $remaining = [];
        $removed = false;
        $task = (string) ($taskEntry->getMeta('task') ?? '');

        foreach ($blocks as $block) {
            if (!$removed && $block['task'] === $task) {
                $removed = true;
                continue;
            }

            $remaining[] = $block;
        }

        if (!$removed) {
            return false;
        }

        $this->rebuildFeatureFromContributionBlocks($featureEntry, $remaining);

        return $remaining !== [];
    }

    private function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->featureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setMeta('stage', BacklogBoard::STAGE_IN_PROGRESS);
        }
    }

    private function prepareAgentWorktree(string $agent): string
    {
        $path = $this->projectRoot . '/.worktrees/' . $agent;
        $relativePath = $this->toRelativeProjectPath($path);
        $exists = is_dir($path . '/.git') || is_file($path . '/.git');
        $created = false;

        if (!$exists) {
            $this->runGitCommand(sprintf('git worktree add --detach %s HEAD', escapeshellarg($relativePath)));
            $created = true;
            if ($this->dryRun) {
                $this->logVerbose('[dry-run] Skipping worktree status check for non-created path: ' . $relativePath);

                return $path;
            }
        }

        $status = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($status !== '') {
            throw new \RuntimeException("Agent worktree is dirty: {$path}");
        }

        $this->ensureWorktreeRuntimeState($path, $created);

        return $path;
    }

    private function prepareFeatureAgentWorktree(BoardEntry $entry): string
    {
        $agent = $entry->getMeta('agent') ?? '';
        if ($agent === '') {
            throw new \RuntimeException('Feature has no assigned agent worktree.');
        }

        $branch = $entry->getMeta('branch') ?? '';
        if ($branch === '') {
            throw new \RuntimeException('Feature has no branch metadata.');
        }

        $worktree = $this->prepareAgentWorktree($agent);
        $this->checkoutBranchInWorktree($worktree, $branch, false);

        return $worktree;
    }

    /**
     * @return array{path: string, temporary: bool}
     */
    private function prepareFeatureMergeWorktree(string $featureBranch, string $feature): array
    {
        $existingPath = $this->findWorktreePathForBranch($featureBranch);
        if ($existingPath !== null) {
            $dirty = trim($this->captureGitOutput($this->gitInPath($existingPath, 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Clean it before feature-task-merge.',
                    $featureBranch,
                    $existingPath,
                ));
            }

            return ['path' => $existingPath, 'temporary' => false];
        }

        $path = $this->projectRoot . '/.worktrees/merge-' . $feature;
        $relativePath = $this->toRelativeProjectPath($path);
        if (is_dir($path) || is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree path already exists: %s',
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree add %s %s',
            escapeshellarg($relativePath),
            escapeshellarg($featureBranch),
        ));
        $this->ensureWorktreeRuntimeState($path, true);

        return ['path' => $path, 'temporary' => true];
    }

    private function removeTemporaryMergeWorktree(string $path): void
    {
        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($dirty !== '') {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree is dirty and cannot be removed automatically: %s',
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path)),
        ));
    }

    private function cleanupMergedTaskWorktree(string $agent, string $taskBranch, BacklogBoard $board): void
    {
        if ($this->findTaskEntriesByAgent($board, $agent) !== []) {
            return;
        }

        $path = $this->projectRoot . '/.worktrees/' . $agent;
        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $boundBranch = $this->findBranchForWorktreePath($path);
        if ($boundBranch !== $taskBranch) {
            return;
        }

        $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($dirty !== '') {
            throw new \RuntimeException(sprintf(
                'Task worktree for %s is dirty after merge and must be cleaned manually: %s',
                $agent,
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path)),
        ));
    }

    private function ensureLocalBranchExists(string $branch, string $startPoint): void
    {
        if ($this->gitCommandSucceeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/heads/' . $branch),
        ))) {
            return;
        }

        $this->runGitCommand(sprintf(
            'git branch %s %s',
            escapeshellarg($branch),
            escapeshellarg($startPoint),
        ));
    }

    private function requireLocalBranchExists(string $branch, string $context): void
    {
        if ($this->dryRun) {
            $this->logVerbose(sprintf(
                '[dry-run] Assuming local branch %s exists for %s.',
                $branch,
                $context,
            ));

            return;
        }

        if ($this->gitCommandSucceeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/heads/' . $branch),
        ))) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s requires local branch %s to exist.',
            $context,
            $branch,
        ));
    }

    private function ensureWorktreeRuntimeState(string $worktree, bool $created): void
    {
        foreach ($this->copiedWorktreePaths() as $relativePath => $sourcePath) {
            if (!file_exists($sourcePath) && !is_link($sourcePath)) {
                throw new \RuntimeException("Missing dependency source in WP: {$sourcePath}");
            }

            $targetPath = $worktree . '/' . $relativePath;
            $parent = dirname($targetPath);
            if (!is_dir($parent)) {
                if ($this->dryRun) {
                    $this->logVerbose('[dry-run] Would create directory: ' . $this->toRelativeProjectPath($parent));
                    continue;
                }
                mkdir($parent, 0777, true);
            }

            if (!$created && (file_exists($targetPath) || is_link($targetPath))) {
                continue;
            }

            $this->replacePathWithCopy($sourcePath, $targetPath);
        }

        $this->syncWorktreeRootEnv($worktree);
        $this->writeBackendWorktreeEnvLocal($worktree);
    }

    /**
     * @return array<string, string>
     */
    private function copiedWorktreePaths(): array
    {
        return [
            'backend/vendor' => $this->projectRoot . '/backend/vendor',
            'frontend/node_modules' => $this->projectRoot . '/frontend/node_modules',
        ];
    }

    private function replacePathWithCopy(string $sourcePath, string $targetPath): void
    {
        $this->removeFilesystemPath($targetPath);
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would copy path: ' . $this->toRelativeProjectPath($sourcePath) . ' -> ' . $this->toRelativeProjectPath($targetPath));
            return;
        }
        $this->copyFilesystemPath($sourcePath, $targetPath);
    }

    private function syncWorktreeRootEnv(string $worktree): void
    {
        $sourcePath = $this->projectRoot . '/.env';
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Missing root .env in WP.');
        }

        $this->replacePathWithCopy($sourcePath, $worktree . '/.env');
    }

    private function writeBackendWorktreeEnvLocal(string $worktree): void
    {
        $targetPath = $worktree . '/backend/.env.local';
        $contents = $this->buildBackendWorktreeEnvLocalContents();
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would write file: ' . $this->toRelativeProjectPath($targetPath));
            return;
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new \RuntimeException("Unable to write file: {$targetPath}");
        }
    }

    private function buildBackendWorktreeEnvLocalContents(): string
    {
        $envFile = $this->projectRoot . '/.env';
        $content = @file_get_contents($envFile);
        if ($content === false) {
            return self::WA_BACKEND_ENV_LOCAL_FALLBACK;
        }

        if (preg_match('/^DATABASE_URL=(["\']?)(.+)\1$/m', $content, $matches) !== 1) {
            return self::WA_BACKEND_ENV_LOCAL_FALLBACK;
        }

        $databaseUrl = trim($matches[2]);
        $localUrl = preg_replace('/@db(?=[:\/])/', '@localhost', $databaseUrl, 1);
        if (!is_string($localUrl) || $localUrl === $databaseUrl) {
            return self::WA_BACKEND_ENV_LOCAL_FALLBACK;
        }

        return sprintf("DATABASE_URL=\"%s\"\n", $localUrl);
    }

    private function removeFilesystemPath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would remove path: ' . $this->toRelativeProjectPath($path));
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Unable to remove path: {$path}");
            }

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new \RuntimeException("Unable to remove directory: {$item->getPathname()}");
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new \RuntimeException("Unable to remove path: {$item->getPathname()}");
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException("Unable to remove directory: {$path}");
        }
    }

    private function copyFilesystemPath(string $sourcePath, string $targetPath): void
    {
        if (is_link($sourcePath)) {
            $linkTarget = readlink($sourcePath);
            if ($linkTarget === false || !symlink($linkTarget, $targetPath)) {
                throw new \RuntimeException("Unable to copy symlink: {$sourcePath}");
            }

            return;
        }

        if (is_file($sourcePath)) {
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException("Unable to copy file: {$sourcePath}");
            }

            return;
        }

        if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
            throw new \RuntimeException("Unable to create directory: {$targetPath}");
        }

        $iterator = new \FilesystemIterator($sourcePath, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $this->copyFilesystemPath($item->getPathname(), $targetPath . '/' . $item->getBasename());
        }
    }

    private function checkoutBranchInWorktree(string $worktree, string $branch, bool $create, string $startPoint = 'origin/main'): void
    {
        if ($branch === '') {
            throw new \RuntimeException('Missing branch name.');
        }

        $this->releaseBranchFromOtherWorktrees($branch, $worktree);

        if ($this->dryRun && !is_dir($worktree . '/.git') && !is_file($worktree . '/.git')) {
            $this->logVerbose('[dry-run] Skipping worktree-local git inspection for non-created path: ' . $this->toRelativeProjectPath($worktree));
            if ($create) {
                $this->runGitCommand($this->gitInPath(
                    $worktree,
                    sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint)),
                ));

                return;
            }

            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout %s', escapeshellarg($branch)),
            ));

            return;
        }

        $currentBranch = null;
        if ($this->gitCommandSucceeds($this->gitInPath($worktree, 'symbolic-ref --quiet --short HEAD'))) {
            $currentBranch = trim($this->captureGitOutput($this->gitInPath($worktree, 'symbolic-ref --quiet --short HEAD')));
        }

        if (!$create && $currentBranch === $branch) {
            return;
        }

        if ($create) {
            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint)),
            ));
            return;
        }

        $hasLocal = $this->gitCommandSucceeds($this->gitInPath(
            $worktree,
            sprintf('rev-parse --verify %s', escapeshellarg($branch)),
        ));
        if ($hasLocal) {
            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout %s', escapeshellarg($branch)),
            ));
            return;
        }

        $this->runGitCommand($this->gitInPath(
            $worktree,
            sprintf('checkout -B %s origin/%s', escapeshellarg($branch), escapeshellarg($branch)),
        ));
    }

    private function releaseBranchFromOtherWorktrees(string $branch, string $keepWorktree): void
    {
        $output = $this->captureGitOutput('git worktree list --porcelain');
        $blocks = preg_split('/\n\n/', trim($output)) ?: [];

        foreach ($blocks as $block) {
            $path = null;
            $ref = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                }
                if (str_starts_with($line, 'branch refs/heads/')) {
                    $ref = substr($line, strlen('branch refs/heads/'));
                }
            }

            if ($path === null || $ref !== $branch || realpath($path) === realpath($keepWorktree)) {
                continue;
            }

            $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException("Branch {$branch} is still active in a dirty worktree: {$path}");
            }

            if (!str_starts_with($path, $this->projectRoot . '/.worktrees/')) {
                throw new \RuntimeException("Branch {$branch} is active in a non-managed worktree: {$path}");
            }

            $this->runGitCommand(sprintf('git worktree remove %s --force', escapeshellarg($this->toRelativeProjectPath($path))));
        }
    }

    private function ensureBranchHasNoDirtyManagedWorktree(string $branch): void
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if ($binding['branch'] !== $branch) {
                continue;
            }

            $dirty = trim($this->captureGitOutput($this->gitInPath($binding['path'], 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Commit or discard local changes before feature-close.',
                    $branch,
                    $binding['path'],
                ));
            }
        }
    }

    private function pushBranchIfAhead(string $branch): void
    {
        if (!$this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)))) {
            return;
        }

        if (!$this->gitCommandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/remotes/origin/' . $branch)))) {
            $this->pushBranchAndWaitForRemoteVisibility($branch);

            return;
        }

        $ahead = trim($this->captureGitOutput(sprintf(
            'git rev-list --count %s..%s',
            escapeshellarg('origin/' . $branch),
            escapeshellarg($branch),
        )));

        if ($ahead !== '0') {
            $this->pushBranchAndWaitForRemoteVisibility($branch);
        }
    }

    /**
     * @return array<int, array{path: string, branch: string|null}>
     */
    private function listWorktreeBranchBindings(): array
    {
        $blocks = $this->gitWorktreeBlocks();
        $bindings = [];

        foreach ($blocks as $block) {
            $path = $block['path'];
            $branch = $block['branch'];

            if ($path !== null) {
                $bindings[] = ['path' => $path, 'branch' => $branch];
            }
        }

        return $bindings;
    }

    private function findWorktreePathForBranch(string $branch): ?string
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if (($binding['branch'] ?? null) !== $branch) {
                continue;
            }

            return $binding['path'];
        }

        return null;
    }

    private function findBranchForWorktreePath(string $path): ?string
    {
        $realPath = realpath($path);
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            $bindingPath = realpath($binding['path']);
            if ($bindingPath === false || $realPath === false || $bindingPath !== $realPath) {
                continue;
            }

            return $binding['branch'];
        }

        return null;
    }

    /**
     * @return array{managed: array<int, array{path: string, branch: string|null, feature: string|null, agent: string|null, state: string, action: string}>, external: array<int, array{path: string, branch: string|null, action: string}>}
     */
    private function classifyWorktrees(BacklogBoard $board): array
    {
        $managed = [];
        $external = [];
        $activeFeatures = $this->activeFeaturesByBranch($board);

        foreach ($this->gitWorktreeBlocks() as $worktree) {
            $path = $worktree['path'];
            if ($path === $this->projectRoot) {
                continue;
            }

            if (!$this->isManagedAgentWorktree($path)) {
                $external[] = [
                    'path' => $path,
                    'branch' => $worktree['branch'],
                    'action' => $worktree['prunable'] ? 'manual-prune' : 'manual-remove',
                ];
                continue;
            }

            $feature = null;
            $agent = null;
            $state = 'orphan';
            $action = 'clean';
            $branch = $worktree['branch'];

            if ($worktree['prunable']) {
                $state = 'prunable';
                $action = 'manual-prune';
            } elseif ($branch !== null && isset($activeFeatures[$branch])) {
                $feature = $activeFeatures[$branch]['feature'];
                $agent = $activeFeatures[$branch]['agent'];
                $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
                $dirty = $this->worktreeIsDirty($path);

                if ($path !== $expectedPath) {
                    $state = 'blocked';
                    $action = 'manual-review';
                } elseif ($dirty) {
                    $state = 'dirty';
                    $action = 'manual-review';
                } else {
                    $state = 'active';
                    $action = 'keep';
                }
            } else {
                $agent = basename($path);
                if ($this->worktreeIsDirty($path)) {
                    $state = 'dirty';
                    $action = 'manual-review';
                } elseif ($branch === null) {
                    $state = 'detached-managed';
                    $action = 'clean';
                }
            }

            $managed[] = [
                'path' => $path,
                'branch' => $branch,
                'feature' => $feature,
                'agent' => $agent,
                'state' => $state,
                'action' => $action,
            ];
        }

        return ['managed' => $managed, 'external' => $external];
    }

    /**
     * @return array<string, array{feature: string, agent: string}>
     */
    private function activeFeaturesByBranch(BacklogBoard $board): array
    {
        $features = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $branch = $entry->getMeta('branch') ?? '';
            $feature = $entry->getMeta('feature') ?? '';
            $agent = $entry->getMeta('agent') ?? '';
            if ($branch === '' || $feature === '' || $agent === '') {
                continue;
            }

            $features[$branch] = [
                'feature' => $feature,
                'agent' => $agent,
            ];
        }

        return $features;
    }

    /**
     * @return array<int, array{path: string, branch: string|null, prunable: bool}>
     */
    private function gitWorktreeBlocks(): array
    {
        $output = trim($this->captureGitOutput('git worktree list --porcelain'));
        if ($output === '') {
            return [];
        }

        $blocks = preg_split('/\n\n/', $output) ?: [];
        $worktrees = [];

        foreach ($blocks as $block) {
            $path = null;
            $branch = null;
            $prunable = false;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                    continue;
                }
                if (str_starts_with($line, 'branch refs/heads/')) {
                    $branch = substr($line, strlen('branch refs/heads/'));
                    continue;
                }
                if (str_starts_with($line, 'prunable ')) {
                    $prunable = true;
                }
            }

            if ($path !== null) {
                $worktrees[] = [
                    'path' => $path,
                    'branch' => $branch,
                    'prunable' => $prunable,
                ];
            }
        }

        return $worktrees;
    }

    private function isManagedAgentWorktree(string $path): bool
    {
        return str_starts_with($path, $this->projectRoot . '/.worktrees/');
    }

    private function worktreeIsDirty(string $path): bool
    {
        if (!is_dir($path) && !is_file($path)) {
            return false;
        }

        return trim($this->captureGitOutput($this->gitInPath($path, 'status --short'))) !== '';
    }

    private function workspaceHasLocalChanges(): bool
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace changes: git status --short');

            return trim($this->capture('git status --short')) !== '';
        }

        return trim($this->captureGitOutput('git status --short')) !== '';
    }

    private function workspaceCurrentBranch(): string
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace branch: git branch --show-current');

            return trim($this->capture('git branch --show-current'));
        }

        return trim($this->captureGitOutput('git branch --show-current'));
    }

    private function updateLocalMainBeforeFeatureStart(): void
    {
        if ($this->workspaceCurrentBranch() !== 'main') {
            $this->runNetworkCommand('git fetch origin main:main', 'Git');

            return;
        }

        $this->updateLocalMainInWorkspaceWithWarning('feature-start');
    }

    private function updateLocalMainInWorkspaceWithWarning(string $context): void
    {
        try {
            $this->runNetworkCommand('git pull --ff-only', 'Git');
        } catch (\RuntimeException $exception) {
            $this->console->warn(sprintf(
                'Unable to update local main in WP during %s; continuing with the current local main.',
                $context,
            ));
            $this->logVerbose('Main update warning detail: ' . $exception->getMessage());
        }
    }

    private function cleanupAbandonedManagedWorktrees(BacklogBoard $board): int
    {
        ['managed' => $managed] = $this->classifyWorktrees($board);

        $cleanable = array_values(array_filter(
            $managed,
            static fn(array $item): bool => in_array($item['state'], ['orphan', 'detached-managed'], true)
        ));

        foreach ($cleanable as $item) {
            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item['path'])),
            ));
        }

        return count($cleanable);
    }

    private function cleanupManagedWorktreesForBranch(string $branch, BacklogBoard $board): int
    {
        if ($branch === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->classifyWorktrees($board)['managed'] as $item) {
            if (($item['branch'] ?? null) !== $branch) {
                continue;
            }
            if (!in_array($item['state'], ['orphan', 'detached-managed'], true)) {
                continue;
            }

            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item['path'])),
            ));
            $count++;
        }

        return $count;
    }

    private function runReviewScript(string $worktree): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run review in ' : 'Run review in ') . $this->toRelativeProjectPath($worktree));
        if ($this->dryRun) {
            return;
        }

        $this->runCommand(sprintf(
            'cd %s && php scripts/review.php',
            escapeshellarg($this->toRelativeProjectPath($worktree)),
        ));
    }

    private function determinePrType(BoardEntry $entry): string
    {
        $base = $entry->getMeta('base') ?? '';
        $branch = $entry->getMeta('branch') ?? '';
        if ($base === '' || $branch === '') {
            throw new \RuntimeException('Cannot determine PR type without base and branch metadata.');
        }

        $files = array_values(array_filter(explode("\n", trim($this->captureGitOutput(sprintf(
            'git diff --name-only %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch),
        ))))));

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? 'FIX' : 'FEAT';
        }

        $docOnly = true;
        $techOnly = true;

        foreach ($files as $file) {
            if (!str_starts_with($file, 'doc/') && $file !== 'AGENTS.md') {
                $docOnly = false;
            }

            if (
                !str_starts_with($file, 'scripts/')
                && !str_starts_with($file, '.github/')
                && !in_array($file, ['AGENTS.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml'], true)
            ) {
                $techOnly = false;
            }
        }

        if ($docOnly) {
            return 'DOC';
        }

        if ($techOnly) {
            return 'TECH';
        }

        return str_starts_with($branch, 'fix/') ? 'FIX' : 'FEAT';
    }

    private function buildPrTitle(string $type, BoardEntry $entry): string
    {
        $title = sprintf('[%s] %s', $type, $entry->getText());

        return $entry->hasMeta('blocked')
            ? $this->ensureBlockedTitle($title)
            : $title;
    }

    private function buildCurrentTitle(BoardEntry $entry): string
    {
        $type = $this->featureStage($entry) === BacklogBoard::STAGE_APPROVED
            ? $this->determinePrType($entry)
            : 'WIP';

        return $this->buildPrTitle($type, $entry);
    }

    private function ensureBlockedTitle(string $title): string
    {
        return str_contains($title, '[BLOCKED]')
            ? $title
            : '[BLOCKED] ' . $title;
    }

    private function createOrUpdatePr(string $branch, string $title, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);

        if ($prNumber === null) {
            $this->createPrWithRetry($branch, $title, $bodyFile);
            return;
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --title %s --body-file %s',
            $prNumber,
            escapeshellarg($title),
            escapeshellarg($bodyFile),
        ));
    }

    private function createPrWithRetry(string $branch, string $title, string $bodyFile): void
    {
        $command = sprintf(
            'php scripts/github.php pr create --title %s --head %s --base main --body-file %s',
            escapeshellarg($title),
            escapeshellarg($branch),
            escapeshellarg($bodyFile),
        );

        [$code, $output] = $this->networkRetryHelper()->run(
            function () use ($branch, $command): array {
                [$code, $output] = $this->captureNetworkCommandWithRetry($command, 'GitHub');
                if ($code !== 0 && $this->isHeadInvalidCreateError($output)) {
                    $this->waitForRemoteBranchVisibility($branch);
                }

                return [$code, $output];
            },
            fn(array $result): bool => $result[0] !== 0 && $this->isHeadInvalidCreateError($result[1]),
        );

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function pushBranchAndWaitForRemoteVisibility(string $branch, ?string $worktree = null): void
    {
        $gitPrefix = $worktree !== null
            ? sprintf('git -C %s', escapeshellarg($this->toRelativeProjectPath($worktree)))
            : 'git';

        $this->runNetworkCommand(sprintf(
            '%s push -u origin %s',
            $gitPrefix,
            escapeshellarg($branch),
        ), 'Git');

        $this->runNetworkCommand(sprintf(
            '%s fetch origin %s:%s',
            $gitPrefix,
            escapeshellarg($branch),
            escapeshellarg('refs/remotes/origin/' . $branch),
        ), 'Git');

        $this->waitForRemoteBranchVisibility($branch);
    }

    private function gitInPath(string $path, string $subCommand): string
    {
        return sprintf(
            'git -C %s %s',
            escapeshellarg($this->toRelativeProjectPath($path)),
            $subCommand,
        );
    }

    private function toRelativeProjectPath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedPath === $normalizedRoot) {
            return '.';
        }

        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            return $path;
        }

        return substr($normalizedPath, strlen($prefix));
    }

    private function waitForRemoteBranchVisibility(string $branch): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would wait for remote branch visibility: ' . $branch);
            return;
        }

        $isVisible = $this->networkRetryHelper()->run(
            fn(): bool => $this->isRemoteBranchVisible($branch),
            fn(bool $result): bool => !$result,
        );

        if ($isVisible) {
            return;
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    private function isRemoteBranchVisible(string $branch): bool
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry(sprintf(
            'git ls-remote --heads origin %s',
            escapeshellarg($branch),
        ), 'Git');

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d while checking remote branch visibility: %s\n%s",
                $code,
                $branch,
                $output,
            ));
        }

        return trim($output) !== '';
    }

    private function isHeadInvalidCreateError(string $output): bool
    {
        return str_contains($output, self::PR_CREATE_HEAD_INVALID_NEEDLE);
    }

    private function updatePrBody(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --body-file %s',
            $prNumber,
            escapeshellarg($bodyFile),
        ));
    }

    private function updatePrBodyIfExists(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            return;
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --body-file %s',
            $prNumber,
            escapeshellarg($bodyFile),
        ));
    }

    private function findPrNumberByBranch(string $branch): ?int
    {
        if ($branch === '') {
            return null;
        }

        $output = $this->captureNetworkOutputWithRetry('php scripts/github.php pr list', 'GitHub');
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^\s*#(\d+)\s+.*\[(.+?) → (.+?)\]$/u', $line, $matches) === 1) {
                if ($matches[2] === $branch) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    private function numberedReviewItems(string $bodyFile): array
    {
        $contents = trim((string) file_get_contents($bodyFile));
        if ($contents === '') {
            return ['1. No details provided.'];
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $contents) ?: [])));
        $items = [];

        foreach ($lines as $index => $line) {
            $normalized = preg_match('/^\d+\.\s+/', $line) === 1
                ? $line
                : sprintf('%d. %s', $index + 1, $line);
            $items[] = $normalized;
        }

        return $items;
    }

    /**
     * @param array<string> $lines
     */
    private function writeTempContent(array $lines): string
    {
        $path = $this->projectRoot . '/local/tmp/backlog-auto-review.txt';
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would write temp file: ' . $this->toRelativeProjectPath($path));
            return $path;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function nextStepForStage(string $stage): string
    {
        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => 'feature-review-request',
            BacklogBoard::STAGE_IN_REVIEW => 'feature-review-check or feature-review-approve',
            BacklogBoard::STAGE_REJECTED => 'feature-rework',
            BacklogBoard::STAGE_APPROVED => 'feature-merge',
            default => '-',
        };
    }

    private function nextStepForEntry(BoardEntry $entry, string $stage): string
    {
        if ($this->isTaskEntry($entry)) {
            return match ($stage) {
                BacklogBoard::STAGE_IN_PROGRESS => 'task-review-request or feature-task-merge',
                BacklogBoard::STAGE_IN_REVIEW => 'task-review-check, task-review-approve, task-review-reject, or feature-task-merge',
                BacklogBoard::STAGE_REJECTED => 'task-rework or feature-task-merge',
                BacklogBoard::STAGE_APPROVED => 'feature-task-merge',
                default => 'feature-task-merge',
            };
        }

        return $this->nextStepForStage($stage);
    }

    private function taskReviewKey(BoardEntry $entry): string
    {
        return sprintf(
            '%s/%s',
            $entry->getMeta('feature') ?? '-',
            $entry->getMeta('task') ?? '-',
        );
    }

    private function logVerbose(string $message): void
    {
        if ($this->verbose) {
            $this->console->info($message);
        }
    }

    private function saveBoard(BacklogBoard $board, string $reason): void
    {
        $this->logVerbose(sprintf(
            'saveBoard(%s): todo=%d active=%d',
            $reason,
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
        ));
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would save backlog board: ' . $reason);
            return;
        }

        $this->logVerbose('Saving backlog board: ' . $reason);
        $board->save();
    }

    private function saveReviewFile(BacklogReviewFile $review, string $reason): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would save backlog review: ' . $reason);
            return;
        }

        $this->logVerbose('Saving backlog review: ' . $reason);
        $review->save();
    }

    private function runCommand(string $command): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run: ' : 'Run: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $code = $this->app->runCommand($command);
        if ($code !== 0) {
            throw new \RuntimeException("Command failed with exit code {$code}: {$command}");
        }
    }

    private function capture(string $command): string
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf("Command failed with exit code %d: %s\n%s", $code, $command, implode("\n", $output)));
        }

        return implode("\n", $output);
    }

    /**
     * Runs one shell command and returns both exit code and captured output.
     *
     * @return array{0: int, 1: string}
     */
    private function captureWithExitCode(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function commandSucceeds(string $command): bool
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return $code === 0;
    }

    private function runGitCommand(string $command): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run git command: ' : 'Run git command: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $this->runCommand($command);
    }

    private function captureGitOutput(string $command): string
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would capture git output: ' : 'Capture git output: ') . $command);
        if ($this->dryRun) {
            return '';
        }

        return $this->capture($command);
    }

    private function gitCommandSucceeds(string $command): bool
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would check git command success: ' : 'Check git command success: ') . $command);
        if ($this->dryRun) {
            return false;
        }

        return $this->commandSucceeds($command);
    }

    private function runGithubCommand(string $command): void
    {
        $this->runNetworkCommand($command, 'GitHub');
    }

    private function captureGithubOutputWithRetry(string $command): string
    {
        return $this->captureNetworkOutputWithRetry($command, 'GitHub');
    }

    private function runNetworkCommand(string $command, string $label): void
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry($command, $label);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

    }

    /**
     * Runs one network command with retry on transient transport failures.
     *
     * @return array{0: int, 1: string}
     */
    private function captureNetworkCommandWithRetry(string $command, string $label): array
    {
        if ($this->dryRun) {
            $this->logVerbose(sprintf('[dry-run] Would run %s command: %s', strtolower($label), $command));
            return [0, ''];
        }

        $result = $this->networkRetryHelper()->run(
            fn(): array => $this->captureWithExitCode($command),
            fn(array $result): bool => $result[0] !== 0 && $this->isRetryableNetworkError($result[1]),
        );

        if ($result[0] !== 0 && $this->isRetryableNetworkError($result[1])) {
            throw new \RuntimeException(sprintf(
                "%s network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
                $label,
                self::RETRY_COUNT,
                $command,
                $result[1],
            ));
        }

        return $result;
    }

    private function captureNetworkOutputWithRetry(string $command, string $label): string
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry($command, $label);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

        return $output;
    }

    private function isRetryableNetworkError(string $output): bool
    {
        foreach (self::NETWORK_ERROR_NEEDLES as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function networkRetryHelper(): RetryHelper
    {
        return new RetryHelper(
            self::RETRY_COUNT,
            self::RETRY_BASE_DELAY,
			self::RETRY_FACTOR,
        );
    }

    private function describePrStatus(BoardEntry $entry): string
    {
        $storedPrNumber = $this->storedPrNumber($entry);
        if ($storedPrNumber !== null) {
            return '#' . $storedPrNumber;
        }

        $branch = $entry->getMeta('branch') ?? '';
        if ($branch === '') {
            return 'none';
        }

        return 'none';
    }

    private function storedPrNumber(BoardEntry $entry): ?int
    {
        $pr = $entry->getMeta('pr');
        if ($pr === null || $pr === '' || $pr === 'none') {
            return null;
        }

        return (int) $pr;
    }
}
