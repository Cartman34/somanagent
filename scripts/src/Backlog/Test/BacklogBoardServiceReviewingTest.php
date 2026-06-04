<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Dedicated tests for BacklogBoardService reviewer ownership lookup.
 */
final class BacklogBoardServiceReviewingTest
{
    private const REFERENCE_FEATURE = 'reference-feature';
    private const REFERENCE_TASK = 'child-task';

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testFindReviewingEntryByReviewerReturnsMatchingEntry();
        $failed += $this->testFindReviewingEntryByReviewerIgnoresReviewStageAndOtherReviewer();
        $failed += $this->testEntryReferenceUsesFeatureSlugForFeatures();
        $failed += $this->testEntryReferenceUsesFullReferenceForTasks();
        $failed += $this->testTaskNotFoundSuggestsEntryReferenceForKnownBranch();

        return $failed;
    }

    private function testFindReviewingEntryByReviewerReturnsMatchingEntry(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $board = new BacklogBoard('/tmp/board.md');
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
        $board = new BacklogBoard('/tmp/board.md');
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

    private function testEntryReferenceUsesFeatureSlugForFeatures(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $entry = $this->entry(self::REFERENCE_FEATURE, 'review', 'r01');
        $entry->setBranch('tech/' . self::REFERENCE_FEATURE);

        $reference = $service->getEntryReference($entry);
        if ($reference !== self::REFERENCE_FEATURE) {
            echo "FAIL testEntryReferenceUsesFeatureSlugForFeatures: expected " . self::REFERENCE_FEATURE . ", got {$reference}\n";
            return 1;
        }

        echo "OK testEntryReferenceUsesFeatureSlugForFeatures\n";
        return 0;
    }

    private function testEntryReferenceUsesFullReferenceForTasks(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $entry = $this->taskEntry(self::REFERENCE_FEATURE, self::REFERENCE_TASK, 'review', 'r01');
        $entry->setBranch('tech/' . self::REFERENCE_FEATURE . '--' . self::REFERENCE_TASK);

        $reference = $service->getEntryReference($entry);
        if ($reference !== self::REFERENCE_FEATURE . '/' . self::REFERENCE_TASK) {
            echo "FAIL testEntryReferenceUsesFullReferenceForTasks: expected " . self::REFERENCE_FEATURE . '/' . self::REFERENCE_TASK . ", got {$reference}\n";
            return 1;
        }

        echo "OK testEntryReferenceUsesFullReferenceForTasks\n";
        return 0;
    }

    private function testTaskNotFoundSuggestsEntryReferenceForKnownBranch(): int
    {
        $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $board = new BacklogBoard('/tmp/board.md');
        $entry = $this->taskEntry(self::REFERENCE_FEATURE, self::REFERENCE_TASK, 'review', 'r01');
        $entry->setBranch('tech/' . self::REFERENCE_FEATURE . '--' . self::REFERENCE_TASK);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        try {
            $service->resolveTaskByReference($board, 'tech/' . self::REFERENCE_FEATURE . '--' . self::REFERENCE_TASK, 'review-check');
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Did you mean ' . self::REFERENCE_FEATURE . '/' . self::REFERENCE_TASK . '?')) {
                echo "OK testTaskNotFoundSuggestsEntryReferenceForKnownBranch\n";
                return 0;
            }

            echo "FAIL testTaskNotFoundSuggestsEntryReferenceForKnownBranch: unexpected message {$exception->getMessage()}\n";
            return 1;
        }

        echo "FAIL testTaskNotFoundSuggestsEntryReferenceForKnownBranch: expected RuntimeException\n";
        return 1;
    }

    private function entry(string $feature, string $stage, ?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry($feature);
        $entry->setKind(BacklogBoardService::ENTRY_KIND_FEATURE);
        $entry->setFeature($feature);
        $entry->setStage($stage);
        $entry->setDeveloper('d01');
        $entry->setReviewer($reviewer);

        return $entry;
    }

    private function taskEntry(string $feature, string $task, string $stage, ?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry($task);
        $entry->setKind(BacklogBoardService::ENTRY_KIND_TASK);
        $entry->setFeature($feature);
        $entry->setTask($task);
        $entry->setStage($stage);
        $entry->setDeveloper('d01');
        $entry->setReviewer($reviewer);

        return $entry;
    }
}
