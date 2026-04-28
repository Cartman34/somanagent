<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for closing an active feature.
 */
final class BacklogFeatureCloseCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private PullRequestService $pullRequestService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_CLOSE->value);
        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();

        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-close only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_CLOSE->value);

        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $this->worktreeService->assertBranchHasNoDirtyManagedWorktree($branch);
        $this->gitService->pushBranchIfAhead($branch);

        $prNumber = $this->storedPrNumber($entry);
        if ($prNumber !== null) {
            $this->pullRequestService->closePr($prNumber);
        }

        $this->boardService->deleteFeature($board, $feature);
        $this->boardService->clearAgentReservations($board, $entry->getAgent() ?? '', $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_CLOSE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_CLOSE->value);

        $cleaned = $this->worktreeService->cleanupAbandonedManagedWorktrees($board);

        $this->presenter->displaySuccess(sprintf('Closed feature %s', $feature));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    private function resolveFeatureReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->boardService->normalizeFeatureSlug($commandArgs[0]);
    }

    private function storedPrNumber(BoardEntry $entry): ?int
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return null;
        }

        return (int) $pr;
    }
}
