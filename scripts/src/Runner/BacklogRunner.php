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
    private const WA_BACKEND_ENV_LOCAL_FALLBACK = "DATABASE_URL=\"postgresql://somanagent:secret@127.0.0.1:5432/somanagent?serverVersion=16&charset=utf8\"\n";
    private const PR_CREATE_HEAD_INVALID_NEEDLE = 'resource=PullRequest, field=head, code=invalid';
    private const RETRY_COUNT = 3;
    private const RETRY_BASE_DELAY = 500000; // MICROSECONDS
    private const RETRY_FACTOR = 4;
    private const FEATURE_SLUG_MAX_WORDS = 8;
    private const FEATURE_SLUG_MAX_LENGTH = 64;
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
            ['name' => 'feature-start', 'description' => 'Start a feature branch and move the feature to development'],
            ['name' => 'feature-release', 'description' => 'Return one untouched active feature back to the todo section'],
            ['name' => 'feature-task-add', 'description' => 'Attach the next queued task to the current feature'],
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
            ['name' => '--feature-text', 'description' => 'Replacement feature text for the active backlog entry'],
            ['name' => '--branch-type', 'description' => 'Developer branch type for feature-start: feat or fix'],
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
            'php scripts/backlog.php feature-start --agent agent-01 --branch-type feat',
            'php scripts/backlog.php feature-release --agent agent-01',
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

        return match ($command) {
            'task-create' => $this->createTask($commandArgs, $options),
            'task-todo-list' => $this->taskTodoList(),
            'task-remove' => $this->taskRemove($commandArgs),
            'feature-start' => $this->featureStart($commandArgs, $options),
            'feature-release' => $this->featureRelease($commandArgs, $options),
            'feature-task-add' => $this->featureTaskAdd($commandArgs, $options),
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
            default => throw new \RuntimeException("Unknown backlog command: {$command}"),
        };
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
        array_splice($entries, $position, 0, [new BoardEntry($text)]);
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
        $branchType = (string) ($options['branch-type'] ?? '');
        if (!in_array($branchType, ['feat', 'fix'], true)) {
            throw new \RuntimeException('feature-start requires --branch-type=feat or --branch-type=fix.');
        }

        $board = $this->board();

        if ($this->getSingleFeatureForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active feature.");
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

        $feature = $this->normalizeFeatureSlug($reserved[0]['entry']->getText());

        $worktree = $this->prepareAgentWorktree($agent);
        $branch = $branchType . '/' . $feature;
        $this->runNetworkCommand('git fetch origin main:main', 'Git');
        $base = trim($this->captureGitOutput('git rev-parse main'));

        $this->checkoutBranchInWorktree($worktree, $branch, true);

        $first = $reserved[0]['entry'];
        $first->unsetMeta('feature');
        $first->unsetMeta('agent');
        $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines(), [
            'stage' => BacklogBoard::STAGE_IN_PROGRESS,
            'feature' => $feature,
            'agent' => $agent,
            'branch' => $branch,
            'base' => $base,
            'pr' => 'none',
        ]);

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
            (string) $feature,
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
            (string) $featureEntry->getMeta('stage'),
        ));
        $this->saveBoard($board, 'feature-start');

        $this->console->ok(sprintf('Started feature %s on %s', $feature, $branch));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureRelease(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $current = $this->requireSingleFeatureForAgent($board, $agent);
        $entry = $current['entry'];
        $feature = $entry->getMeta('feature') ?? '';
        if ($feature === '') {
            throw new \RuntimeException("Agent {$agent} has an active feature without feature metadata.");
        }

        $branch = $entry->getMeta('branch') ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . ' to be released.');
        }
        if (!$this->featureHasNoDevelopment($entry)) {
            throw new \RuntimeException("Feature {$feature} already has development work and cannot be released back to todo.");
        }

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
        $entry->setText($featureText);

        foreach ($reserved as $task) {
            $reservedEntry = $task['entry'];
            $reservedEntry->unsetMeta('feature');
            $reservedEntry->unsetMeta('agent');
            $entry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $entry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->removeReservedTasks($board, $reserved);

        if ($this->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $entry->setMeta('stage', BacklogBoard::STAGE_IN_PROGRESS);
        }

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
                    $entry->getMeta('feature') ?? '-',
                    'branch=' . ($entry->getMeta('branch') ?? '-'),
                    'agent=' . ($entry->getMeta('agent') ?? '-'),
                ];
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
            $feature = $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');
        }

        if ($feature === null) {
            throw new \RuntimeException('Unable to resolve target feature for feature-status.');
        }

        $match = $this->requireFeature($board, $feature);
        $this->printFeatureStatus($feature, $match['entry']);

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

        $this->printFeatureStatus($feature, $entry);

        return 0;
    }

    private function printFeatureStatus(string $feature, BoardEntry $entry): void
    {
        $stage = $this->featureStage($entry);
        $this->console->line('Feature: ' . $feature);
        $this->console->line('Branch: ' . ($entry->getMeta('branch') ?? '-'));
        $this->console->line('Base: ' . ($entry->getMeta('base') ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Last: ' . $entry->getText());
        $this->console->line('Next: ' . $this->nextStepForStage($stage));
        $this->console->line('Blocker: ' . ($entry->hasMeta('blocked') ? 'blocked' : '-'));
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

        if ($this->featureStage($match['entry']) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be rejected.');
        }

        $match['entry']->setMeta('stage', BacklogBoard::STAGE_REJECTED);
        $review->setFeatureReview($feature, $this->numberedReviewItems($bodyFile));
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
        $review->clearFeatureReview($feature);
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
        $review->clearFeatureReview($feature);
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
        if ($this->workspaceHasLocalChanges()) {
            $this->runNetworkCommand('git fetch origin main:main', 'Git');
            $skippedMainCheckout = true;
        } else {
            $this->runGitCommand('git checkout main');
            $this->runGitCommand('git pull');
        }

        $board->removeFeature($feature);
        $board->clearReservations($match['entry']->getMeta('agent') ?? '', $feature);
        $review->clearFeatureReview($feature);
        $this->saveBoard($board, 'feature-merge');
        $this->saveReviewFile($review, 'feature-merge');
        $cleaned = $this->cleanupManagedWorktreesForBranch($branch, $board);
        $cleaned += $this->cleanupAbandonedManagedWorktrees($board);

        $this->runGitCommand(sprintf('git push origin --delete %s', escapeshellarg($branch)));
        $this->runGitCommand(sprintf('git branch -D %s', escapeshellarg($branch)));

        $this->console->ok(sprintf('Merged feature %s', $feature));
        if ($skippedMainCheckout) {
            $this->console->line('Main was updated without checkout because WP has local changes.');
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

    private function featureStage(BoardEntry $entry): string
    {
        return BacklogBoard::entryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS;
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
        $match = $board->findFeature($feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found: {$feature}");
        }

        return $match;
    }

    private function getSingleFeatureForAgent(BacklogBoard $board, string $agent, bool $required): ?BoardEntry
    {
        $matches = $board->findFeaturesByAgent($agent);
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
        $matches = $board->findFeaturesByAgent($agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active feature.");
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features.");
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
        $localUrl = preg_replace('/@db(?=[:\/])/', '@127.0.0.1', $databaseUrl, 1);
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

    private function checkoutBranchInWorktree(string $worktree, string $branch, bool $create): void
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
                    sprintf('checkout -B %s main', escapeshellarg($branch)),
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
                sprintf('checkout -B %s main', escapeshellarg($branch)),
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
        return trim($this->captureGitOutput('git status --short')) !== '';
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
