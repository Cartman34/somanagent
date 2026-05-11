<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Unified command for running reviewer mechanical checks on a feature or task.
 *
 * Delegates to feature-review-check or task-review-check based on the reference kind.
 * Short task references (bare task slug without the parent feature) are refused.
 */
final class BacklogReviewCheckCommand extends AbstractBacklogCommand
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
            throw new \RuntimeException('review-check requires --agent=<reviewer>.');
        }

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-check requires <feature> or <feature/task>.');
        }

        if (str_contains($reference, '/')) {
            $this->commandFactory->createHandler(BacklogCommandName::TASK_REVIEW_CHECK->value)->handle([$reference], []);

            return;
        }

        $board = $this->loadBoard();
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

        $this->commandFactory->createHandler(BacklogCommandName::FEATURE_REVIEW_CHECK->value)->handle([$reference], []);
    }
}
