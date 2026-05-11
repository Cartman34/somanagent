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
 * Unified command for approving a feature or task review.
 *
 * Delegates to feature-review-approve or task-review-approve based on the reference kind.
 * Short task references (bare task slug without the parent feature) are refused.
 * --body-file is required for feature approvals and rejected for task approvals.
 */
final class BacklogReviewApproveCommand extends AbstractBacklogCommand
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
            throw new \RuntimeException('review-approve requires --agent=<reviewer>.');
        }

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-approve requires <feature> or <feature/task>.');
        }

        if (str_contains($reference, '/')) {
            if (array_key_exists('body-file', $options)) {
                throw new \RuntimeException('review-approve does not accept --body-file for task approvals.');
            }

            $this->commandFactory->createHandler(BacklogCommandName::TASK_REVIEW_APPROVE->value)->handle([$reference], []);

            return;
        }

        $board = $this->loadBoard();
        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-approve refuses short task reference `%s`; use `<feature/task>` instead.',
                    $slug,
                ));
            }
        }

        $delegatedOptions = [];
        if (array_key_exists('body-file', $options)) {
            $delegatedOptions['body-file'] = $options['body-file'];
        }

        $this->commandFactory->createHandler(BacklogCommandName::FEATURE_REVIEW_APPROVE->value)->handle([$reference], $delegatedOptions);
    }
}
