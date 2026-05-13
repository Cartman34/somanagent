<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

/**
 * Dedicated tests for BacklogBoardService reviewer ownership lookup.
 */
final class BacklogBoardServiceReviewingTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testFindReviewingEntryByReviewerReturnsMatchingEntry();
        $failed += $this->testFindReviewingEntryByReviewerIgnoresReviewStageAndOtherReviewer();

        return $failed;
    }

    private function testFindReviewingEntryByReviewerReturnsMatchingEntry(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $board = new BacklogBoard('/tmp/board.md', '# Test backlog');
        $target = $this->entry('target-feature', 'reviewing', 'r02');
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [
            $this->entry('other-feature', 'reviewing', 'r01'),
            $target,
        ]);

        $match = $service->findReviewingEntryByReviewer($board, 'r02');

        if ($match === null || $match->getIndex() !== 1 || $match->getEntry() !== $target) {
            echo "FAIL testFindReviewingEntryByReviewerReturnsMatchingEntry: expected index 1 target entry\n";
            return 1;
        }

        echo "OK testFindReviewingEntryByReviewerReturnsMatchingEntry\n";
        return 0;
    }

    private function testFindReviewingEntryByReviewerIgnoresReviewStageAndOtherReviewer(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $board = new BacklogBoard('/tmp/board.md', '# Test backlog');
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [
            $this->entry('review-feature', 'review', 'r02'),
            $this->entry('claimed-feature', 'reviewing', 'r03'),
        ]);

        $match = $service->findReviewingEntryByReviewer($board, 'r02');

        if ($match !== null) {
            echo "FAIL testFindReviewingEntryByReviewerIgnoresReviewStageAndOtherReviewer: expected null\n";
            return 1;
        }

        echo "OK testFindReviewingEntryByReviewerIgnoresReviewStageAndOtherReviewer\n";
        return 0;
    }

    private function entry(string $feature, string $stage, ?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry($feature);
        $entry->setKind(BacklogBoardService::ENTRY_KIND_FEATURE);
        $entry->setFeature($feature);
        $entry->setStage($stage);
        $entry->setAgent('d01');
        $entry->setReviewer($reviewer);

        return $entry;
    }
}
