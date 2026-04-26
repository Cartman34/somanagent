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
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Factory for creating backlog commands with their specific dependencies.
 */
final class BacklogCommandFactory
{
    private Console $console;

    private bool $dryRun;

    private string $projectRoot;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogEntryService $entryService;

    private BacklogEntryResolver $entryResolver;

    private ConsoleClient $consoleClient;

    private TextSlugger $featureSlugger;

    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestService $pullRequestService;

    private BacklogPresenter $presenter;

    private BacklogPermissionService $permissionService;

    private string $boardPath;

    private string $reviewFilePath;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogWorktreeManager $worktreeManager,
        BacklogEntryService $entryService,
        BacklogEntryResolver $entryResolver,
        ConsoleClient $consoleClient,
        TextSlugger $featureSlugger,
        BacklogReviewBodyFormatter $reviewBodyFormatter,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestService $pullRequestService,
        string $boardPath,
        string $reviewFilePath
    ) {
        $this->console = $console;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
        $this->worktreeManager = $worktreeManager;
        $this->entryService = $entryService;
        $this->entryResolver = $entryResolver;
        $this->consoleClient = $consoleClient;
        $this->featureSlugger = $featureSlugger;
        $this->reviewBodyFormatter = $reviewBodyFormatter;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestService = $pullRequestService;
        $this->presenter = new BacklogPresenter($console, $consoleClient, $entryService);
        $this->permissionService = new BacklogPermissionService();
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    public function createHandler(string $commandName): AbstractBacklogCommand
    {
        $command = match ($commandName) {
            BacklogCommandName::STATUS->value => new BacklogStatusCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager
            ),
            BacklogCommandName::WORKTREE_LIST->value => new BacklogWorktreeListCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->worktreeManager
            ),
            BacklogCommandName::WORKTREE_CLEAN->value => new BacklogWorktreeCleanCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->worktreeManager
            ),
            BacklogCommandName::WORKTREE_RESTORE->value => new BacklogWorktreeRestoreCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->worktreeManager, $this->entryResolver
            ),
            BacklogCommandName::TASK_CREATE->value => new BacklogTaskCreateCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryService
            ),
            BacklogCommandName::TASK_TODO_LIST->value => new BacklogTaskTodoListCommand(
                $this->presenter, $this->dryRun, $this->projectRoot
            ),
            BacklogCommandName::TASK_REMOVE->value => new BacklogTaskRemoveCommand(
                $this->presenter, $this->dryRun, $this->projectRoot
            ),
            BacklogCommandName::TASK_REVIEW_REQUEST->value => new BacklogTaskReviewRequestCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager
            ),
            BacklogCommandName::TASK_REVIEW_CHECK->value => new BacklogTaskReviewCheckCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this
            ),
            BacklogCommandName::TASK_REVIEW_REJECT->value => new BacklogTaskReviewRejectCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->reviewBodyFormatter
            ),
            BacklogCommandName::TASK_REVIEW_APPROVE->value => new BacklogTaskReviewApproveCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService
            ),
            BacklogCommandName::TASK_REWORK->value => new BacklogTaskReworkCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager
            ),
            BacklogCommandName::REVIEW_NEXT->value => new BacklogReviewNextCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryService
            ),
            BacklogCommandName::FEATURE_START->value => new BacklogFeatureStartCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_RELEASE->value => new BacklogFeatureReleaseCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_TASK_ADD->value => new BacklogFeatureTaskAddCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow, $this->pullRequestService
            ),
            BacklogCommandName::FEATURE_TASK_MERGE->value => new BacklogFeatureTaskMergeCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_ASSIGN->value => new BacklogFeatureAssignCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->worktreeManager, $this->permissionService
            ),
            BacklogCommandName::FEATURE_UNASSIGN->value => new BacklogFeatureUnassignCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->worktreeManager, $this->permissionService
            ),
            BacklogCommandName::FEATURE_REWORK->value => new BacklogFeatureReworkCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_BLOCK->value => new BacklogFeatureBlockCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->gitWorkflow, $this->pullRequestService
            ),
            BacklogCommandName::FEATURE_UNBLOCK->value => new BacklogFeatureUnblockCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->gitWorkflow, $this->pullRequestService
            ),
            BacklogCommandName::FEATURE_LIST->value => new BacklogFeatureListCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryService
            ),
            BacklogCommandName::FEATURE_REVIEW_REQUEST->value => new BacklogFeatureReviewRequestCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_REVIEW_CHECK->value => new BacklogFeatureReviewCheckCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this
            ),
            BacklogCommandName::FEATURE_REVIEW_REJECT->value => new BacklogFeatureReviewRejectCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->reviewBodyFormatter
            ),
            BacklogCommandName::FEATURE_REVIEW_APPROVE->value => new BacklogFeatureReviewApproveCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->gitWorkflow, $this->pullRequestService
            ),
            BacklogCommandName::FEATURE_CLOSE->value => new BacklogFeatureCloseCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_MERGE->value => new BacklogFeatureMergeCommand(
                $this->presenter, $this->dryRun, $this->projectRoot, $this->entryResolver, $this->entryService, $this->worktreeManager, $this->gitWorkflow, $this->pullRequestService
            ),
            default => throw new \RuntimeException(sprintf('No handler found for command: %s', $commandName)),
        };

        $command->setBoardPath($this->boardPath);
        $command->setReviewFilePath($this->reviewFilePath);

        return $command;
    }
}
