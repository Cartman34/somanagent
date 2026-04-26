<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Console;

/**
 * Command for displaying the next item to review.
 */
final class BacklogReviewNextCommand extends AbstractBacklogCommand
{
    private BacklogEntryService $entryService;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryService $entryService
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryService = $entryService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $this->printEntryStatus($entry);

            return;
        }

        throw new \RuntimeException('No task or feature available in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }

    private function printEntryStatus(BoardEntry $entry): void
    {
        $stage = $this->entryService->featureStage($entry);
        $this->console->line('Kind: ' . $this->entryService->entryKind($entry));
        if ($this->entryService->isTaskEntry($entry)) {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
            $this->console->line('Task: ' . ($entry->getTask() ?? '-'));
            $this->console->line('Ref: ' . $this->entryService->taskReviewKey($entry));
            $this->console->line('Feature Branch: ' . ($entry->getFeatureBranch() ?? '-'));
        } else {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        }
        $this->console->line('Branch: ' . ($entry->getBranch() ?? '-'));
        $this->console->line('Base: ' . ($entry->getBase() ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
        $this->console->line('Blocker: ' . ($entry->isBlocked() ? 'blocked' : '-'));
    }

    private function printEntryStatusDetails(BoardEntry $entry): void
    {
        $extraLines = $entry->getExtraLines();
        if ($extraLines === []) {
            return;
        }

        $this->console->line('Details:');
        foreach ($extraLines as $line) {
            $this->console->line($line);
        }
    }

    private function describePrStatus(BoardEntry $entry): string
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return BacklogMetaValue::NONE->value;
        }

        return '#' . $pr;
    }
}
