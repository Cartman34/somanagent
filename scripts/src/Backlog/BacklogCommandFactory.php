<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureAssignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureBlockCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureCloseCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureListCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReleaseCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewApproveCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewCheckCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewRejectCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReviewRequestCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureReworkCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureStartCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureTaskAddCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureTaskMergeCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureUnassignCommand;
use SoManAgent\Script\Backlog\Command\BacklogFeatureUnblockCommand;
use SoManAgent\Script\Backlog\Command\BacklogReviewNextCommand;
use SoManAgent\Script\Backlog\Command\BacklogStatusCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskCreateCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskRemoveCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewApproveCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewCheckCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewRejectCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReviewRequestCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskReworkCommand;
use SoManAgent\Script\Backlog\Command\BacklogTaskTodoListCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeCleanCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeListCommand;
use SoManAgent\Script\Backlog\Command\BacklogWorktreeRestoreCommand;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogHelpService;
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
    private string $projectRoot;
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

    public function __construct(
        Application $app,
        Console $console,
        bool $dryRun,
        string $projectRoot,
        string $boardPath,
        string $reviewFilePath
    ) {
        $this->app = $app;
        $this->console = $console;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    public function createHandler(string $commandName): AbstractBacklogCommand
    {
        $map = [
            BacklogCommandName::STATUS->value => BacklogStatusCommand::class,
            BacklogCommandName::WORKTREE_LIST->value => BacklogWorktreeListCommand::class,
            BacklogCommandName::WORKTREE_CLEAN->value => BacklogWorktreeCleanCommand::class,
            BacklogCommandName::WORKTREE_RESTORE->value => BacklogWorktreeRestoreCommand::class,
            BacklogCommandName::TASK_CREATE->value => BacklogTaskCreateCommand::class,
            BacklogCommandName::TASK_TODO_LIST->value => BacklogTaskTodoListCommand::class,
            BacklogCommandName::TASK_REMOVE->value => BacklogTaskRemoveCommand::class,
            BacklogCommandName::TASK_REVIEW_REQUEST->value => BacklogTaskReviewRequestCommand::class,
            BacklogCommandName::TASK_REVIEW_CHECK->value => BacklogTaskReviewCheckCommand::class,
            BacklogCommandName::TASK_REVIEW_REJECT->value => BacklogTaskReviewRejectCommand::class,
            BacklogCommandName::TASK_REVIEW_APPROVE->value => BacklogTaskReviewApproveCommand::class,
            BacklogCommandName::TASK_REWORK->value => BacklogTaskReworkCommand::class,
            BacklogCommandName::REVIEW_NEXT->value => BacklogReviewNextCommand::class,
            BacklogCommandName::FEATURE_START->value => BacklogFeatureStartCommand::class,
            BacklogCommandName::FEATURE_RELEASE->value => BacklogFeatureReleaseCommand::class,
            BacklogCommandName::FEATURE_TASK_ADD->value => BacklogFeatureTaskAddCommand::class,
            BacklogCommandName::FEATURE_TASK_MERGE->value => BacklogFeatureTaskMergeCommand::class,
            BacklogCommandName::FEATURE_ASSIGN->value => BacklogFeatureAssignCommand::class,
            BacklogCommandName::FEATURE_UNASSIGN->value => BacklogFeatureUnassignCommand::class,
            BacklogCommandName::FEATURE_REWORK->value => BacklogFeatureReworkCommand::class,
            BacklogCommandName::FEATURE_BLOCK->value => BacklogFeatureBlockCommand::class,
            BacklogCommandName::FEATURE_UNBLOCK->value => BacklogFeatureUnblockCommand::class,
            BacklogCommandName::FEATURE_LIST->value => BacklogFeatureListCommand::class,
            BacklogCommandName::FEATURE_REVIEW_REQUEST->value => BacklogFeatureReviewRequestCommand::class,
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
            return new $class($this->getPresenter(), $this->dryRun, $this->projectRoot);
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $arguments[] = match ($type->getName()) {
                BacklogPresenter::class => $this->getPresenter(),
                'bool' => $this->dryRun,
                'string' => $this->projectRoot,
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

    public function getBoardService(): BacklogBoardService
    {
        if ($this->boardService === null) {
            $this->boardService = new BacklogBoardService($this->getTextSlugger(), $this->getFilesystemClient(), $this->dryRun);
        }
        return $this->boardService;
    }

    public function getWorktreeService(): BacklogWorktreeService
    {
        if ($this->worktreeService === null) {
            $this->worktreeService = new BacklogWorktreeService(
                $this->projectRoot,
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

    public function getPermissionService(): BacklogPermissionService
    {
        if ($this->permissionService === null) {
            $this->permissionService = new BacklogPermissionService();
        }
        return $this->permissionService;
    }

    public function getPresenter(): BacklogPresenter
    {
        if ($this->presenter === null) {
            $this->presenter = new BacklogPresenter($this->console, $this->getConsoleClient(), $this->getBoardService());
        }
        return $this->presenter;
    }

    public function getGitService(): GitService
    {
        if ($this->gitService === null) {
            $this->gitService = new GitService($this->dryRun, $this->console, $this->getGitClient(), function(string $m) { $this->console->line($m); });
        }
        return $this->gitService;
    }

    public function getPullRequestService(): PullRequestService
    {
        if ($this->pullRequestService === null) {
            $this->pullRequestService = new PullRequestService($this->getGitHubClient(), $this->getGitService());
        }
        return $this->pullRequestService;
    }

    public function getReviewBodyFormatter(): BacklogReviewBodyFormatter
    {
        if ($this->reviewBodyFormatter === null) {
            $this->reviewBodyFormatter = new BacklogReviewBodyFormatter($this->projectRoot);
        }
        return $this->reviewBodyFormatter;
    }

    public function getFilesystemClient(): FilesystemClientInterface
    {
        if ($this->filesystemClient === null) {
            $this->filesystemClient = new FilesystemClient();
        }
        return $this->filesystemClient;
    }

    public function getConsoleClient(): ConsoleClient
    {
        if ($this->consoleClient === null) {
            $this->consoleClient = new ConsoleClient($this->projectRoot, $this->dryRun, $this->app, function(string $m) { $this->console->line($m); });
        }
        return $this->consoleClient;
    }

    public function getGitClient(): GitClient
    {
        if ($this->gitClient === null) {
            $this->gitClient = new GitClient($this->dryRun, $this->getConsoleClient());
        }
        return $this->gitClient;
    }

    public function getGitHubClient(): GitHubClient
    {
        if ($this->gitHubClient === null) {
            $this->gitHubClient = new GitHubClient($this->dryRun, $this->getProjectScriptClient(), [], 0, 0, 0);
        }
        return $this->gitHubClient;
    }

    public function getProjectScriptClient(): ProjectScriptClient
    {
        if ($this->projectScriptClient === null) {
            $this->projectScriptClient = new ProjectScriptClient($this->getConsoleClient());
        }
        return $this->projectScriptClient;
    }

    public function getTextSlugger(): TextSlugger
    {
        if ($this->textSlugger === null) {
            $this->textSlugger = new TextSlugger();
        }
        return $this->textSlugger;
    }
}
