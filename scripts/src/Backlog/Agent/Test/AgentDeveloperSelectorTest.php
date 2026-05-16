<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for AgentDeveloperSelector — active entry detection and first-queued selection.
 */
final class AgentDeveloperSelectorTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-developer-selector-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on cleanup.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testFindOwnedActiveEntryReturnsNullWhenNoneAssigned();
        $failed += $this->testFindOwnedActiveEntryReturnsMatchWhenAssigned();
        $failed += $this->testFindOwnedActiveEntryIgnoresOtherAgent();
        $failed += $this->testSelectFirstQueuedReturnsRefOfFirstEntry();
        $failed += $this->testSelectFirstQueuedThrowsWhenTodoEmpty();
        $failed += $this->testSelectFirstQueuedReturnsFeatureSlugRef();
        $failed += $this->testSelectFirstQueuedReturnsTaskRef();

        return $failed;
    }

    private function testFindOwnedActiveEntryReturnsNullWhenNoneAssigned(): int
    {
        $board = $this->makeBoard([], []);
        $selector = $this->makeSelector();

        $match = $selector->findOwnedActiveEntry($board, 'd05');

        if ($match !== null) {
            echo "FAIL testFindOwnedActiveEntryReturnsNullWhenNoneAssigned: expected null, got a match\n";
            return 1;
        }
        echo "OK testFindOwnedActiveEntryReturnsNullWhenNoneAssigned\n";
        return 0;
    }

    private function testFindOwnedActiveEntryReturnsMatchWhenAssigned(): int
    {
        $board = $this->makeBoard([], [
            '- my-feature',
            '  meta:',
            '    kind: feature',
            '    feature: my-feature',
            '    branch: feat/my-feature',
            '    stage: development',
            '    agent: d05',
        ]);
        $selector = $this->makeSelector();

        $match = $selector->findOwnedActiveEntry($board, 'd05');

        if ($match === null) {
            echo "FAIL testFindOwnedActiveEntryReturnsMatchWhenAssigned: expected a match, got null\n";
            return 1;
        }
        if ($match->getEntry()->getFeature() !== 'my-feature') {
            echo "FAIL testFindOwnedActiveEntryReturnsMatchWhenAssigned: unexpected feature '{$match->getEntry()->getFeature()}'\n";
            return 1;
        }
        echo "OK testFindOwnedActiveEntryReturnsMatchWhenAssigned\n";
        return 0;
    }

    private function testFindOwnedActiveEntryIgnoresOtherAgent(): int
    {
        $board = $this->makeBoard([], [
            '- other-feature',
            '  meta:',
            '    kind: feature',
            '    feature: other-feature',
            '    branch: feat/other-feature',
            '    stage: development',
            '    agent: d03',
        ]);
        $selector = $this->makeSelector();

        $match = $selector->findOwnedActiveEntry($board, 'd05');

        if ($match !== null) {
            echo "FAIL testFindOwnedActiveEntryIgnoresOtherAgent: expected null, got a match for d03's entry\n";
            return 1;
        }
        echo "OK testFindOwnedActiveEntryIgnoresOtherAgent\n";
        return 0;
    }

    private function testSelectFirstQueuedReturnsRefOfFirstEntry(): int
    {
        $board = $this->makeBoard([
            '- [feat][alpha-feature] First queued task',
            '- [feat][beta-feature] Second queued task',
        ], []);
        $selector = $this->makeSelector();

        $ref = $selector->selectFirstQueued($board, 'd05');

        if ($ref !== 'alpha-feature') {
            echo "FAIL testSelectFirstQueuedReturnsRefOfFirstEntry: expected 'alpha-feature', got '{$ref}'\n";
            return 1;
        }
        echo "OK testSelectFirstQueuedReturnsRefOfFirstEntry\n";
        return 0;
    }

    private function testSelectFirstQueuedThrowsWhenTodoEmpty(): int
    {
        $board = $this->makeBoard([], []);
        $selector = $this->makeSelector();

        $threw = false;
        try {
            $selector->selectFirstQueued($board, 'd05');
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'No queued task available for d05');
        }

        if (!$threw) {
            echo "FAIL testSelectFirstQueuedThrowsWhenTodoEmpty: expected empty-todo error\n";
            return 1;
        }
        echo "OK testSelectFirstQueuedThrowsWhenTodoEmpty\n";
        return 0;
    }

    private function testSelectFirstQueuedReturnsFeatureSlugRef(): int
    {
        $board = $this->makeBoard([
            '- [feat][my-feature] Plain feature task',
        ], []);
        $selector = $this->makeSelector();

        $ref = $selector->selectFirstQueued($board, 'd05');

        if ($ref !== 'my-feature') {
            echo "FAIL testSelectFirstQueuedReturnsFeatureSlugRef: expected 'my-feature', got '{$ref}'\n";
            return 1;
        }
        echo "OK testSelectFirstQueuedReturnsFeatureSlugRef\n";
        return 0;
    }

    private function testSelectFirstQueuedReturnsTaskRef(): int
    {
        $board = $this->makeBoard([
            '- [tech][parent-feature][child-task] Scoped child task',
        ], []);
        $selector = $this->makeSelector();

        $ref = $selector->selectFirstQueued($board, 'd05');

        if ($ref !== 'parent-feature/child-task') {
            echo "FAIL testSelectFirstQueuedReturnsTaskRef: expected 'parent-feature/child-task', got '{$ref}'\n";
            return 1;
        }
        echo "OK testSelectFirstQueuedReturnsTaskRef\n";
        return 0;
    }

    private function makeSelector(): AgentDeveloperSelector
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        return new AgentDeveloperSelector($boardService);
    }

    /**
     * @param list<string> $todoLines
     * @param list<string> $activeLines
     */
    private function makeBoard(array $todoLines, array $activeLines): BacklogBoard
    {
        $boardPath = $this->tmpDir . '/board-' . uniqid('', true) . '.md';
        $content = "# Test backlog\n\n## To do\n\n"
            . implode("\n", $todoLines)
            . "\n\n## In progress\n\n"
            . implode("\n", $activeLines)
            . "\n\n## Suggestions\n";
        file_put_contents($boardPath, $content);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        return $boardService->loadBoard($boardPath);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
