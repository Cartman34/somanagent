<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Command for requesting a review for a feature.
 */
final class BacklogFeatureReviewRequestCommand extends AbstractBacklogCommand
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
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($commandArgs[0]))
            : $this->boardService->resolveSingleFeatureForAgent($board, $agent);

        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '-';
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-review-request only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, (string) $feature, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);
        
        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.');
        }
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $worktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $this->worktreeService->runReviewScript($worktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf('Feature %s moved to %s', $feature, $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW)));
    }
}
