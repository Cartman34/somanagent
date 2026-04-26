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
 * Factory for creating backlog command handlers with their dependencies.
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

    private PullRequestManager $pullRequestManager;

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
        PullRequestManager $pullRequestManager,
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
        $this->pullRequestManager = $pullRequestManager;
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    public function createHandler(string $commandName): AbstractBacklogCommand
    {
        $command = match ($commandName) {
            BacklogCommandName::STATUS->value => new BacklogStatusCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->consoleClient
            ),
            BacklogCommandName::WORKTREE_LIST->value => new BacklogWorktreeListCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager,
                $this->consoleClient
            ),
            BacklogCommandName::WORKTREE_CLEAN->value => new BacklogWorktreeCleanCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager
            ),
            BacklogCommandName::WORKTREE_RESTORE->value => new BacklogWorktreeRestoreCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager,
                $this->entryResolver
            ),
            BacklogCommandName::TASK_CREATE->value => new BacklogTaskCreateCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryService
            ),
            BacklogCommandName::TASK_TODO_LIST->value => new BacklogTaskTodoListCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot
            ),
            BacklogCommandName::TASK_REMOVE->value => new BacklogTaskRemoveCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot
            ),
            BacklogCommandName::TASK_REVIEW_REQUEST->value => new BacklogTaskReviewRequestCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager
            ),
            BacklogCommandName::TASK_REVIEW_CHECK->value => new BacklogTaskReviewCheckCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this
            ),
            BacklogCommandName::TASK_REVIEW_REJECT->value => new BacklogTaskReviewRejectCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->reviewBodyFormatter
            ),
            BacklogCommandName::TASK_REVIEW_APPROVE->value => new BacklogTaskReviewApproveCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService
            ),
            BacklogCommandName::TASK_REWORK->value => new BacklogTaskReworkCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager
            ),
            BacklogCommandName::REVIEW_NEXT->value => new BacklogReviewNextCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryService
            ),
            BacklogCommandName::FEATURE_START->value => new BacklogFeatureStartCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_RELEASE->value => new BacklogFeatureReleaseCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_TASK_ADD->value => new BacklogFeatureTaskAddCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow,
                $this->pullRequestManager
            ),
            BacklogCommandName::FEATURE_TASK_MERGE->value => new BacklogFeatureTaskMergeCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_ASSIGN->value => new BacklogFeatureAssignCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_UNASSIGN->value => new BacklogFeatureUnassignCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_REWORK->value => new BacklogFeatureReworkCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_BLOCK->value => new BacklogFeatureBlockCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->gitWorkflow,
                $this->pullRequestManager
            ),
            BacklogCommandName::FEATURE_UNBLOCK->value => new BacklogFeatureUnblockCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->gitWorkflow,
                $this->pullRequestManager
            ),
            BacklogCommandName::FEATURE_LIST->value => new BacklogFeatureListCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryService
            ),
            BacklogCommandName::FEATURE_REVIEW_REQUEST->value => new BacklogFeatureReviewRequestCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager
            ),
            BacklogCommandName::FEATURE_REVIEW_CHECK->value => new BacklogFeatureReviewCheckCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this
            ),
            BacklogCommandName::FEATURE_REVIEW_REJECT->value => new BacklogFeatureReviewRejectCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->reviewBodyFormatter
            ),
            BacklogCommandName::FEATURE_REVIEW_APPROVE->value => new BacklogFeatureReviewApproveCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->gitWorkflow,
                $this->pullRequestManager
            ),
            BacklogCommandName::FEATURE_CLOSE->value => new BacklogFeatureCloseCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow
            ),
            BacklogCommandName::FEATURE_MERGE->value => new BacklogFeatureMergeCommand(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->gitWorkflow,
                $this->pullRequestManager
            ),
            default => throw new \RuntimeException(sprintf('No handler found for command: %s', $commandName)),
        };

        $command->setBoardPath($this->boardPath);
        $command->setReviewFilePath($this->reviewFilePath);

        return $command;
    }
}
