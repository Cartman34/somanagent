<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogHandlerFactory;
use SoManAgent\Script\Backlog\BacklogCommandHelp;
use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCliOption;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\ExternalWorktree;
use SoManAgent\Script\Backlog\ManagedWorktree;
use SoManAgent\Script\Backlog\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\WorktreeAction;
use SoManAgent\Script\Backlog\WorktreeState;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\PullRequestTag;
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\Client\ProjectScriptClient;
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
    private const DEFAULT_BOARD_PATH = 'local/backlog-board.md';
    private const DEFAULT_REVIEW_FILE_PATH = 'local/backlog-review.md';
    private const WA_BACKEND_ENV_LOCAL_FALLBACK = "DATABASE_URL=\"postgresql://somanagent:secret@localhost:5432/somanagent?serverVersion=16&charset=utf8\"\n";
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

    private ?BacklogCommandHelp $commandHelp = null;
    private ?BacklogHandlerFactory $handlerFactory = null;
    private ?BacklogEntryResolver $entryResolver = null;
    private ?BacklogEntryService $entryService = null;
    private ?ConsoleClient $consoleClient = null;
    private ?GitClient $gitClient = null;
    private ?ProjectScriptClient $projectScriptClient = null;
    private ?GitHubClient $gitHubClient = null;
    private ?BacklogGitWorkflow $gitWorkflow = null;
    private ?BacklogWorktreeManager $worktreeManager = null;
    private ?PullRequestManager $pullRequestManager = null;
    private ?BacklogReviewBodyFormatter $reviewBodyFormatter = null;
    private ?string $boardPath = null;
    private ?string $reviewFilePath = null;
    private ?string $prBaseBranchOverride = null;

    protected function getDescription(): string
    {
        return 'Backlog workflow helper for local developer and reviewer procedures';
    }

    protected function getCommands(): array
    {
        return $this->commandHelp()->getCommands();
    }

    protected function getOptions(): array
    {
        return $this->commandHelp()->getOptions($this->getExecutionModeOptions());
    }

    protected function getUsageExamples(): array
    {
        return $this->commandHelp()->getUsageExamples();
    }

    /**
     * Executes one backlog workflow command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs($args);
        $command = array_shift($parsedArgs) ?? '';
        $commandArgs = $parsedArgs;
        $this->configureExecutionModes($options);
        $this->configureTestFileOverrides($options);

        if ($command === '') {
            $this->printHelp();

            return 0;
        }

        if ($command === BacklogCommandName::HELP->value) {
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

        try {
            return match ($command) {
                BacklogCommandName::STATUS->value => $this->handleCommand($command, $commandArgs, $options),
                BacklogCommandName::TASK_CREATE->value => $this->createTask($commandArgs, $options),
                BacklogCommandName::TASK_TODO_LIST->value => $this->taskTodoList(),
                BacklogCommandName::TASK_REMOVE->value => $this->taskRemove($commandArgs),
                BacklogCommandName::REVIEW_NEXT->value => $this->reviewNext(),
                BacklogCommandName::TASK_REVIEW_REQUEST->value => $this->taskReviewRequest($commandArgs, $options),
                BacklogCommandName::TASK_REVIEW_CHECK->value => $this->taskReviewCheck($commandArgs),
                BacklogCommandName::TASK_REVIEW_REJECT->value => $this->taskReviewReject($commandArgs, $options),
                BacklogCommandName::TASK_REVIEW_APPROVE->value => $this->taskReviewApprove($commandArgs),
                BacklogCommandName::TASK_REWORK->value => $this->taskRework($commandArgs, $options),
                BacklogCommandName::FEATURE_START->value => $this->featureStart($commandArgs, $options),
                BacklogCommandName::FEATURE_RELEASE->value => $this->featureRelease($commandArgs, $options),
                BacklogCommandName::FEATURE_TASK_ADD->value => $this->featureTaskAdd($commandArgs, $options),
                BacklogCommandName::FEATURE_TASK_MERGE->value => $this->featureTaskMerge($commandArgs, $options),
                BacklogCommandName::FEATURE_ASSIGN->value => $this->featureAssign($commandArgs, $options),
                BacklogCommandName::FEATURE_UNASSIGN->value => $this->featureUnassign($commandArgs, $options),
                BacklogCommandName::FEATURE_REWORK->value => $this->featureRework($commandArgs, $options),
                BacklogCommandName::FEATURE_BLOCK->value => $this->featureBlock($commandArgs, $options),
                BacklogCommandName::FEATURE_UNBLOCK->value => $this->featureUnblock($commandArgs, $options),
                BacklogCommandName::FEATURE_LIST->value => $this->featureList(),
                BacklogCommandName::WORKTREE_LIST->value => $this->handleCommand($command, $commandArgs, $options),
                BacklogCommandName::WORKTREE_CLEAN->value => $this->worktreeClean(),
                BacklogCommandName::WORKTREE_RESTORE->value => $this->worktreeRestore($commandArgs, $options),
                BacklogCommandName::FEATURE_REVIEW_REQUEST->value => $this->featureReviewRequest($commandArgs, $options),
                BacklogCommandName::FEATURE_REVIEW_CHECK->value => $this->featureReviewCheck($commandArgs),
                BacklogCommandName::FEATURE_REVIEW_REJECT->value => $this->featureReviewReject($commandArgs, $options),
                BacklogCommandName::FEATURE_REVIEW_APPROVE->value => $this->featureReviewApprove($commandArgs, $options),
                BacklogCommandName::FEATURE_CLOSE->value => $this->featureClose($commandArgs),
                BacklogCommandName::FEATURE_MERGE->value => $this->featureMerge($commandArgs, $options),
                default => throw new \RuntimeException("Unknown command: {$command}"),
            };
        } catch (\Exception $e) {
            $this->console->fail($e->getMessage());

            return 1;
        }
    }

    private function handleCommand(string $command, array $commandArgs, array $options): int
    {
        $this->handlerFactory()->createHandler($command)->handle($commandArgs, $options);

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function configureTestFileOverrides(array $options): void
    {
        $boardFile = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::BOARD_FILE->value] ?? ''));
        $reviewFile = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::REVIEW_FILE->value] ?? ''));
        $prBaseBranch = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::PR_BASE_BRANCH->value] ?? ''));

        if ($boardFile === null && $reviewFile === null && $prBaseBranch === null) {
            return;
        }

        if (!isset($options[BacklogCliOption::TEST_MODE->value])) {
            throw new \RuntimeException('backlog test file overrides require --test-mode.');
        }

        if ($boardFile !== null) {
            $this->boardPath = $this->validateTestFileOverride($boardFile, BacklogCliOption::BOARD_FILE->value);
        }

        if ($reviewFile !== null) {
            $this->reviewFilePath = $this->validateTestFileOverride($reviewFile, BacklogCliOption::REVIEW_FILE->value);
        }
        if ($prBaseBranch !== null) {
            $this->prBaseBranchOverride = $prBaseBranch;
        }
    }

    private function validateTestFileOverride(string $path, string $option): string
    {
        $absolutePath = str_starts_with($path, '/')
            ? $path
            : $this->projectRoot . '/' . ltrim($path, '/');

        $normalizedPath = str_replace('\\', '/', $absolutePath);
        $allowedPrefix = str_replace('\\', '/', $this->projectRoot . '/local/tmp/');
        if (!str_starts_with($normalizedPath, $allowedPrefix)) {
            throw new \RuntimeException(sprintf(
                '--%s must point inside local/tmp/ when --test-mode is enabled.',
                $option,
            ));
        }

        if (!is_file($absolutePath)) {
            throw new \RuntimeException(sprintf(
                'Test file not found for --%s: %s',
                $option,
                $path,
            ));
        }

        return $absolutePath;
    }

    private function printCommandHelp(string $command): void
    {
        echo $this->commandHelp()->renderCommandHelp($command);
    }

    private function prBaseBranch(): string
    {
        return $this->prBaseBranchOverride ?? BacklogGitWorkflow::MAIN_BRANCH;
    }

    private function commandHelp(): BacklogCommandHelp
    {
        if ($this->commandHelp === null) {
            $this->commandHelp = new BacklogCommandHelp();
        }

        return $this->commandHelp;
    }

    private function handlerFactory(): BacklogHandlerFactory
    {
        if ($this->handlerFactory === null) {
            $this->handlerFactory = new BacklogHandlerFactory(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager(),
                $this->entryService(),
                $this->entryResolver(),
                $this->consoleClient(),
                $this->featureSlugger(),
                $this->boardPath(),
                $this->reviewFilePath()
            );
        }

        return $this->handlerFactory;
    }

    private function boardPath(): string
    {
        return $this->boardPath ?? ($this->projectRoot . '/' . self::DEFAULT_BOARD_PATH);
    }

    private function reviewFilePath(): string
    {
        return $this->reviewFilePath ?? ($this->projectRoot . '/' . self::DEFAULT_REVIEW_FILE_PATH);
    }

    private function entryResolver(): BacklogEntryResolver
    {
        if ($this->entryResolver === null) {
            $this->entryResolver = new BacklogEntryResolver($this->featureSlugger());
        }

        return $this->entryResolver;
    }

    private function entryService(): BacklogEntryService
    {
        if ($this->entryService === null) {
            $this->entryService = new BacklogEntryService(
                $this->featureSlugger(),
                $this->entryResolver(),
            );
        }

        return $this->entryService;
    }

    private function consoleClient(): ConsoleClient
    {
        if ($this->consoleClient === null) {
            $this->consoleClient = new ConsoleClient(
                $this->projectRoot,
                $this->dryRun,
                $this->app,
                function (string $message): void {
                    $this->logVerbose($message);
                },
            );
        }

        return $this->consoleClient;
    }

    private function gitClient(): GitClient
    {
        if ($this->gitClient === null) {
            $this->gitClient = new GitClient(
                $this->dryRun,
                $this->consoleClient(),
                self::NETWORK_ERROR_NEEDLES,
                self::RETRY_COUNT,
                self::RETRY_BASE_DELAY,
                self::RETRY_FACTOR,
            );
        }

        return $this->gitClient;
    }

    private function projectScriptClient(): ProjectScriptClient
    {
        if ($this->projectScriptClient === null) {
            $this->projectScriptClient = new ProjectScriptClient($this->consoleClient());
        }

        return $this->projectScriptClient;
    }

    private function gitHubClient(): GitHubClient
    {
        if ($this->gitHubClient === null) {
            $this->gitHubClient = new GitHubClient(
                $this->dryRun,
                $this->projectScriptClient(),
                self::NETWORK_ERROR_NEEDLES,
                self::RETRY_COUNT,
                self::RETRY_BASE_DELAY,
                self::RETRY_FACTOR,
            );
        }

        return $this->gitHubClient;
    }

    private function worktreeManager(): BacklogWorktreeManager
    {
        if ($this->worktreeManager === null) {
            $this->worktreeManager = new BacklogWorktreeManager(
                $this->projectRoot,
                $this->dryRun,
                self::WA_BACKEND_ENV_LOCAL_FALLBACK,
                $this->entryResolver(),
                $this->consoleClient(),
                $this->gitClient(),
                $this->projectScriptClient(),
            );
        }

        return $this->worktreeManager;
    }

    private function gitWorkflow(): BacklogGitWorkflow
    {
        if ($this->gitWorkflow === null) {
            $this->gitWorkflow = new BacklogGitWorkflow(
                $this->dryRun,
                $this->consoleClient(),
                $this->console,
                $this->gitClient(),
                $this->pullRequestManager(),
                function (string $message): void {
                    $this->logVerbose($message);
                },
            );
        }

        return $this->gitWorkflow;
    }

    private function pullRequestManager(): PullRequestManager
    {
        if ($this->pullRequestManager === null) {
            $this->pullRequestManager = new PullRequestManager(
                $this->dryRun,
                self::PR_CREATE_HEAD_INVALID_NEEDLE,
                $this->gitClient(),
                $this->gitHubClient(),
                self::RETRY_COUNT,
                self::RETRY_BASE_DELAY,
                self::RETRY_FACTOR,
            );
        }

        return $this->pullRequestManager;
    }

    private function reviewBodyFormatter(): BacklogReviewBodyFormatter
    {
        if ($this->reviewBodyFormatter === null) {
            $this->reviewBodyFormatter = new BacklogReviewBodyFormatter();
        }

        return $this->reviewBodyFormatter;
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
        array_splice($entries, $position, 0, [$this->entryService()->createTaskEntryFromInput($text)]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, BacklogCommandName::TASK_CREATE->value);

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
     * Removes one queued to-do task by its 1-based displayed number.
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
        $this->saveBoard($board, BacklogCommandName::TASK_REMOVE->value);

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

        if ($this->entryResolver()->getSingleTaskForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active task.");
        }

        $target = $this->entryService()->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No backlog task available to start.');
        }
        $reserved = [$target];

        $this->logVerbose(sprintf(
            'feature-start: selected=1 todo-before=%d active-before=%d',
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
        ));

        $worktree = $this->worktreeManager()->prepareAgentWorktree($agent);
        $first = $reserved[0]->getEntry();
        $first->setFeature(null);
        $first->setAgent(null);
        $scopedTask = $this->entryService()->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            $task = $scopedTask['task'];
            $parent = $this->entryResolver()->findParentFeatureEntry($board, $scopedTask['featureGroup']);

            if ($parent === null) {
                $branchType = $this->entryService()->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
                $featureBranch = $branchType . '/' . $scopedTask['featureGroup'];
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->gitWorkflow()->updateMainBeforeFeatureStart();
                $featureBase = $this->gitWorkflow()->originMainHead();
                $this->worktreeManager()->ensureLocalBranchExists($featureBranch, BacklogGitWorkflow::ORIGIN_REMOTE . '/' . BacklogGitWorkflow::MAIN_BRANCH);

                $featureEntry = new BoardEntry($scopedTask['text'], [], [
                    BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_FEATURE,
                    BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                    BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
                    BoardEntry::META_AGENT => $agent,
                    BoardEntry::META_BRANCH => $featureBranch,
                    BoardEntry::META_BASE => $featureBase,
                    BoardEntry::META_PR => BacklogMetaValue::NONE->value,
                ]);
                $activeEntries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $activeEntries[] = $featureEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);
                $parent = $this->entryResolver()->requireParentFeature($board, $scopedTask['featureGroup']);
            } else {
                $branchType = $this->entryService()->resolveFeatureStartBranchType($first, $parent->getEntry(), $branchTypeOverride);
                $featureBranch = $parent->getEntry()->getBranch() ?: ($branchType . '/' . $scopedTask['featureGroup']);
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->entryService()->invalidateFeatureReviewState($parent->getEntry());
            }
            $this->entryService()->assertTaskSlugAvailableForFeature($board, $parent->getEntry(), $scopedTask['featureGroup'], $task, BacklogCommandName::FEATURE_START->value);

            $taskBase = $this->gitWorkflow()->branchHead($featureBranch);
            $this->worktreeManager()->requireLocalBranchExists($featureBranch, BacklogCommandName::FEATURE_START->value);
            $this->worktreeManager()->checkoutBranchInWorktree($worktree, $branch, true, $featureBranch);

            $taskEntry = new BoardEntry($scopedTask['text'], $first->getExtraLines(), [
                BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_TASK,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
                BoardEntry::META_TASK => $task,
                BoardEntry::META_AGENT => $agent,
                BoardEntry::META_BRANCH => $branch,
                BoardEntry::META_FEATURE_BRANCH => $featureBranch,
                BoardEntry::META_BASE => $taskBase,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
            $this->entryService()->appendTaskContribution($parent->getEntry(), $taskEntry);
            $featureEntry = $taskEntry;
        } else {
            $branchType = $this->entryService()->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
            $feature = $this->entryService()->normalizeFeatureSlug($first->getText());
            $this->gitWorkflow()->updateMainBeforeFeatureStart();
            $base = $this->gitWorkflow()->originMainHead();
            $branch = $branchType . '/' . $feature;
            $this->worktreeManager()->checkoutBranchInWorktree($worktree, $branch, true);

            $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines(), [
                BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_FEATURE,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $feature,
                BoardEntry::META_AGENT => $agent,
                BoardEntry::META_BRANCH => $branch,
                BoardEntry::META_BASE => $base,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
        }

        foreach (array_slice($reserved, 1) as $task) {
            $reservedEntry = $task->getEntry();
            $reservedEntry->setFeature(null);
            $reservedEntry->setAgent(null);
            $featureEntry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $featureEntry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->entryService()->removeReservedTasks($board, $reserved);
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);
        $this->logVerbose(sprintf(
            'feature-start: feature=%s todo-after-remove=%d active-after-add=%d active-stage=%s',
            (string) ($featureEntry->getTask() ?? $featureEntry->getFeature()),
            count($board->getEntries(BacklogBoard::SECTION_TODO)),
            count($board->getEntries(BacklogBoard::SECTION_ACTIVE)),
            (string) $featureEntry->getStage(),
        ));
        $this->saveBoard($board, BacklogCommandName::FEATURE_START->value);

        $this->console->ok(sprintf(
            'Started %s %s on %s',
            $this->entryService()->entryKind($featureEntry),
            $featureEntry->getTask() ?? $featureEntry->getFeature() ?? '-',
            $branch,
        ));

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
        $requestedTarget = BoardEntry::parseEmptyString($commandArgs[0] ?? null);
        if ($requestedTarget !== null) {
            $target = $this->entryService()->normalizeFeatureSlug($requestedTarget);
            $task = $this->entryResolver()->getSingleTaskForAgent($board, $agent, false);
            if ($task !== null && $task->getTask() === $target) {
                $current = $this->entryResolver()->requireSingleTaskForAgent($board, $agent);
            } else {
                $current = $this->entryResolver()->requireSingleFeatureForAgent($board, $agent);
                if ($current->getEntry()->getFeature() !== $target) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s has no active feature or task matching %s.',
                        $agent,
                        $target,
                    ));
                }
            }
        } else {
            $current = $this->entryResolver()->findTaskEntriesByAgent($board, $agent)[0] ?? $this->entryResolver()->requireSingleFeatureForAgent($board, $agent);
        }
        $entry = $current->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException('Active entry has no branch metadata.');
        }

        if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException('Active entry must be in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . ' to be released.');
        }
        if (!$this->featureHasNoDevelopment($entry)) {
            throw new \RuntimeException('Active entry already has development work and cannot be released back to todo.');
        }

        if ($this->entryService()->isTaskEntry($entry)) {
            $feature = $entry->getFeature() ?? '';
            $task = $entry->getTask() ?? '';
            $parent = $this->entryResolver()->requireParentFeature($board, $feature);
            $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
            array_unshift($todoEntries, new BoardEntry(
                sprintf('[%s][%s] %s', $feature, $task, $entry->getText()),
                $entry->getExtraLines(),
            ));
            $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
            $this->entryService()->removeActiveEntryAt($board, $current->getIndex());
            $hasFeatureContent = $this->entryService()->removeTaskContribution($parent->getEntry(), $entry);
            if (!$hasFeatureContent && !$this->featureHasNoDevelopment($parent->getEntry())) {
                throw new \RuntimeException("Parent feature {$feature} still has development work and cannot be removed.");
            }
            if (!$hasFeatureContent) {
                $this->entryService()->removeActiveEntryAt($board, $parent->getIndex());
                $this->gitWorkflow()->deleteLocalBranchIfExists($parent->getEntry()->getBranch());
            }
            $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);
            $cleaned = $this->worktreeManager()->cleanupManagedWorktreesForBranch($branch, $board);
            $this->gitWorkflow()->deleteLocalBranchIfExists($branch);

            $this->console->ok(sprintf('Released task %s back to todo', $task));
            if ($cleaned > 0) {
                $this->console->line(sprintf('Cleaned %d managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
            }

            return 0;
        }

        $feature = $entry->getFeature() ?? '';
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_RELEASE->value);
        $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
        array_unshift($todoEntries, new BoardEntry($entry->getText(), $entry->getExtraLines()));
        $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
        $board->removeFeature($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);

        $cleaned = $this->worktreeManager()->cleanupManagedWorktreesForBranch($branch, $board);
        $this->gitWorkflow()->deleteLocalBranchIfExists($branch);

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
        $agent = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::AGENT->value] ?? ''));
        if ($agent !== null) {
            $match = isset($commandArgs[0])
                ? $this->entryResolver()->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value)
                : $this->entryResolver()->requireSingleTaskForAgent($board, $agent);
        } else {
            if (BoardEntry::parseEmptyString($commandArgs[0] ?? null) === null) {
                throw new \RuntimeException('feature-task-merge requires <feature/task> when used without --agent.');
            }

            $match = $this->entryResolver()->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value);
        }
        if ($match === null) {
            throw new \RuntimeException('No task available for feature-task-merge.');
        }

        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::FEATURE_TASK_MERGE->value);
        if ($agent !== null && $entry->getAgent() !== $agent) {
            throw new \RuntimeException('feature-task-merge requires the task to be assigned to the provided agent.');
        }
        $taskAgent = $entry->getAgent() ?? '';

        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';
        $featureBranch = $entry->getFeatureBranch() ?? '';
        $taskBranch = $entry->getBranch() ?? '';
        $parent = $this->entryResolver()->requireParentFeature($board, $feature);
        $taskWorktree = $this->worktreeManager()->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager()->runReviewScript($taskWorktree, $entry->getBase());
        $this->worktreeManager()->ensureBranchHasNoDirtyManagedWorktree($taskBranch);
        $mergeContext = $this->worktreeManager()->prepareFeatureMergeWorktree($featureBranch, $feature);

        try {
            $this->gitWorkflow()->mergeBranchInPath(
                $mergeContext['path'],
                $taskBranch,
                sprintf('Merge task %s into feature %s', $task, $feature),
            );
        } catch (\Throwable $exception) {
            if ($mergeContext['temporary']) {
                $this->worktreeManager()->removeTemporaryMergeWorktree($mergeContext['path']);
            }

            throw $exception;
        }

        $this->entryService()->removeActiveEntryAt($board, $match->getIndex());
        if ($parent->getEntry()->getAgent() === null) {
            $parent->getEntry()->setAgent($taskAgent);
        }
        $this->entryService()->invalidateFeatureReviewState($parent->getEntry());
        $review->clearReview($this->entryService()->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_TASK_MERGE->value);

        if ($mergeContext['temporary']) {
            $this->worktreeManager()->removeTemporaryMergeWorktree($mergeContext['path']);
        }

        $this->worktreeManager()->cleanupMergedTaskWorktree($taskAgent, $taskBranch, $board);

        $this->gitWorkflow()->deleteLocalBranchIfExists($taskBranch);

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
            ? $this->entryResolver()->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REVIEW_REQUEST->value)
            : $this->entryResolver()->requireSingleTaskForAgent($board, $agent);
        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::TASK_REVIEW_REQUEST->value);
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('task-review-request requires the task to be assigned to the provided agent.');
        }

        $taskWorktree = $this->worktreeManager()->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager()->runReviewScript($taskWorktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $review->clearReview($this->entryService()->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REQUEST->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REQUEST->value);

        $this->console->ok(sprintf(
            'Task %s moved to %s',
            $this->entryService()->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));

        return 0;
    }

    private function reviewNext(): int
    {
        $board = $this->board();
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $this->printEntryStatus($entry);

            return 0;
        }

        throw new \RuntimeException('No task or feature available in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }

    /**
     * @param array<string> $commandArgs
     */
    private function taskReviewCheck(array $commandArgs): int
    {
        $board = $this->board();
        $match = $this->entryResolver()->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_CHECK->value);
        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::TASK_REVIEW_CHECK->value);

        if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be checked.',
                $this->entryService()->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->worktreeManager()->prepareFeatureAgentWorktree($entry);

        try {
            $this->worktreeManager()->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the task again.';
            $this->taskReviewReject([$this->entryService()->taskReviewKey($entry)], [BacklogCliOption::BODY_FILE->value => $this->writeTempContent([$message])], true);
            throw $exception;
        }

        $this->console->ok(sprintf('Mechanical review passed for task %s', $this->entryService()->taskReviewKey($entry)));

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
        $match = $this->entryResolver()->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_REJECT->value);
        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::TASK_REVIEW_REJECT->value);

        if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be rejected.',
                $this->entryService()->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $review->setReview($this->entryService()->taskReviewKey($entry), $this->reviewBodyFormatter()->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REJECT->value);

        $this->console->ok(sprintf(
            '%stask %s moved to %s',
            $auto ? 'Automatically rejected ' : 'Rejected ',
            $this->entryService()->taskReviewKey($entry),
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
        $match = $this->entryResolver()->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::TASK_REVIEW_APPROVE->value);

        if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be approved.',
                $this->entryService()->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_APPROVED);
        $review->clearReview($this->entryService()->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_APPROVE->value);

        $this->console->ok(sprintf('Approved task %s', $this->entryService()->taskReviewKey($entry)));

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
            ? $this->entryResolver()->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REWORK->value)
            : $this->entryResolver()->requireSingleTaskForAgent($board, $agent);
        $entry = $match->getEntry();
        $this->entryService()->assertTaskEntry($entry, BacklogCommandName::TASK_REWORK->value);

        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('task-rework requires the task to be assigned to the provided agent.');
        }

        if ($this->entryService()->featureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException(sprintf(
                'Task %s is not in the rejected stage.',
                $this->entryService()->taskReviewKey($entry),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::TASK_REWORK->value);

        $taskWorktree = $this->worktreeManager()->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager()->checkoutBranchInWorktree($taskWorktree, $entry->getBranch() ?? '', false);

        $this->console->ok(sprintf(
            'Moved task %s back to %s',
            $this->entryService()->taskReviewKey($entry),
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
        $featureText = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::FEATURE_TEXT->value] ?? ''));
        if ($featureText === null) {
            throw new \RuntimeException('feature-task-add requires --feature-text.');
        }

        $board = $this->board();
        $current = $this->entryResolver()->requireSingleFeatureForAgent($board, $agent);
        $feature = $current->getEntry()->getFeature();
        $target = $this->entryService()->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No queued task available to add to the current feature.');
        }
        $reserved = [$target];

        $entry = $current->getEntry();
        $this->entryService()->assertFeatureEntry($entry, BacklogCommandName::FEATURE_TASK_ADD->value);
        $entry->setText($featureText);
        $this->entryService()->invalidateFeatureReviewState($entry);

        foreach ($reserved as $task) {
            $reservedEntry = $task->getEntry();
            $reservedEntry->setFeature(null);
            $reservedEntry->setAgent(null);
            $scopedTask = $this->entryService()->extractScopedTaskMetadata($reservedEntry->getText());

            if ($scopedTask !== null) {
                if ($scopedTask['featureGroup'] !== $feature) {
                    throw new \RuntimeException(sprintf(
                        'Next queued task belongs to feature %s, not %s.',
                        $scopedTask['featureGroup'],
                        $feature,
                    ));
                }
                if ($this->entryResolver()->getSingleTaskForAgent($board, $agent, false) !== null) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s already owns an active task. Merge or release it before feature-task-add.',
                        $agent,
                    ));
                }

                $featureBranch = $entry->getBranch();
                $branchType = $this->entryService()->detectBranchType($featureBranch);
                if ($featureBranch === null || $branchType === '') {
                    throw new \RuntimeException('Current feature metadata is incomplete: missing branch information.');
                }
                $this->entryService()->assertTaskSlugAvailableForFeature($board, $entry, (string) $feature, $scopedTask['task'], BacklogCommandName::FEATURE_TASK_ADD->value);

                $taskBranch = $branchType . '/' . $feature . '--' . $scopedTask['task'];
                $taskBase = $this->gitWorkflow()->branchHead($featureBranch);

                $worktree = $this->worktreeManager()->prepareAgentWorktree($agent);
                $this->worktreeManager()->checkoutBranchInWorktree($worktree, $taskBranch, true, $featureBranch);

                $taskEntry = new BoardEntry($scopedTask['text'], $reservedEntry->getExtraLines(), [
                    BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_TASK,
                    BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                    BoardEntry::META_FEATURE => $feature,
                    BoardEntry::META_TASK => $scopedTask['task'],
                    BoardEntry::META_AGENT => $agent,
                    BoardEntry::META_BRANCH => $taskBranch,
                    BoardEntry::META_FEATURE_BRANCH => $featureBranch,
                    BoardEntry::META_BASE => $taskBase,
                    BoardEntry::META_PR => BacklogMetaValue::NONE->value,
                ]);
                $this->entryService()->appendTaskContribution($entry, $taskEntry);

                $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $entries[] = $taskEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);

                continue;
            }

            if ($this->entryService()->featureContributionBlocks($entry) !== [] || $this->entryResolver()->findTaskEntriesByFeature($board, (string) $feature) !== []) {
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

        $this->entryService()->removeReservedTasks($board, $reserved);

        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_ADD->value);
        $bodyFile = isset($options[BacklogCliOption::BODY_FILE->value])
            ? $this->requireBodyFile($options)
            : null;
        if ($bodyFile !== null) {
            $this->pullRequestManager()->updatePrBodyIfExists($entry->getBranch() ?? '', $bodyFile);
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

        if ($this->entryResolver()->getSingleFeatureForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active feature.");
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        $previousAgent = $match->getEntry()->getAgent();
        $match->getEntry()->setAgent($agent);
        $this->saveBoard($board, BacklogCommandName::FEATURE_ASSIGN->value);

        $worktree = $this->worktreeManager()->prepareAgentWorktree($agent);
        $this->worktreeManager()->checkoutBranchInWorktree($worktree, $match->getEntry()->getBranch() ?? '', false);
        $cleaned = $previousAgent !== null && $previousAgent !== $agent
            ? $this->worktreeManager()->cleanupAbandonedManagedWorktrees($board)
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
            ? $this->entryService()->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();
        $actorAgent = $actorRole === self::ROLE_DEVELOPER ? $this->requireWorkflowAgent() : null;

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unassign.');
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->assertCanUnassignFeature($actorRole, $actorAgent, $agent, $feature, $match->getEntry());
        if ($match->getEntry()->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match->getEntry()->setAgent(null);
        $this->saveBoard($board, BacklogCommandName::FEATURE_UNASSIGN->value);
        $cleaned = $this->worktreeManager()->cleanupAbandonedManagedWorktrees($board);

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

        $match = $this->entryResolver()->requireFeature($board, $feature);
        $assignedAgent = $match->getEntry()->getAgent();
        if ($assignedAgent !== null && $assignedAgent !== $actorAgent) {
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

        $assignedAgent = $entry->getAgent();
        if ($assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is assigned to %s. Developer role can only unassign its own feature.',
                $feature,
                $assignedAgent === null ? 'no agent' : $assignedAgent,
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
            ? $this->entryService()->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-rework.');
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException("Feature {$feature} is not in the rejected stage.");
        }

        $match->getEntry()->setAgent($agent);
        $match->getEntry()->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REWORK->value);

        $worktree = $this->worktreeManager()->prepareAgentWorktree($agent);
        $this->worktreeManager()->checkoutBranchInWorktree($worktree, $match->getEntry()->getBranch() ?? '', false);

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
            ? $this->entryService()->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-block.');
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        $match->getEntry()->setBlocked(true);
        $this->saveBoard($board, BacklogCommandName::FEATURE_BLOCK->value);

        $prNumber = $this->storedPrNumber($match->getEntry());
        if ($prNumber !== null) {
            $type = $this->entryService()->featureStage($match->getEntry()) === BacklogBoard::STAGE_APPROVED ? $this->determinePrType($match->getEntry()) : 'WIP';
            $title = $this->ensureBlockedTitle($this->buildPrTitle($type, $match->getEntry()));
            $this->pullRequestManager()->editPrTitle($prNumber, $title);
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
            ? $this->entryService()->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unblock.');
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        if ($match->getEntry()->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match->getEntry()->setBlocked(false);
        $this->saveBoard($board, BacklogCommandName::FEATURE_UNBLOCK->value);

        $prNumber = $this->storedPrNumber($match->getEntry());
        if ($prNumber !== null) {
            $title = $this->buildCurrentTitle($match->getEntry());
            $this->pullRequestManager()->editPrTitle($prNumber, $title);
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
                fn(BoardEntry $entry): bool => $this->entryService()->featureStage($entry) === $stage
            ));
            if ($entries === []) {
                continue;
            }

            $printed = true;
            $this->console->line('[' . BacklogBoard::stageLabel($stage) . ']');
            foreach ($entries as $entry) {
                $parts = [
                    'kind=' . $this->entryService()->entryKind($entry),
                    $entry->getFeature() ?? '-',
                    'branch=' . ($entry->getBranch() ?? '-'),
                    'agent=' . ($entry->getAgent() ?? '-'),
                ];
                if ($this->entryService()->isTaskEntry($entry)) {
                    $parts[] = 'task=' . ($entry->getTask() ?? '-');
                    $parts[] = 'feature-branch=' . ($entry->getFeatureBranch() ?? '-');
                }
                if ($entry->isBlocked()) {
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

    private function worktreeClean(): int
    {
        $board = $this->board();
        $cleaned = $this->worktreeManager()->cleanupAbandonedManagedWorktrees($board);

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

        $classification = $this->worktreeManager()->classifyWorktrees($board);
        $skipped = count($classification->getManaged());
        if ($skipped > 0) {
            $this->console->line(sprintf('Skipped %d managed worktree%s that require manual attention.', $skipped, $skipped > 1 ? 's' : ''));
        }

        return 0;
    }

    private function printStatusWorktree(BacklogBoard $board, ?string $agent): void
    {
        $this->console->line('[Worktree]');
        if ($agent === null) {
            $this->console->line('State: unknown');
            $this->console->line('Path: -');

            return;
        }

        $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
        $expectedRelativePath = $this->consoleClient()->toRelativeProjectPath($expectedPath);
        $worktree = null;
        foreach ($this->worktreeManager()->classifyWorktrees($board)->getManaged() as $item) {
            if ($item->getPath() === $expectedPath) {
                $worktree = $item;
                break;
            }
        }

        if ($worktree === null) {
            $this->console->line('State: absent');
            $this->console->line('Path: ' . $expectedRelativePath);

            return;
        }

        $this->console->line('State: ' . $this->statusWorktreeStateLabel($worktree->getState()->value));
        $this->console->line('Path: ' . $expectedRelativePath);
        $this->console->line('Branch: ' . ($worktree->getBranch() ?? '-'));
        $this->console->line('Action: ' . $worktree->getAction()->value);
    }

    /**
     * @param array{path: string, branch: string|null, feature: string|null, agent: string|null, state: string, action: string} $worktree
     */
    private function statusWorktreeStateLabel(string $state): string
    {
        return match ($state) {
            WorktreeState::ACTIVE->value => WorktreeAction::CLEAN->value,
            WorktreeState::DIRTY->value => WorktreeState::DIRTY->value,
            WorktreeState::BLOCKED->value => 'inconsistent',
            WorktreeState::DETACHED_MANAGED->value => 'detached',
            WorktreeState::PRUNABLE->value => WorktreeState::PRUNABLE->value,
            WorktreeState::ORPHAN->value => WorktreeState::ORPHAN->value,
            default => $state,
        };
    }

    private function printStatusEntry(BoardEntry $entry): void
    {
        $this->console->line('Kind: ' . $this->entryService()->entryKind($entry));
        $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        if ($this->entryService()->isTaskEntry($entry)) {
            $this->console->line('Task: ' . ($entry->getTask() ?? '-'));
            $this->console->line('Ref: ' . $this->entryService()->taskReviewKey($entry));
            $this->console->line('Feature Branch: ' . ($entry->getFeatureBranch() ?? '-'));
        }
        $this->console->line('Branch: ' . ($entry->getBranch() ?? '-'));
        $this->console->line('Base: ' . ($entry->getBase() ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($this->entryService()->featureStage($entry)));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Blocker: ' . ($entry->isBlocked() ? 'blocked' : '-'));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
    }

    /**
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     */
    private function worktreeRestore(array $commandArgs, array $options): int
    {
        $board = $this->board();
        $agent = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::AGENT->value] ?? ''));
        $entry = null;

        if ($agent !== null) {
            $entry = $this->entryResolver()->getSingleTaskForAgent($board, $agent, false)
                ?? $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry();
        } elseif (BoardEntry::parseEmptyString($commandArgs[0] ?? null) !== null) {
            $feature = $this->entryService()->normalizeFeatureSlug($commandArgs[0]);
            $entry = $this->entryResolver()->requireFeature($board, $feature)->getEntry();
        } else {
            throw new \RuntimeException('worktree-restore requires --agent=<code> or <feature>.');
        }

        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException('Active entry has no branch metadata.');
        }

        $worktree = $this->worktreeManager()->prepareFeatureAgentWorktree($entry);
        $this->console->ok(sprintf(
            'Restored worktree %s on %s',
            $this->consoleClient()->toRelativeProjectPath($worktree),
            $branch,
        ));

        return 0;
    }

    private function printEntryStatus(BoardEntry $entry): void
    {
        $stage = $this->entryService()->featureStage($entry);
        $this->console->line('Kind: ' . $this->entryService()->entryKind($entry));
        if ($this->entryService()->isTaskEntry($entry)) {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
            $this->console->line('Task: ' . ($entry->getTask() ?? '-'));
            $this->console->line('Ref: ' . $this->entryService()->taskReviewKey($entry));
            $this->console->line('Feature Branch: ' . ($entry->getFeatureBranch() ?? '-'));
        } else {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        }
        $this->console->line('Branch: ' . ($entry->getBranch() ?? '-'));
        $this->console->line('Base: ' . ($entry->getBase() ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
        $this->console->line('Next: ' . $this->nextStepForEntry($entry, $stage));
        $this->console->line('Blocker: ' . ($entry->isBlocked() ? 'blocked' : '-'));
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
            ? $this->entryService()->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver()->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-review-request.');
        }

        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_REVIEW_REQUEST->value);
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);
        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.');
        }
        if ($match->getEntry()->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $worktree = $this->worktreeManager()->prepareFeatureAgentWorktree($match->getEntry());
        $this->worktreeManager()->runReviewScript($worktree, $match->getEntry()->getBase());

        $match->getEntry()->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);

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
        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_REVIEW_CHECK->value);
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_REVIEW_CHECK->value);
        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be checked.');
        }

        $reviewWorktree = $this->worktreeManager()->prepareFeatureAgentWorktree($match->getEntry());

        try {
            $this->worktreeManager()->runReviewScript($reviewWorktree, $match->getEntry()->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before requesting review again.';
            $this->featureReviewReject([$feature], [BacklogCliOption::BODY_FILE->value => $this->writeTempContent([$message])], true);
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
        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_REVIEW_REJECT->value);

        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be rejected.');
        }

        $match->getEntry()->setStage(BacklogBoard::STAGE_REJECTED);
        $review->setReview($feature, $this->reviewBodyFormatter()->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_REJECT->value);

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
        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);

        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . ' to be approved.');
        }

        $type = $this->determinePrType($match->getEntry());
        $title = $this->buildPrTitle($type, $match->getEntry());
        $branch = $match->getEntry()->getBranch();
        if ($branch === null) {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $this->pullRequestManager()->pushBranchAndWaitForRemoteVisibility($branch);
        $this->pullRequestManager()->createOrUpdatePr($branch, $title, $bodyFile, $this->prBaseBranch());
        $prNumber = $this->pullRequestManager()->findPrNumberByBranch($branch);
        if ($prNumber !== null) {
            $match->getEntry()->setPr((string) $prNumber);
        }

        $match->getEntry()->setStage(BacklogBoard::STAGE_APPROVED);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);

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
        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_CLOSE->value);
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_CLOSE->value);

        $branch = $match->getEntry()->getBranch();
        if ($branch === null) {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $this->worktreeManager()->ensureBranchHasNoDirtyManagedWorktree($branch);
        $this->gitWorkflow()->pushBranchIfAhead($branch);

        $prNumber = $this->storedPrNumber($match->getEntry());
        if ($prNumber !== null) {
            $this->pullRequestManager()->closePr($prNumber);
        }

        $board->removeFeature($feature);
        $board->clearReservations($match->getEntry()->getAgent() ?? '', $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_CLOSE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_CLOSE->value);
        $cleaned = $this->worktreeManager()->cleanupAbandonedManagedWorktrees($board);

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
        $match = $this->entryResolver()->requireFeature($board, $feature);
        $this->entryService()->assertFeatureEntry($match->getEntry(), BacklogCommandName::FEATURE_MERGE->value);
        $this->entryResolver()->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_MERGE->value);

        if ($this->entryService()->featureStage($match->getEntry()) !== BacklogBoard::STAGE_APPROVED) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_APPROVED) . ' before merge.');
        }
        if ($match->getEntry()->isBlocked()) {
            throw new \RuntimeException("Feature {$feature} is blocked and cannot be merged.");
        }

        $branch = $match->getEntry()->getBranch() ?? '';
        $prNumber = $this->pullRequestManager()->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $type = $this->determinePrType($match->getEntry());
        $this->pullRequestManager()->createOrUpdatePr($branch, $this->buildPrTitle($type, $match->getEntry()), $bodyFile, $this->prBaseBranch());
        $this->pullRequestManager()->mergePr($prNumber);
        $skippedMainCheckout = false;
        $targetBaseBranch = $this->prBaseBranch();
        $skippedMainCheckout = $this->gitWorkflow()->handleMergeBaseAfterPrMerge($targetBaseBranch, BacklogCommandName::FEATURE_MERGE->value);

        $board->removeFeature($feature);
        $board->clearReservations($match->getEntry()->getAgent() ?? '', $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_MERGE->value);
        $cleaned = $this->worktreeManager()->cleanupManagedWorktreesForBranch($branch, $board);
        $cleaned += $this->worktreeManager()->cleanupAbandonedManagedWorktrees($board);

        $this->gitWorkflow()->deleteRemoteBranch($branch);
        $this->gitWorkflow()->deleteLocalBranchIfExists($branch);

        $this->console->ok(sprintf('Merged feature %s', $feature));
        if ($skippedMainCheckout) {
            $this->console->line(sprintf('%s was handled without checkout in WP.', $targetBaseBranch === BacklogGitWorkflow::MAIN_BRANCH ? ucfirst(BacklogGitWorkflow::MAIN_BRANCH) : $targetBaseBranch));
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
        $agent = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::AGENT->value] ?? ''));
        if ($agent === null) {
            throw new \RuntimeException('This command requires --agent=<code>.');
        }

        return $agent;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function readBranchTypeOverride(array $options): string
    {
        $branchType = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::BRANCH_TYPE->value] ?? ''));
        if ($branchType === null) {
            return '';
        }
        if (!in_array($branchType, [BacklogEntryService::BRANCH_TYPE_FEAT, BacklogEntryService::BRANCH_TYPE_FIX], true)) {
            throw new \RuntimeException('feature-start --branch-type must be feat or fix.');
        }

        return $branchType;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function requireBodyFile(array $options): string
    {
        $bodyFile = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::BODY_FILE->value] ?? ''));
        if ($bodyFile === null) {
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
        $feature = BoardEntry::parseEmptyString($commandArgs[0] ?? null);
        if ($feature === null) {
            throw new \RuntimeException('This command requires <feature>.');
        }

        return $this->entryService()->normalizeFeatureSlug($feature);
    }

    private function board(): BacklogBoard
    {
        return new BacklogBoard($this->boardPath());
    }

    private function reviewFile(): BacklogReviewFile
    {
        return new BacklogReviewFile($this->reviewFilePath());
    }

    private function featureHasNoDevelopment(BoardEntry $entry): bool
    {
        $branch = $entry->getBranch();
        $base = $entry->getBase();
        if ($branch === null || $base === null) {
            throw new \RuntimeException('Feature metadata is incomplete: missing branch or base.');
        }

        $this->worktreeManager()->ensureBranchHasNoDirtyManagedWorktree($branch);

        return $this->gitWorkflow()->branchHasNoDevelopment($base, $branch);
    }

    private function featureSlugger(): TextSlugger
    {
        return new TextSlugger(
            maxWords: self::FEATURE_SLUG_MAX_WORDS,
            maxLength: self::FEATURE_SLUG_MAX_LENGTH,
        );
    }

    private function determinePrType(BoardEntry $entry): string
    {
        $base = $entry->getBase();
        $branch = $entry->getBranch();
        if ($base === null || $branch === null) {
            throw new \RuntimeException('Cannot determine PR type without base and branch metadata.');
        }

        $files = $this->gitWorkflow()->changedFiles($base, $branch);

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
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
            return PullRequestTag::DOC->value;
        }

        if ($techOnly) {
            return PullRequestTag::TECH->value;
        }

        return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
    }

    private function buildPrTitle(string $type, BoardEntry $entry): string
    {
        $title = sprintf('[%s] %s', $type, $entry->getText());

        return $entry->isBlocked()
            ? $this->ensureBlockedTitle($title)
            : $title;
    }

    private function buildCurrentTitle(BoardEntry $entry): string
    {
        $type = $this->entryService()->featureStage($entry) === BacklogBoard::STAGE_APPROVED
            ? $this->determinePrType($entry)
            : PullRequestTag::WIP->value;

        return $this->buildPrTitle($type, $entry);
    }

    private function ensureBlockedTitle(string $title): string
    {
        $tag = '[' . PullRequestTag::BLOCKED->value . ']';

        return str_contains($title, $tag)
            ? $title
            : $tag . ' ' . $title;
    }

    /**
     * @param array<string> $lines
     */
    private function writeTempContent(array $lines): string
    {
        $path = $this->projectRoot . '/local/tmp/backlog-auto-review.txt';
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would write temp file: ' . $this->consoleClient()->toRelativeProjectPath($path));
            return $path;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function nextStepForStage(string $stage): string
    {
        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => BacklogCommandName::FEATURE_REVIEW_REQUEST->value,
            BacklogBoard::STAGE_IN_REVIEW => BacklogCommandName::FEATURE_REVIEW_CHECK->value . ' or ' . BacklogCommandName::FEATURE_REVIEW_APPROVE->value,
            BacklogBoard::STAGE_REJECTED => BacklogCommandName::FEATURE_REWORK->value,
            BacklogBoard::STAGE_APPROVED => BacklogCommandName::FEATURE_MERGE->value,
            default => '-',
        };
    }

    private function nextStepForEntry(BoardEntry $entry, string $stage): string
    {
        if ($this->entryService()->isTaskEntry($entry)) {
            return match ($stage) {
                BacklogBoard::STAGE_IN_PROGRESS => BacklogCommandName::TASK_REVIEW_REQUEST->value . ' or ' . BacklogCommandName::FEATURE_TASK_MERGE->value,
                BacklogBoard::STAGE_IN_REVIEW => implode(', ', [
                    BacklogCommandName::TASK_REVIEW_CHECK->value,
                    BacklogCommandName::TASK_REVIEW_APPROVE->value,
                    BacklogCommandName::TASK_REVIEW_REJECT->value,
                ]) . ', or ' . BacklogCommandName::FEATURE_TASK_MERGE->value,
                BacklogBoard::STAGE_REJECTED => BacklogCommandName::TASK_REWORK->value . ' or ' . BacklogCommandName::FEATURE_TASK_MERGE->value,
                BacklogBoard::STAGE_APPROVED => BacklogCommandName::FEATURE_TASK_MERGE->value,
                default => BacklogCommandName::FEATURE_TASK_MERGE->value,
            };
        }

        return $this->nextStepForStage($stage);
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

    private function describePrStatus(BoardEntry $entry): string
    {
        $storedPrNumber = $this->storedPrNumber($entry);
        if ($storedPrNumber !== null) {
            return '#' . $storedPrNumber;
        }

        $branch = $entry->getBranch();
        if ($branch === null) {
            return BacklogMetaValue::NONE->value;
        }

        return BacklogMetaValue::NONE->value;
    }

    private function storedPrNumber(BoardEntry $entry): ?int
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return null;
        }

        return (int) $pr;
    }
}
