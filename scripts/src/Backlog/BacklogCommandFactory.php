<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use SoManAgent\Script\Backlog\Command\BacklogCommandContext;
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
    private BacklogCommandContext $context;

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
        $this->context = new BacklogCommandContext(
            $console,
            $dryRun,
            $projectRoot,
            $worktreeManager,
            $entryService,
            $entryResolver,
            $consoleClient,
            $featureSlugger,
            $reviewBodyFormatter,
            $gitWorkflow,
            $pullRequestManager,
            $this
        );
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    public function createHandler(string $commandName): AbstractBacklogCommand
    {
        $map = [
            BacklogCommandName::STATUS->value => Command\BacklogStatusCommand::class,
            BacklogCommandName::WORKTREE_LIST->value => Command\BacklogWorktreeListCommand::class,
            BacklogCommandName::WORKTREE_CLEAN->value => Command\BacklogWorktreeCleanCommand::class,
            BacklogCommandName::WORKTREE_RESTORE->value => Command\BacklogWorktreeRestoreCommand::class,
            BacklogCommandName::TASK_CREATE->value => Command\BacklogTaskCreateCommand::class,
            BacklogCommandName::TASK_TODO_LIST->value => Command\BacklogTaskTodoListCommand::class,
            BacklogCommandName::TASK_REMOVE->value => Command\BacklogTaskRemoveCommand::class,
            BacklogCommandName::TASK_REVIEW_REQUEST->value => Command\BacklogTaskReviewRequestCommand::class,
            BacklogCommandName::TASK_REVIEW_CHECK->value => Command\BacklogTaskReviewCheckCommand::class,
            BacklogCommandName::TASK_REVIEW_REJECT->value => Command\BacklogTaskReviewRejectCommand::class,
            BacklogCommandName::TASK_REVIEW_APPROVE->value => Command\BacklogTaskReviewApproveCommand::class,
            BacklogCommandName::TASK_REWORK->value => Command\BacklogTaskReworkCommand::class,
            BacklogCommandName::REVIEW_NEXT->value => Command\BacklogReviewNextCommand::class,
            BacklogCommandName::FEATURE_START->value => Command\BacklogFeatureStartCommand::class,
            BacklogCommandName::FEATURE_RELEASE->value => Command\BacklogFeatureReleaseCommand::class,
            BacklogCommandName::FEATURE_TASK_ADD->value => Command\BacklogFeatureTaskAddCommand::class,
            BacklogCommandName::FEATURE_TASK_MERGE->value => Command\BacklogFeatureTaskMergeCommand::class,
            BacklogCommandName::FEATURE_ASSIGN->value => Command\BacklogFeatureAssignCommand::class,
            BacklogCommandName::FEATURE_UNASSIGN->value => Command\BacklogFeatureUnassignCommand::class,
            BacklogCommandName::FEATURE_REWORK->value => Command\BacklogFeatureReworkCommand::class,
            BacklogCommandName::FEATURE_BLOCK->value => Command\BacklogFeatureBlockCommand::class,
            BacklogCommandName::FEATURE_UNBLOCK->value => Command\BacklogFeatureUnblockCommand::class,
            BacklogCommandName::FEATURE_LIST->value => Command\BacklogFeatureListCommand::class,
            BacklogCommandName::FEATURE_REVIEW_REQUEST->value => Command\BacklogFeatureReviewRequestCommand::class,
            BacklogCommandName::FEATURE_REVIEW_CHECK->value => Command\BacklogFeatureReviewCheckCommand::class,
            BacklogCommandName::FEATURE_REVIEW_REJECT->value => Command\BacklogFeatureReviewRejectCommand::class,
            BacklogCommandName::FEATURE_REVIEW_APPROVE->value => Command\BacklogFeatureReviewApproveCommand::class,
            BacklogCommandName::FEATURE_CLOSE->value => Command\BacklogFeatureCloseCommand::class,
            BacklogCommandName::FEATURE_MERGE->value => Command\BacklogFeatureMergeCommand::class,
        ];

        $class = $map[$commandName] ?? null;
        if ($class === null) {
            throw new \RuntimeException(sprintf('No handler found for command: %s', $commandName));
        }

        /** @var AbstractBacklogCommand $command */
        $command = new $class($this->context);
        $command->setBoardPath($this->boardPath);
        $command->setReviewFilePath($this->reviewFilePath);

        return $command;
    }
}
