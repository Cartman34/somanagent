<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
/**
 * Command for listing managed and external worktrees.
 */
final class BacklogWorktreeListCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    /**
     * Injects the worktree service alongside the parent dependencies.
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
    }

    /**
     * Classifies all known worktrees and displays the result.
     *
     * @param array<string> $commandArgs
     * @param array<string, mixed> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $classification = $this->worktreeService->classifyWorktrees($board);

        $this->presenter->displayWorktreeList($classification);
    }
}
