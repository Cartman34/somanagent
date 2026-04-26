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

    private BacklogPresenter $presenter;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->worktreeManager = $context->getWorktreeManager();
        $this->presenter = $context->getPresenter();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $classification = $this->worktreeManager->classifyWorktrees($board);

        $this->presenter->displayWorktreeList($classification);
    }
}
