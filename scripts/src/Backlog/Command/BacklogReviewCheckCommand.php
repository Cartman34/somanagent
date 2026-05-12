<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\FilesystemClientInterface;

/**
 * Reviewer command that runs the mechanical review for a feature or task.
 *
 * Carries the feature and task logic directly. Short task references (bare task slug without the
 * parent feature) are refused. On mechanical failure the entry is automatically rejected with a
 * synthetic body, mirroring the behavior of `review-reject`.
 */
final class BacklogReviewCheckCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;
    private FilesystemClientInterface $fs;
    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param FilesystemClientInterface $fs
     * @param BacklogReviewBodyFormatter $reviewBodyFormatter
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        FilesystemClientInterface $fs,
        BacklogReviewBodyFormatter $reviewBodyFormatter
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->fs = $fs;
        $this->reviewBodyFormatter = $reviewBodyFormatter;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $reviewer = $options['agent'] ?? null;
        if (!is_string($reviewer) || $reviewer === '') {
            throw new \RuntimeException('review-check requires --agent=<reviewer>.');
        }

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-check requires <feature> or <feature/task>.');
        }

        $board = $this->loadBoard();

        if (str_contains($reference, '/')) {
            $match = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_CHECK->value);
            $entry = $match->getEntry();
            $this->assertStageAllowsCheck($entry);

            $reviewKey = $this->boardService->getTaskReviewKey($entry);
            $this->runMechanicalCheck($board, $entry, $reviewKey, sprintf('task %s', $reviewKey));

            return;
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-check refuses short task reference `%s`; use `<feature/task>` instead.',
                    $slug,
                ));
            }
        }

        $match = $this->boardService->resolveFeature($board, $slug);
        $entry = $match->getEntry();
        $this->assertStageAllowsCheck($entry);

        $this->runMechanicalCheck($board, $entry, $slug, sprintf('feature %s', $slug));
    }

    /**
     * Refuses unless the entry is in review or reviewing stage.
     */
    private function assertStageAllowsCheck(BoardEntry $entry): void
    {
        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage === BacklogBoard::STAGE_IN_REVIEW || $stage === BacklogBoard::STAGE_REVIEWING) {
            return;
        }

        $label = $this->boardService->checkIsTaskEntry($entry)
            ? sprintf('Task %s', $this->boardService->getTaskReviewKey($entry))
            : sprintf('Feature %s', $entry->getFeature() ?? '');

        throw new \RuntimeException(sprintf(
            '%s must be in %s or %s to be checked.',
            $label,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
        ));
    }

    /**
     * Runs the mechanical review for the given entry, displaying any saved result first. On failure
     * the entry is auto-rejected with a synthetic body.
     */
    private function runMechanicalCheck(BacklogBoard $board, BoardEntry $entry, string $reviewKey, string $label): void
    {
        $reviewWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $savedResult = $this->worktreeService->loadReviewResult($reviewWorktree);

        if ($savedResult !== null) {
            echo rtrim($savedResult) . "\n";
            $this->presenter->displaySuccess(sprintf('Mechanical review passed for %s', $label));

            return;
        }

        try {
            $this->worktreeService->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $this->autoRejectAfterMechanicalFailure($board, $entry, $reviewKey);

            throw $exception;
        }

        $this->presenter->displaySuccess(sprintf('Mechanical review passed for %s', $label));
    }

    /**
     * Writes a synthetic rejection body, applies the rejection to the in-memory board and review file,
     * persists both, then removes the body file. The board and entry come from the original handle()
     * load so we mutate the same instance we already validated.
     */
    private function autoRejectAfterMechanicalFailure(BacklogBoard $board, BoardEntry $entry, string $reviewKey): void
    {
        $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting again.';
        $tempBodyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'somanagent-review-' . bin2hex(random_bytes(8));
        $this->fs->writeFilePath($tempBodyFile, $message);

        try {
            $review = $this->loadReviewFile();
            $entry->setStage(BacklogBoard::STAGE_REJECTED);
            $entry->setReviewer(null);
            $review->setReview($reviewKey, $this->reviewBodyFormatter->fromFile($tempBodyFile));
            $this->saveBoard($board, BacklogCommandName::REVIEW_CHECK->value);
            $this->saveReviewFile($review, BacklogCommandName::REVIEW_CHECK->value);
        } finally {
            $this->fs->removePath($tempBodyFile);
        }
    }
}
