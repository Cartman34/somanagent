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

/**
 * Unified review-request command: submits the agent's single active entry (task or feature) for review.
 */
final class BacklogReviewRequestCommand extends AbstractBacklogCommand
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
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }

        $board = $this->loadBoard();
        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);

        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Agent {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php work-start --agent={$agent}` to start one."
            );
        }

        $entry = $activeEntries[0]->getEntry();

        if ($this->boardService->checkIsTaskEntry($entry)) {
            $this->handleTask($agent, $board, $entry);
        } else {
            $this->handleFeature($agent, $board, $entry);
        }
    }

    private function handleTask(string $agent, BacklogBoard $board, BoardEntry $entry): void
    {
        $review = $this->loadReviewFile();

        $taskWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $this->worktreeService->runReviewScript($taskWorktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $review->clearReview($this->boardService->getTaskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Task %s moved to %s',
            $this->boardService->getTaskReviewKey($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));
    }

    private function handleFeature(string $agent, BacklogBoard $board, BoardEntry $entry): void
    {
        $feature = $entry->getFeature() ?? '-';

        $this->boardService->assertNoActiveTasksForFeature($board, (string) $feature, BacklogCommandName::REVIEW_REQUEST->value);

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException(
                "Feature {$feature} must be in " . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.'
            );
        }
        $featureAgent = $entry->getAgent();
        if ($featureAgent === null || $featureAgent === BacklogMetaValue::NONE->value) {
            throw new \RuntimeException(
                "Feature {$feature} has no assigned developer.\n" .
                "Run `php scripts/backlog.php feature-assign --agent={$agent} {$feature}` to take ownership before submitting for review."
            );
        }
        if ($featureAgent !== $agent) {
            throw new \RuntimeException(
                "Feature {$feature} is assigned to agent {$featureAgent}, not {$agent}.\n" .
                "Details: php scripts/backlog.php status --agent={$agent}"
            );
        }

        $worktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $this->worktreeService->runReviewScript($worktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Feature %s moved to %s',
            $feature,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));
    }
}
