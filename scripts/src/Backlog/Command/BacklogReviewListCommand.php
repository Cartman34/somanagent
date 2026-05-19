<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Command for listing entries waiting in the review stage, with their stable references.
 *
 * Lists only entries in the `review` stage so reviewers can pick an explicit
 * target through `review-next <entry-ref>`. Entries already in the
 * `reviewing` stage are excluded because they have already been claimed.
 */
final class BacklogReviewListCommand extends AbstractBacklogCommand
{
    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    /**
     * List entries currently in the review stage.
     *
     * Each line is shaped `- <ref> kind=<feature|task> agent=<x> pr=<y>` where
     * <ref> is the stable target usable by `review-next`.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $entries = array_values(array_filter(
            $this->loadBoard()->getEntries(BacklogBoard::SECTION_ACTIVE),
            fn(BoardEntry $entry): bool => $this->boardService->getFeatureStage($entry) === BacklogBoard::STAGE_IN_REVIEW
        ));

        if ($entries === []) {
            $this->presenter->displayLine('No entry waiting in review.');

            return;
        }

        foreach ($entries as $entry) {
            $this->presenter->displayLine($this->formatLine($entry));
        }
    }

    /**
     * Formats one review entry line with its stable reference.
     */
    private function formatLine(BoardEntry $entry): string
    {
        $isTask = $this->boardService->checkIsTaskEntry($entry);
        $feature = $entry->getFeature() ?? '-';
        $reference = $isTask
            ? $feature . '/' . ($entry->getTask() ?? '-')
            : $feature;

        $parts = [
            $reference,
            'kind=' . ($isTask ? BacklogBoardService::ENTRY_KIND_TASK : BacklogBoardService::ENTRY_KIND_FEATURE),
            'developer=' . ($entry->getDeveloper() ?? BacklogMetaValue::NONE->value),
        ];
        if (!$isTask) {
            $pr = $entry->getPr();
            $parts[] = 'pr=' . ($pr === null || $pr === BacklogMetaValue::NONE->value
                ? BacklogMetaValue::NONE->value
                : '#' . $pr);
        }
        if ($entry->checkIsBlocked()) {
            $parts[] = 'blocked=' . BacklogMetaValue::YES->value;
        }

        return '- ' . implode(' ', $parts);
    }
}
