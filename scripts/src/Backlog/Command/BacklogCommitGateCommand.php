<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
/**
 * Guards git commits in managed worktrees: exits 0 when the active entry is in development stage,
 * exits non-zero with a descriptive message otherwise. Called by the pre-commit hook only.
 */
final class BacklogCommitGateCommand extends AbstractBacklogCommand
{
    private BacklogPermissionService $permissionService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogPermissionService $permissionService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogPermissionService $permissionService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->permissionService = $permissionService;
    }

    /**
     * Checks the active entry stage for the agent identified by SOMANAGER_AGENT.
     * Throws when the commit must be blocked; returns normally when allowed.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->permissionService->requireWorkflowAgent();
        $board = $this->loadBoard();

        $taskMatches = $this->boardService->findTaskEntriesByAgent($board, $agent);
        $featureMatches = $this->boardService->findFeatureEntriesByAgent($board, $agent);

        $entry = ($taskMatches[0] ?? null)?->getEntry() ?? ($featureMatches[0] ?? null)?->getEntry();

        if ($entry === null) {
            throw new \RuntimeException(sprintf(
                "❌ Commit blocked: no active backlog entry found for agent %s.\n" .
                "   Ensure the entry was started via start and is still active.",
                $agent,
            ));
        }

        $stage = $entry->getStage();

        if ($stage === BacklogBoard::STAGE_IN_PROGRESS) {
            return;
        }

        throw new \RuntimeException(match ($stage) {
            BacklogBoard::STAGE_PENDING_REVIEW =>
                "❌ Commit blocked: entry is in stage 'review'.\n" .
                "   The entry is under review. Wait for reviewer feedback.\n" .
                "   To make a fix before the reviewer acts, run `rework` first.",
            BacklogBoard::STAGE_REVIEWING =>
                "❌ Commit blocked: entry is in stage 'reviewing'.\n" .
                "   A reviewer has claimed the entry. Wait for their feedback.\n" .
                "   Committing now would change the code being reviewed.",
            BacklogBoard::STAGE_REJECTED =>
                "❌ Commit blocked: entry is in stage 'rejected'.\n" .
                "   Run `rework` to transition back to development before committing.",
            BacklogBoard::STAGE_APPROVED =>
                "❌ Commit blocked: entry is in stage 'approved'.\n" .
                "   The entry has been approved. Run `rework` if further changes are needed.",
            default => sprintf(
                "❌ Commit blocked: entry is in unexpected stage '%s'.\n" .
                "   Only the 'development' stage allows commits.",
                $stage,
            ),
        });
    }
}
