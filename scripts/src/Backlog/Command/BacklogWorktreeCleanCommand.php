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
 * Command for cleaning abandoned managed worktrees.
 */
final class BacklogWorktreeCleanCommand extends AbstractBacklogCommand
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
     * Removes abandoned managed worktrees and reports the result.
     *
     * @param array<string> $commandArgs
     * @param array<string, mixed> $options
     */
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
