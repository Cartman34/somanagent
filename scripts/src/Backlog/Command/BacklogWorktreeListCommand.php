<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for listing managed and external worktrees.
 */
final class BacklogWorktreeListCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->worktreeManager = $worktreeManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $classification = $this->worktreeManager->classifyWorktrees($board);

        $this->presenter->displayWorktreeList($classification);
    }
}
