<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use SoManAgent\Script\Backlog\Command\BacklogBaseUpdateCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureAssignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureBlockCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureCloseCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureListCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReleaseCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewApproveCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewCheckCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewRejectCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryRenameCommand;
use SoManAgent\Script\Backlog\Command\BacklogReworkCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorkStartCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureTaskMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureUnassignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureUnblockCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewNextCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewRequestCommand;
use SoManAgent\Script\Backlog\Command\BacklogStatusCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskCreateCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskRemoveCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewApproveCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewCheckCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewRejectCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskTodoListCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeCleanCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeListCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeRestoreCommand;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\FilesystemClientInterface;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;
use SoManAgent\Script\TextSlugger;
use SoManAgent\Script\Application;
use SoManAgent\Script\RetryPolicy;

/**
 * Factory for creating backlog commands with their specific dependencies.
 *
 * Implements lazy loading for all shared services.
 */
final class BacklogCommandFactory
{
    private Application $app;
    private Console $console;
    private bool $dryRun;
    private bool $verbose;
    private string $projectRoot;
    private string $worktreesRoot;
    private string $boardPath;
    private string $reviewFilePath;

    private ?BacklogBoardService $boardService = null;
    private ?BacklogWorktreeService $worktreeService = null;
    private ?BacklogPermissionService $permissionService = null;
    private ?BacklogPresenter $presenter = null;
    private ?BacklogReviewBodyFormatter $reviewBodyFormatter = null;
    private ?GitService $gitService = null;
    private ?PullRequestService $pullRequestService = null;
    private ?ConsoleClient $consoleClient = null;
    private ?GitClient $gitClient = null;
    private ?GitHubClient $gitHubClient = null;
    private ?ProjectScriptClient $projectScriptClient = null;
    private ?TextSlugger $textSlugger = null;
    private ?FilesystemClientInterface $filesystemClient = null;
    private ?RetryPolicy $retryPolicy = null;

    /**
     * Constructor.
     *
     * @param Application $app The application instance
     * @param Console $console The console instance
     * @param bool $dryRun Whether to run in dry-run mode
     * @param bool $verbose Whether to enable verbose logging
     * @param string $projectRoot The project root path
     * @param string $boardPath The board path
     * @param string $reviewFilePath The review file path
     */
    public function __construct(
        Application $app,
        Console $console,
        bool $dryRun,
        bool $verbose,
        string $projectRoot,
        string $worktreesRoot,
        string $boardPath,
        string $reviewFilePath
    ) {
        $this->app = $app;
        $this->console = $console;
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
        $this->projectRoot = $projectRoot;
        $this->worktreesRoot = $worktreesRoot;
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    /**
     * Create a handler for the given command name.
     *
     * @param string $commandName The command name
     * @return AbstractBacklogCommand
     * @throws \RuntimeException
     */
    public function createHandler(string $commandName): AbstractBacklogCommand
    {
        $map = [
            BacklogCommandName::BASE_UPDATE->value => BacklogBaseUpdateCommand::class,
            BacklogCommandName::STATUS->value => BacklogStatusCommand::class,
            BacklogCommandName::WORKTREE_LIST->value => BacklogWorktreeListCommand::class,
            BacklogCommandName::WORKTREE_CLEAN->value => BacklogWorktreeCleanCommand::class,
            BacklogCommandName::WORKTREE_RESTORE->value => BacklogWorktreeRestoreCommand::class,
            BacklogCommandName::TASK_CREATE->value => BacklogTaskCreateCommand::class,
            BacklogCommandName::TASK_TODO_LIST->value => BacklogTaskTodoListCommand::class,
            BacklogCommandName::TASK_REMOVE->value => BacklogTaskRemoveCommand::class,
            BacklogCommandName::REVIEW_REQUEST->value => BacklogReviewRequestCommand::class,
            BacklogCommandName::TASK_REVIEW_CHECK->value => BacklogTaskReviewCheckCommand::class,
            BacklogCommandName::TASK_REVIEW_REJECT->value => BacklogTaskReviewRejectCommand::class,
            BacklogCommandName::TASK_REVIEW_APPROVE->value => BacklogTaskReviewApproveCommand::class,
            BacklogCommandName::REWORK->value => BacklogReworkCommand::class,
            BacklogCommandName::ENTRY_RENAME->value => BacklogEntryRenameCommand::class,
            BacklogCommandName::REVIEW_NEXT->value => BacklogReviewNextCommand::class,
            BacklogCommandName::WORK_START->value => BacklogWorkStartCommand::class,
            BacklogCommandName::FEATURE_RELEASE->value => BacklogFeatureReleaseCommand::class,
            BacklogCommandName::FEATURE_TASK_MERGE->value => BacklogFeatureTaskMergeCommand::class,
            BacklogCommandName::FEATURE_ASSIGN->value => BacklogFeatureAssignCommand::class,
            BacklogCommandName::FEATURE_UNASSIGN->value => BacklogFeatureUnassignCommand::class,
            BacklogCommandName::FEATURE_BLOCK->value => BacklogFeatureBlockCommand::class,
            BacklogCommandName::FEATURE_UNBLOCK->value => BacklogFeatureUnblockCommand::class,
            BacklogCommandName::FEATURE_LIST->value => BacklogFeatureListCommand::class,
            BacklogCommandName::FEATURE_REVIEW_CHECK->value => BacklogFeatureReviewCheckCommand::class,
            BacklogCommandName::FEATURE_REVIEW_REJECT->value => BacklogFeatureReviewRejectCommand::class,
            BacklogCommandName::FEATURE_REVIEW_APPROVE->value => BacklogFeatureReviewApproveCommand::class,
            BacklogCommandName::FEATURE_CLOSE->value => BacklogFeatureCloseCommand::class,
            BacklogCommandName::FEATURE_MERGE->value => BacklogFeatureMergeCommand::class,
        ];

        $class = $map[$commandName] ?? null;
        if ($class === null) {
            throw new \RuntimeException(sprintf('No handler found for command: %s', $commandName));
        }

        // Use reflection or a consistent factory method to inject only needed dependencies
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        
        if ($constructor === null) {
            throw new \RuntimeException(sprintf(
                'Command class %s must define its own constructor to support dependency injection.',
                $class
            ));
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $arguments[] = match ($type->getName()) {
                BacklogPresenter::class => $this->getPresenter(),
                'bool' => match ($parameter->getName()) {
                    'dryRun' => $this->dryRun,
                    default => throw new \RuntimeException('Unable to inject bool parameter: ' . $parameter->getName()),
                },
                'string' => match ($parameter->getName()) {
                    'projectRoot' => $this->projectRoot,
                    default => throw new \RuntimeException('Unable to inject string parameter: ' . $parameter->getName()),
                },
                BacklogBoardService::class => $this->getBoardService(),
                BacklogWorktreeService::class => $this->getWorktreeService(),
                BacklogPermissionService::class => $this->getPermissionService(),
                GitService::class => $this->getGitService(),
                PullRequestService::class => $this->getPullRequestService(),
                BacklogReviewBodyFormatter::class => $this->getReviewBodyFormatter(),
                FilesystemClientInterface::class => $this->getFilesystemClient(),
                self::class => $this,
                default => throw new \RuntimeException('Unable to inject ' . $type->getName()),
            };
        }

        /** @var AbstractBacklogCommand $command */
        $command = $reflection->newInstanceArgs($arguments);
        $command->setBoardPath($this->boardPath);
        $command->setReviewFilePath($this->reviewFilePath);

        return $command;
    }

    /* --- Lazy Loading Getters --- */

    /**
     * Get the board service.
     *
     * @return BacklogBoardService
     */
    public function getBoardService(): BacklogBoardService
    {
        if ($this->boardService === null) {
            $this->boardService = new BacklogBoardService($this->getTextSlugger(), $this->getFilesystemClient(), $this->dryRun);
        }
        return $this->boardService;
    }

    /**
     * Get the worktree service.
     *
     * @return BacklogWorktreeService
     */
    public function getWorktreeService(): BacklogWorktreeService
    {
        if ($this->worktreeService === null) {
            $this->worktreeService = new BacklogWorktreeService(
                $this->projectRoot,
                $this->worktreesRoot,
                $this->dryRun,
                (string) getenv('DATABASE_URL'),
                $this->getBoardService(),
                $this->getConsoleClient(),
                $this->getGitClient(),
                $this->getProjectScriptClient(),
                $this->getFilesystemClient()
            );
        }
        return $this->worktreeService;
    }

    /**
     * Get the permission service.
     *
     * @return BacklogPermissionService
     */
    public function getPermissionService(): BacklogPermissionService
    {
        if ($this->permissionService === null) {
            $this->permissionService = new BacklogPermissionService();
        }
        return $this->permissionService;
    }

    /**
     * Get the presenter.
     *
     * @return BacklogPresenter
     */
    public function getPresenter(): BacklogPresenter
    {
        if ($this->presenter === null) {
            $this->presenter = new BacklogPresenter($this->console, $this->getConsoleClient(), $this->getBoardService());
        }
        return $this->presenter;
    }

    /**
     * Get the git service.
     *
     * @return GitService
     */
    public function getGitService(): GitService
    {
        if ($this->gitService === null) {
            $this->gitService = new GitService($this->dryRun, $this->console, $this->getGitClient(), function(string $m) { $this->console->line($m); });
        }
        return $this->gitService;
    }

    /**
     * Get the pull request service.
     *
     * @return PullRequestService
     */
    public function getPullRequestService(): PullRequestService
    {
        if ($this->pullRequestService === null) {
            $this->pullRequestService = new PullRequestService($this->getGitHubClient(), $this->getGitService(), $this->getRetryPolicy());
        }
        return $this->pullRequestService;
    }

    /**
     * Get the review body formatter.
     *
     * @return BacklogReviewBodyFormatter
     */
    public function getReviewBodyFormatter(): BacklogReviewBodyFormatter
    {
        if ($this->reviewBodyFormatter === null) {
            $this->reviewBodyFormatter = new BacklogReviewBodyFormatter();
        }
        return $this->reviewBodyFormatter;
    }

    /**
     * Get the filesystem client.
     *
     * @return FilesystemClientInterface
     */
    public function getFilesystemClient(): FilesystemClientInterface
    {
        if ($this->filesystemClient === null) {
            $this->filesystemClient = new FilesystemClient();
        }
        return $this->filesystemClient;
    }

    /**
     * Get the console client.
     *
     * @return ConsoleClient
     */
    public function getConsoleClient(): ConsoleClient
    {
        if ($this->consoleClient === null) {
            $this->consoleClient = new ConsoleClient($this->projectRoot, $this->dryRun, $this->app, function(string $m): void {
                if ($this->verbose) {
                    $this->console->line($m);
                }
            });
        }
        return $this->consoleClient;
    }

    /**
     * Get the git client.
     *
     * @return GitClient
     */
    public function getGitClient(): GitClient
    {
        if ($this->gitClient === null) {
            $this->gitClient = new GitClient($this->dryRun, $this->getConsoleClient(), $this->getRetryPolicy());
        }
        return $this->gitClient;
    }

    /**
     * Get the GitHub client.
     *
     * @return GitHubClient
     */
    public function getGitHubClient(): GitHubClient
    {
        if ($this->gitHubClient === null) {
            $this->gitHubClient = new GitHubClient($this->dryRun, $this->getProjectScriptClient(), $this->getRetryPolicy());
        }
        return $this->gitHubClient;
    }

    /**
     * Get the project script client.
     *
     * @return ProjectScriptClient
     */
    public function getProjectScriptClient(): ProjectScriptClient
    {
        if ($this->projectScriptClient === null) {
            $this->projectScriptClient = new ProjectScriptClient($this->getConsoleClient());
        }
        return $this->projectScriptClient;
    }

    /**
     * Get the text slugger.
     *
     * @return TextSlugger
     */
    public function getTextSlugger(): TextSlugger
    {
        if ($this->textSlugger === null) {
            $this->textSlugger = new TextSlugger();
        }
        return $this->textSlugger;
    }

    /**
     * Get the retry policy.
     *
     * @return RetryPolicy
     */
    public function getRetryPolicy(): RetryPolicy
    {
        if ($this->retryPolicy === null) {
            $this->retryPolicy = new RetryPolicy();
        }

        return $this->retryPolicy;
    }
}
