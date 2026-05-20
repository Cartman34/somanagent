<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use SoManAgent\Script\Backlog\Command\BacklogBaseUpdateCommand;
use SoManAgent\Script\Backlog\Command\BacklogCommitGateCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryAssignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureBlockCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureCloseCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryRebaseCommand;
use SoManAgent\Script\Backlog\Command\BacklogUserMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogListCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryReleaseCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryRenameCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntrySetMetaCommand;
use SoManAgent\Script\Backlog\Command\BacklogReworkCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorkStartCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureTaskMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryUnassignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureUnblockCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewApproveCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewCancelCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewCheckCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewNextCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewNotesCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewAmendCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewRejectCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewReopenCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewRequestCommand;
use SoManAgent\Script\Backlog\Command\BacklogStatusCommand;
use SoManAgent\Script\Backlog\Command\BacklogEntryCreateCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskRemoveCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeCleanCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeListCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeRestoreCommand;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use SoManAgent\Script\Backlog\Service\PostMergeSessionStopper;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\FilesystemClientInterface;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;
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
    private ?AgentSessionService $agentSessionService = null;
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
    private ?BacklogFeatureMergeCommand $featureMergeCommand = null;
    private ?BacklogFeatureTaskMergeCommand $featureTaskMergeCommand = null;
    private ?BodyFilePathResolver $bodyFilePathResolver = null;
    private ?EntryRebaseService $entryRebaseService = null;
    private ?PostMergeSessionStopper $postMergeSessionStopper = null;

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
            BacklogCommandName::ENTRY_CREATE->value => BacklogEntryCreateCommand::class,
            BacklogCommandName::ENTRY_REMOVE->value => BacklogTaskRemoveCommand::class,
            BacklogCommandName::REVIEW_REQUEST->value => BacklogReviewRequestCommand::class,
            BacklogCommandName::REWORK->value => BacklogReworkCommand::class,
            BacklogCommandName::MERGE->value => BacklogEntryMergeCommand::class,
            BacklogCommandName::RENAME->value => BacklogEntryRenameCommand::class,
            BacklogCommandName::ENTRY_SET_META->value => BacklogEntrySetMetaCommand::class,
            BacklogCommandName::REVIEW_CANCEL->value => BacklogReviewCancelCommand::class,
            BacklogCommandName::REVIEW_CHECK->value => BacklogReviewCheckCommand::class,
            BacklogCommandName::REVIEW_APPROVE->value => BacklogReviewApproveCommand::class,
            BacklogCommandName::REVIEW_REJECT->value => BacklogReviewRejectCommand::class,
            BacklogCommandName::REVIEW_AMEND->value => BacklogReviewAmendCommand::class,
            BacklogCommandName::REVIEW_REOPEN->value => BacklogReviewReopenCommand::class,
            BacklogCommandName::REVIEW_NEXT->value => BacklogReviewNextCommand::class,
            BacklogCommandName::REVIEW_NOTES->value => BacklogReviewNotesCommand::class,
            BacklogCommandName::START->value => BacklogWorkStartCommand::class,
            BacklogCommandName::RELEASE->value => BacklogEntryReleaseCommand::class,
            BacklogCommandName::FEATURE_TASK_MERGE->value => BacklogFeatureTaskMergeCommand::class,
            BacklogCommandName::ASSIGN->value => BacklogEntryAssignCommand::class,
            BacklogCommandName::UNASSIGN->value => BacklogEntryUnassignCommand::class,
            BacklogCommandName::FEATURE_BLOCK->value => BacklogFeatureBlockCommand::class,
            BacklogCommandName::FEATURE_UNBLOCK->value => BacklogFeatureUnblockCommand::class,
            BacklogCommandName::LIST->value => BacklogListCommand::class,
            BacklogCommandName::FEATURE_CLOSE->value => BacklogFeatureCloseCommand::class,
            BacklogCommandName::USER_MERGE->value => BacklogUserMergeCommand::class,
            BacklogCommandName::REBASE->value => BacklogEntryRebaseCommand::class,
            BacklogCommandName::PRECOMMIT_CHECK->value => BacklogCommitGateCommand::class,
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
                    'worktreesRoot' => $this->worktreesRoot,
                    'boardPath' => $this->boardPath,
                    default => throw new \RuntimeException('Unable to inject string parameter: ' . $parameter->getName()),
                },
                BacklogBoardService::class => $this->getBoardService(),
                AgentSessionService::class => $this->getAgentSessionService(),
                BacklogWorktreeService::class => $this->getWorktreeService(),
                BacklogPermissionService::class => $this->getPermissionService(),
                GitService::class => $this->getGitService(),
                PullRequestService::class => $this->getPullRequestService(),
                BacklogReviewBodyFormatter::class => $this->getReviewBodyFormatter(),
                FilesystemClientInterface::class => $this->getFilesystemClient(),
                BodyFilePathResolver::class => $this->getBodyFilePathResolver(),
                BacklogFeatureMergeCommand::class => $this->getFeatureMergeCommand(),
                BacklogFeatureTaskMergeCommand::class => $this->getFeatureTaskMergeCommand(),
                EntryRebaseService::class => $this->getEntryRebaseService(),
                PostMergeSessionStopper::class => $this->getPostMergeSessionStopper(),
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

    private function getAgentSessionService(): AgentSessionService
    {
        if ($this->agentSessionService === null) {
            $this->agentSessionService = new AgentSessionService($this->projectRoot);
        }

        return $this->agentSessionService;
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
            $this->gitClient = new GitClient(
                $this->dryRun,
                $this->getConsoleClient(),
                $this->getRetryPolicy(),
                GitClient::shouldDisableNetworkFromEnvironment(),
            );
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

    /**
     * Get the body file path resolver.
     *
     * @return BodyFilePathResolver
     */
    public function getBodyFilePathResolver(): BodyFilePathResolver
    {
        if ($this->bodyFilePathResolver === null) {
            $this->bodyFilePathResolver = new BodyFilePathResolver(
                $this->getBoardService(),
                $this->getWorktreeService(),
                $this->console,
                $this->boardPath,
            );
        }

        return $this->bodyFilePathResolver;
    }

    /**
     * Get the entry rebase service.
     *
     * @return EntryRebaseService
     */
    public function getEntryRebaseService(): EntryRebaseService
    {
        if ($this->entryRebaseService === null) {
            $this->entryRebaseService = new EntryRebaseService(
                $this->getBoardService(),
                $this->getGitService(),
            );
        }

        return $this->entryRebaseService;
    }

    /**
     * Get the feature merge command (internal implementation, not public).
     *
     * @return BacklogFeatureMergeCommand
     */
    public function getFeatureMergeCommand(): BacklogFeatureMergeCommand
    {
        if ($this->featureMergeCommand === null) {
            $this->featureMergeCommand = new BacklogFeatureMergeCommand(
                $this->getPresenter(),
                $this->dryRun,
                $this->projectRoot,
                $this->getBoardService(),
                $this->getWorktreeService(),
                $this->getGitService(),
                $this->getPullRequestService(),
                $this->getBodyFilePathResolver(),
                $this->getPostMergeSessionStopper(),
            );
            $this->featureMergeCommand->setBoardPath($this->boardPath);
            $this->featureMergeCommand->setReviewFilePath($this->reviewFilePath);
        }

        return $this->featureMergeCommand;
    }

    /**
     * Get the feature task merge command (internal implementation, not public).
     *
     * @return BacklogFeatureTaskMergeCommand
     */
    public function getFeatureTaskMergeCommand(): BacklogFeatureTaskMergeCommand
    {
        if ($this->featureTaskMergeCommand === null) {
            $this->featureTaskMergeCommand = new BacklogFeatureTaskMergeCommand(
                $this->getPresenter(),
                $this->dryRun,
                $this->projectRoot,
                $this->getBoardService(),
                $this->getWorktreeService(),
                $this->getGitService(),
                $this->getPostMergeSessionStopper(),
            );
            $this->featureTaskMergeCommand->setBoardPath($this->boardPath);
            $this->featureTaskMergeCommand->setReviewFilePath($this->reviewFilePath);
        }

        return $this->featureTaskMergeCommand;
    }

    private function getPostMergeSessionStopper(): PostMergeSessionStopper
    {
        if ($this->postMergeSessionStopper === null) {
            $this->postMergeSessionStopper = new PostMergeSessionStopper($this->getPresenter(), $this->projectRoot);
        }

        return $this->postMergeSessionStopper;
    }
}
