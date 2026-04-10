<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\TextSlugger;

/**
 * Backlog workflow runner for the local developer/reviewer process.
 */
final class BacklogRunner extends AbstractScriptRunner
{
    private const REMOTE_BRANCH_WAIT_ATTEMPTS = 5;
    private const REMOTE_BRANCH_WAIT_DELAY_MICROSECONDS = 1000000;
    private const PR_CREATE_HEAD_INVALID_NEEDLE = 'resource=PullRequest, field=head, code=invalid';
    private const GITHUB_NETWORK_RETRY_COUNT = 3;
    private const GITHUB_NETWORK_INITIAL_DELAY_MICROSECONDS = 1000000;
    private const FEATURE_SLUG_MAX_WORDS = 8;
    private const FEATURE_SLUG_MAX_LENGTH = 64;
    private const TASK_CREATE_POSITION_START = 'start';
    private const TASK_CREATE_POSITION_INDEX = 'index';
    private const TASK_CREATE_POSITION_END = 'end';
    private const GITHUB_NETWORK_ERROR_NEEDLES = [
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
            ['name' => 'task-todo-list', 'description' => 'List queued todo tasks with optional reservation metadata'],
            ['name' => 'task-remove', 'description' => 'Remove one queued todo task by its displayed number'],
            ['name' => 'task-book-next', 'description' => 'Reserve the next backlog task for one developer agent'],
            ['name' => 'task-book-release', 'description' => 'Release one reserved backlog task'],
            ['name' => 'feature-start', 'description' => 'Start a feature branch and move the feature to development'],
            ['name' => 'feature-task-add', 'description' => 'Attach all reserved tasks of one agent to its current feature'],
            ['name' => 'feature-assign', 'description' => 'Assign an existing feature to one developer agent'],
            ['name' => 'feature-unassign', 'description' => 'Remove the current agent assignment from one feature'],
            ['name' => 'feature-rework', 'description' => 'Move a rejected feature back to development'],
            ['name' => 'feature-block', 'description' => 'Mark a feature as blocked'],
            ['name' => 'feature-unblock', 'description' => 'Remove the blocked flag from one feature'],
            ['name' => 'feature-list', 'description' => 'List active features grouped by backlog section'],
            ['name' => 'feature-status', 'description' => 'Print the current status of one feature'],
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
        return [
            ['name' => '--agent', 'description' => 'Developer agent code (required on developer commands)'],
            ['name' => '--body-file', 'description' => 'Path to a local file used for PR or review body content when required'],
            ['name' => '--feature-text', 'description' => 'Replacement feature text for the active backlog entry'],
            ['name' => '--branch-type', 'description' => 'Developer branch type for feature-start: feat or fix'],
            ['name' => '--position', 'description' => 'Insertion position for task-create: start, index, end (default: end)'],
            ['name' => '--index', 'description' => '1-based target position used when --position=index'],
            ['name' => '--force', 'description' => 'Allow taking a task that is already reserved'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/backlog.php task-book-next --agent agent-01',
            'php scripts/backlog.php task-create "Add toast notifications on success and error flows"',
            'php scripts/backlog.php task-todo-list',
            'php scripts/backlog.php task-remove 8',
            'php scripts/backlog.php task-book-next --agent agent-01 delete-question-reply',
            'php scripts/backlog.php feature-start --agent agent-01 --branch-type feat',
            'php scripts/backlog.php feature-list',
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

        return match ($command) {
            'task-create' => $this->createTask($commandArgs, $options),
            'task-todo-list' => $this->taskTodoList(),
            'task-remove' => $this->taskRemove($commandArgs),
            'task-book-next' => $this->taskBookNext($commandArgs, $options),
            'task-book-release' => $this->taskBookRelease($commandArgs, $options),
            'feature-start' => $this->featureStart($commandArgs, $options),
            'feature-task-add' => $this->featureTaskAdd($commandArgs, $options),
            'feature-assign' => $this->featureAssign($commandArgs, $options),
            'feature-unassign' => $this->featureUnassign($commandArgs, $options),
            'feature-rework' => $this->featureRework($commandArgs, $options),
            'feature-block' => $this->featureBlock($commandArgs, $options),
            'feature-unblock' => $this->featureUnblock($commandArgs, $options),
            'feature-list' => $this->featureList(),
            'feature-status' => $this->featureStatus($commandArgs, $options),
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
        $board->save();

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
            $reservation = [];
            if ($entry->getMeta('agent') !== null) {
                $reservation[] = 'agent=' . $entry->getMeta('agent');
            }
            if ($entry->getMeta('feature') !== null) {
                $reservation[] = 'feature=' . $entry->getMeta('feature');
            }

            $suffix = $reservation === [] ? '' : ' [' . implode(', ', $reservation) . ']';
            $this->console->line($prefix . $entry->getText() . $suffix);
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
        $board->save();

        $this->console->ok(sprintf('Removed queued task %d', $position));
        $this->console->info($removed->getText());

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function taskBookNext(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();

        $existingFeature = $this->getSingleFeatureForAgent($board, $agent, false);
        $feature = $existingFeature?->getMeta('feature');

        if ($feature === null) {
            $feature = isset($commandArgs[0])
                ? $this->normalizeFeatureSlug($commandArgs[0])
                : $this->normalizeFeatureSlug($this->nextTaskText($board));
        }

        $target = $board->findNextBookableTask(isset($options['force']));
        if ($target === null) {
            throw new \RuntimeException('No backlog task available to reserve.');
        }

        $entry = $target['entry'];
        $entry->setMeta('agent', $agent);
        $entry->setMeta('feature', $feature);
        $board->save();

        $this->console->ok(sprintf('Reserved task for %s on feature %s', $agent, $feature));
        $this->console->info($entry->getText());

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function taskBookRelease(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0]) ? $this->normalizeFeatureSlug($commandArgs[0]) : null;

        if ($feature === null) {
            $currentFeature = $this->getSingleFeatureForAgent($board, $agent, false);
            $feature = $currentFeature?->getMeta('feature');
        }

        $board->clearReservations($agent, $feature);
        $board->save();
        $this->console->ok($feature !== null
            ? sprintf('Released reserved tasks for %s on feature %s', $agent, $feature)
            : sprintf('Released all reserved tasks for %s', $agent));

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

        $reserved = $board->findReservedTasks($agent);
        if ($reserved === []) {
            throw new \RuntimeException("Agent {$agent} has no reserved tasks to start.");
        }

        $feature = $reserved[0]['entry']->getMeta('feature');
        foreach ($reserved as $task) {
            if ($task['entry']->getMeta('feature') !== $feature) {
                throw new \RuntimeException("Reserved tasks for {$agent} do not share the same feature.");
            }
        }

        $worktree = $this->prepareAgentWorktree($agent);
        $branch = $branchType . '/' . $feature;
        $base = trim($this->capture('git rev-parse main'));

        $this->checkoutBranchInWorktree($worktree, $branch, true);

        $first = $reserved[0]['entry'];
        $first->unsetMeta('feature');
        $first->unsetMeta('agent');
        $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines(), [
            'feature' => $feature,
            'agent' => $agent,
            'branch' => $branch,
            'base' => $base,
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
        $entries = $board->getEntries(BacklogBoard::SECTION_IN_PROGRESS);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_IN_PROGRESS, $entries);
        $board->save();

        $this->console->ok(sprintf('Started feature %s on %s', $feature, $branch));

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
        $reserved = $board->findReservedTasks($agent, $feature);
        if ($reserved === []) {
            throw new \RuntimeException("Agent {$agent} has no reserved tasks for feature {$feature}.");
        }

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

        if ($current['section'] !== BacklogBoard::SECTION_IN_PROGRESS) {
            $board->moveFeature($feature, BacklogBoard::SECTION_IN_PROGRESS);
        }

        $board->save();
        $bodyFile = isset($options['body-file'])
            ? $this->requireBodyFile($options)
            : null;
        if ($bodyFile !== null) {
            $this->updatePrBodyIfExists($entry->getMeta('branch') ?? '', $bodyFile);
        }

        $this->console->ok(sprintf('Added reserved tasks to feature %s', $feature));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureAssign(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $feature = $this->requireFeatureArgument($commandArgs);
        $board = $this->board();

        if ($this->getSingleFeatureForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active feature.");
        }

        $match = $this->requireFeature($board, $feature);
        $match['entry']->setMeta('agent', $agent);
        $board->save();

        $this->checkoutBranchInWorktree(
            $this->prepareAgentWorktree($agent),
            $match['entry']->getMeta('branch') ?? '',
            false,
        );

        $this->console->ok(sprintf('Assigned feature %s to %s', $feature, $agent));

        return 0;
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function featureUnassign(array $commandArgs, array $options): int
    {
        $agent = $this->requireAgent($options);
        $board = $this->board();
        $feature = isset($commandArgs[0])
            ? $this->normalizeFeatureSlug($commandArgs[0])
            : $this->requireSingleFeatureForAgent($board, $agent)['entry']->getMeta('feature');

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unassign.');
        }

        $match = $this->requireFeature($board, $feature);
        if (($match['entry']->getMeta('agent') ?? '') !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match['entry']->unsetMeta('agent');
        $board->save();

        $this->console->ok(sprintf('Unassigned feature %s from %s', $feature, $agent));

        return 0;
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
        if ($match['section'] !== BacklogBoard::SECTION_REJECTED) {
            throw new \RuntimeException("Feature {$feature} is not in the rejected section.");
        }

        $match['entry']->setMeta('agent', $agent);
        $board->moveFeature($feature, BacklogBoard::SECTION_IN_PROGRESS);
        $board->save();

        $this->checkoutBranchInWorktree(
            $this->prepareAgentWorktree($agent),
            $match['entry']->getMeta('branch') ?? '',
            false,
        );

        $this->console->ok(sprintf('Moved feature %s back to %s', $feature, BacklogBoard::SECTION_IN_PROGRESS));

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
        $board->save();

        $branch = $match['entry']->getMeta('branch') ?? '';
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber !== null) {
            $type = $match['section'] === BacklogBoard::SECTION_APPROVED ? $this->determinePrType($match['entry']) : 'WIP';
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
        $board->save();

        $branch = $match['entry']->getMeta('branch') ?? '';
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber !== null) {
            $title = $this->buildCurrentTitle($match['entry'], $match['section']);
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
        $sections = [
            BacklogBoard::SECTION_IN_PROGRESS,
            BacklogBoard::SECTION_IN_REVIEW,
            BacklogBoard::SECTION_REJECTED,
            BacklogBoard::SECTION_APPROVED,
        ];

        $printed = false;
        foreach ($sections as $section) {
            $entries = $board->getEntries($section);
            if ($entries === []) {
                continue;
            }

            $printed = true;
            $this->console->line('[' . $section . ']');
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
        $this->console->line('Feature: ' . $feature);
        $this->console->line('Branch: ' . ($match['entry']->getMeta('branch') ?? '-'));
        $this->console->line('Base: ' . ($match['entry']->getMeta('base') ?? '-'));
        $this->console->line('Stage: ' . $match['section']);
        $prStatus = $this->describePrStatus($match['entry']);
        $this->console->line('PR: ' . $prStatus);
        $this->console->line('Last: ' . $match['entry']->getText());
        $this->console->line('Next: ' . $this->nextStepForSection($match['section']));
        $this->console->line('Blocker: ' . ($match['entry']->hasMeta('blocked') ? 'blocked' : '-'));

        return 0;
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
        if ($match['section'] !== BacklogBoard::SECTION_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::SECTION_IN_PROGRESS . '.');
        }

        $worktree = $this->prepareAgentWorktree($agent);
        $this->checkoutBranchInWorktree($worktree, $match['entry']->getMeta('branch') ?? '', false);
        $this->runReviewScript($worktree);

        $board->moveFeature($feature, BacklogBoard::SECTION_IN_REVIEW);
        $board->save();

        $this->console->ok(sprintf('Feature %s moved to %s', $feature, BacklogBoard::SECTION_IN_REVIEW));

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

        $reviewWorktree = $this->prepareReviewerWorktree();
        $this->checkoutDetachedInWorktree($reviewWorktree, $match['entry']->getMeta('branch') ?? '');

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

        if ($match['section'] !== BacklogBoard::SECTION_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::SECTION_IN_REVIEW . ' to be rejected.');
        }

        $board->moveFeature($feature, BacklogBoard::SECTION_REJECTED);
        $review->setFeatureReview($feature, $this->numberedReviewItems($bodyFile));
        $board->save();
        $review->save();

        $this->console->ok(sprintf(
            '%sfeature %s moved to %s',
            $auto ? 'Automatically rejected ' : 'Rejected ',
            $feature,
            BacklogBoard::SECTION_REJECTED,
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

        if ($match['section'] !== BacklogBoard::SECTION_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::SECTION_IN_REVIEW . ' to be approved.');
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

        $board->moveFeature($feature, BacklogBoard::SECTION_APPROVED);
        $review->clearFeatureReview($feature);
        $board->save();
        $review->save();

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
        $board->save();
        $review->save();

        $this->console->ok(sprintf('Closed feature %s without merge', $feature));

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

        if ($match['section'] !== BacklogBoard::SECTION_APPROVED) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::SECTION_APPROVED . ' before merge.');
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
        $this->runCommand('git checkout main');
        $this->runCommand('git pull');
        $this->runCommand(sprintf('git push origin --delete %s', escapeshellarg($branch)));
        $this->runCommand(sprintf('git branch -d %s', escapeshellarg($branch)));

        $board->removeFeature($feature);
        $board->clearReservations($match['entry']->getMeta('agent') ?? '', $feature);
        $review->clearFeatureReview($feature);
        $board->save();
        $review->save();

        $this->console->ok(sprintf('Merged feature %s', $feature));

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

    private function prepareAgentWorktree(string $agent): string
    {
        $path = $this->projectRoot . '/.worktrees/' . $agent;
        $relativePath = $this->toRelativeProjectPath($path);

        if (!is_dir($path . '/.git') && !is_file($path . '/.git')) {
            $this->runCommand(sprintf('git worktree add --detach %s HEAD', escapeshellarg($relativePath)));
        }

        $status = trim($this->capture($this->gitInPath($path, 'status --short')));
        if ($status !== '') {
            throw new \RuntimeException("Agent worktree is dirty: {$path}");
        }

        return $path;
    }

    private function prepareReviewerWorktree(): string
    {
        $path = $this->projectRoot . '/.worktrees/reviewer';
        $relativePath = $this->toRelativeProjectPath($path);

        if (!is_dir($path . '/.git') && !is_file($path . '/.git')) {
            $this->runCommand(sprintf('git worktree add --detach %s HEAD', escapeshellarg($relativePath)));
        }

        $status = trim($this->capture($this->gitInPath($path, 'status --short')));
        if ($status !== '') {
            throw new \RuntimeException("Reviewer worktree is dirty: {$path}");
        }

        return $path;
    }

    private function checkoutBranchInWorktree(string $worktree, string $branch, bool $create): void
    {
        if ($branch === '') {
            throw new \RuntimeException('Missing branch name.');
        }

        $this->releaseBranchFromOtherWorktrees($branch, $worktree);

        if ($create) {
            $this->runCommand($this->gitInPath(
                $worktree,
                sprintf('checkout -B %s main', escapeshellarg($branch)),
            ));
            return;
        }

        $hasLocal = $this->commandSucceeds($this->gitInPath(
            $worktree,
            sprintf('rev-parse --verify %s', escapeshellarg($branch)),
        ));
        if ($hasLocal) {
            $this->runCommand($this->gitInPath(
                $worktree,
                sprintf('checkout %s', escapeshellarg($branch)),
            ));
            return;
        }

        $this->runCommand($this->gitInPath(
            $worktree,
            sprintf('checkout -B %s origin/%s', escapeshellarg($branch), escapeshellarg($branch)),
        ));
    }

    private function checkoutDetachedInWorktree(string $worktree, string $branch): void
    {
        if ($branch === '') {
            throw new \RuntimeException('Missing branch name.');
        }

        $this->runCommand($this->gitInPath(
            $worktree,
            sprintf('checkout --detach %s', escapeshellarg($branch)),
        ));
    }

    private function releaseBranchFromOtherWorktrees(string $branch, string $keepWorktree): void
    {
        $output = $this->capture('git worktree list --porcelain');
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

            $dirty = trim($this->capture($this->gitInPath($path, 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException("Branch {$branch} is still active in a dirty worktree: {$path}");
            }

            if (!str_starts_with($path, $this->projectRoot . '/.worktrees/')) {
                throw new \RuntimeException("Branch {$branch} is active in a non-managed worktree: {$path}");
            }

            $this->runCommand(sprintf('git worktree remove %s --force', escapeshellarg($this->toRelativeProjectPath($path))));
        }
    }

    private function ensureBranchHasNoDirtyManagedWorktree(string $branch): void
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if ($binding['branch'] !== $branch) {
                continue;
            }

            $dirty = trim($this->capture($this->gitInPath($binding['path'], 'status --short')));
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
        if (!$this->commandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)))) {
            return;
        }

        if (!$this->commandSucceeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/remotes/origin/' . $branch)))) {
            $this->pushBranchAndWaitForRemoteVisibility($branch);

            return;
        }

        $ahead = trim($this->capture(sprintf(
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
        $output = $this->capture('git worktree list --porcelain');
        $blocks = preg_split('/\n\n/', trim($output)) ?: [];
        $bindings = [];

        foreach ($blocks as $block) {
            $path = null;
            $branch = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                    continue;
                }
                if (str_starts_with($line, 'branch refs/heads/')) {
                    $branch = substr($line, strlen('branch refs/heads/'));
                }
            }

            if ($path !== null) {
                $bindings[] = ['path' => $path, 'branch' => $branch];
            }
        }

        return $bindings;
    }

    private function runReviewScript(string $worktree): void
    {
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

        $files = array_values(array_filter(explode("\n", trim($this->capture(sprintf(
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

    private function buildCurrentTitle(BoardEntry $entry, string $section): string
    {
        $type = $section === BacklogBoard::SECTION_APPROVED
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

        for ($attempt = 1; $attempt <= self::REMOTE_BRANCH_WAIT_ATTEMPTS; $attempt++) {
            [$code, $output] = $this->captureGithubCommandWithRetry($command);
            if ($code === 0) {
                return;
            }

            if (!$this->isHeadInvalidCreateError($output) || $attempt === self::REMOTE_BRANCH_WAIT_ATTEMPTS) {
                throw new \RuntimeException(sprintf(
                    "Command failed with exit code %d: %s\n%s",
                    $code,
                    $command,
                    $output,
                ));
            }

            usleep(self::REMOTE_BRANCH_WAIT_DELAY_MICROSECONDS);
            $this->waitForRemoteBranchVisibility($branch);
        }
    }

    private function pushBranchAndWaitForRemoteVisibility(string $branch, ?string $worktree = null): void
    {
        $gitPrefix = $worktree !== null
            ? sprintf('git -C %s', escapeshellarg($this->toRelativeProjectPath($worktree)))
            : 'git';

        $this->runCommand(sprintf(
            '%s push -u origin %s',
            $gitPrefix,
            escapeshellarg($branch),
        ));

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
        for ($attempt = 1; $attempt <= self::REMOTE_BRANCH_WAIT_ATTEMPTS; $attempt++) {
            if ($this->isRemoteBranchVisible($branch)) {
                return;
            }

            if ($attempt < self::REMOTE_BRANCH_WAIT_ATTEMPTS) {
                usleep(self::REMOTE_BRANCH_WAIT_DELAY_MICROSECONDS);
            }
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    private function isRemoteBranchVisible(string $branch): bool
    {
        [$code, $output] = $this->captureWithExitCode(sprintf(
            'git ls-remote --heads origin %s',
            escapeshellarg($branch),
        ));

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

        $output = $this->captureGithubOutputWithRetry('php scripts/github.php pr list');
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
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function nextStepForSection(string $section): string
    {
        return match ($section) {
            BacklogBoard::SECTION_IN_PROGRESS => 'feature-review-request',
            BacklogBoard::SECTION_IN_REVIEW => 'feature-review-check or feature-review-approve',
            BacklogBoard::SECTION_REJECTED => 'feature-rework',
            BacklogBoard::SECTION_APPROVED => 'feature-merge',
            default => '-',
        };
    }

    private function runCommand(string $command): void
    {
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

    private function runGithubCommand(string $command): void
    {
        [$code, $output] = $this->captureGithubCommandWithRetry($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function captureGithubOutputWithRetry(string $command): string
    {
        [$code, $output] = $this->captureGithubCommandWithRetry($command);
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

    /**
     * Runs one GitHub command with retry on transient transport failures.
     *
     * @return array{0: int, 1: string}
     */
    private function captureGithubCommandWithRetry(string $command): array
    {
        $lastResult = [0, ''];

        for ($attempt = 0; $attempt <= self::GITHUB_NETWORK_RETRY_COUNT; $attempt++) {
            $lastResult = $this->captureWithExitCode($command);
            if ($lastResult[0] === 0) {
                return $lastResult;
            }

            if (!$this->isRetryableGithubNetworkError($lastResult[1])) {
                return $lastResult;
            }

            if ($attempt === self::GITHUB_NETWORK_RETRY_COUNT) {
                throw new \RuntimeException(sprintf(
                    "GitHub network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
                    self::GITHUB_NETWORK_RETRY_COUNT,
                    $command,
                    $lastResult[1],
                ));
            }

            usleep(self::GITHUB_NETWORK_INITIAL_DELAY_MICROSECONDS * (2 ** $attempt));
        }

        return $lastResult;
    }

    private function isRetryableGithubNetworkError(string $output): bool
    {
        foreach (self::GITHUB_NETWORK_ERROR_NEEDLES as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
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
        if ($pr === null || $pr === '') {
            return null;
        }

        return (int) $pr;
    }
}
