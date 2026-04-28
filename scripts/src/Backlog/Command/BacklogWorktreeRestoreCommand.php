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
 * Command for restoring an agent worktree.
 */
final class BacklogWorktreeRestoreCommand extends AbstractBacklogCommand
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
        $agent = $this->boardService->sanitizeString((string) ($options['agent'] ?? ''));
        if ($agent === null) {
            throw new \RuntimeException('worktree-restore requires --agent=<code>.');
        }

        $taskMatch = $this->boardService->findTaskEntriesByAgent($board, $agent)[0] ?? null;
        $featureMatch = $this->boardService->findFeatureEntriesByAgent($board, $agent)[0] ?? null;
        $match = $taskMatch ?? $featureMatch;

        if ($match === null) {
            throw new \RuntimeException("Agent {$agent} has no active task or feature.");
        }

        $entry = $match->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException("Agent {$agent} has an active entry but no branch metadata.");
        }

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, false);

        $this->presenter->displaySuccess(sprintf('Restored worktree for agent %s on branch %s', $agent, $branch));
    }
}
