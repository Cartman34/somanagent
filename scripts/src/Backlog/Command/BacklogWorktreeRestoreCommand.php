<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;

/**
 * Command for restoring an agent worktree.
 */
final class BacklogWorktreeRestoreCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
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
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $agent = $this->boardService->sanitizeString((string) ($options[BacklogCliOption::DEVELOPER->value] ?? ''));
        if ($agent === null) {
            throw new \RuntimeException('worktree-restore requires --developer=<code>.');
        }

        $taskMatch = $this->boardService->findTaskEntriesByAgent($board, $agent)[0] ?? null;
        $featureMatch = $this->boardService->findFeatureEntriesByAgent($board, $agent)[0] ?? null;
        $match = $taskMatch ?? $featureMatch;

        if ($match === null) {
            throw new \RuntimeException("Developer {$agent} has no active task or feature.");
        }

        $entry = $match->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException("Developer {$agent} has an active entry but no branch metadata.");
        }

        if (isset($options[BacklogCliOption::FORCE->value])) {
            $this->worktreeService->removeAgentWorktreeForRestore($agent);
        }

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, false);

        $this->presenter->displaySuccess(sprintf('Restored worktree for agent %s on branch %s', $agent, $branch));
    }
}
