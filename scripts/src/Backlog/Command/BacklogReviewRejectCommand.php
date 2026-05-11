<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Unified command for rejecting a feature or task review and recording reviewer blockers.
 *
 * Delegates to feature-review-reject or task-review-reject based on the reference kind.
 * Short task references (bare task slug without the parent feature) are refused.
 */
final class BacklogReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogCommandFactory $commandFactory;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogCommandFactory $commandFactory
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogCommandFactory $commandFactory
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->commandFactory = $commandFactory;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $reviewer = $options['agent'] ?? null;
        if (!is_string($reviewer) || $reviewer === '') {
            throw new \RuntimeException('review-reject requires --agent=<reviewer>.');
        }

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-reject requires <feature> or <feature/task>.');
        }

        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile) || $bodyFile === '') {
            throw new \RuntimeException('review-reject requires --body-file=<path>.');
        }

        $delegatedOptions = ['body-file' => $bodyFile];

        if (str_contains($reference, '/')) {
            $this->commandFactory->getTaskReviewRejectCommand()->performReject([$reference], $delegatedOptions);

            return;
        }

        $board = $this->loadBoard();
        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-reject refuses short task reference `%s`; use `<feature/task>` instead.',
                    $slug,
                ));
            }
        }

        $this->commandFactory->getFeatureReviewRejectCommand()->performReject([$reference], $delegatedOptions);
    }
}
