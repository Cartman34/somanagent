<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Console;

/**
 * Command for reworking a rejected task.
 */
final class BacklogTaskReworkCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->entryResolver->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REWORK->value)
            : $this->entryResolver->requireSingleTaskForAgent($board, $agent);
        $entry = $match->getEntry();
        $this->entryService->assertTaskEntry($entry, BacklogCommandName::TASK_REWORK->value);

        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('task-rework requires the task to be assigned to the provided agent.');
        }

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException(sprintf(
                'Task %s is not in the rejected stage.',
                $this->entryService->taskReviewKey($entry),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::TASK_REWORK->value);

        $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
        $this->worktreeManager->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);

        $this->console->ok(sprintf(
            'Task %s moved back to %s',
            $this->entryService->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS),
        ));
    }
}
