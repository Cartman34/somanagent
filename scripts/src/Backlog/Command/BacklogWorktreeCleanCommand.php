<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Command for cleaning abandoned managed worktrees.
 */
final class BacklogWorktreeCleanCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

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

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $cleaned = $this->worktreeService->cleanupAbandonedManagedWorktrees($board);

        if ($cleaned === 0) {
            $this->presenter->displayLine('No abandoned managed worktree to clean.');
        } else {
            $this->presenter->displaySuccess(sprintf(
                '%s %d abandoned managed worktree%s',
                $this->dryRun ? 'Would clean' : 'Cleaned',
                $cleaned,
                $cleaned > 1 ? 's' : '',
            ));
        }

        $classification = $this->worktreeService->classifyWorktrees($board);
        $skipped = count($classification->getManaged());
        if ($skipped > 0) {
            $this->presenter->displayLine(sprintf('Skipped %d managed worktree%s that require manual attention.', $skipped, $skipped > 1 ? 's' : ''));
        }
    }
}
