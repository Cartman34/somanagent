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
use RuntimeException;

/**
 * Command for claiming the next item to review and transitioning it to the reviewing stage.
 */
final class BacklogReviewNextCommand extends AbstractBacklogCommand
{
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent) || $agent === '') {
            throw new RuntimeException('review-next requires --agent=<reviewer>.');
        }

        $board = $this->loadBoard();

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($entry->getReviewer() === $agent) {
                throw new RuntimeException(sprintf(
                    'Reviewer %s already has an entry in %s. Run review-cancel to release it first.',
                    $agent,
                    $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
                ));
            }
        }

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $entry->setStage(BacklogBoard::STAGE_REVIEWING);
            $entry->setReviewer($agent);
            $this->saveBoard($board, BacklogCommandName::REVIEW_NEXT->value);

            $this->presenter->displayEntryStatus($entry);

            return;
        }

        throw new RuntimeException('No task or feature available in ' . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }
}
