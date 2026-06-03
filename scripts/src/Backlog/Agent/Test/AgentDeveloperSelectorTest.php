<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\FakeBacklogCommandRunner;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for AgentDeveloperSelector — active entry detection and first-queued selection.
 */
final class AgentDeveloperSelectorTest
{
    private const MY_FEATURE = 'my-feature';

    private const ALPHA_FEATURE = 'alpha-feature';

    private const BETA_FEATURE = 'beta-feature';

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
        $failed += $this->testPickSkipsNotReservableAndReturnsSecond();
        $failed += $this->testPickReturnsNullWhenAllNotReservable();
        $failed += $this->testPickPropagatesUnexpectedException();

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
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => self::MY_FEATURE,
                'developer' => 'd05',
                'branch' => 'feat/' . self::MY_FEATURE,
            ],
        ]);
        $selector = $this->makeSelector();

        $match = $selector->findOwnedActiveEntry($board, 'd05');

        if ($match === null) {
            echo "FAIL testFindOwnedActiveEntryReturnsMatchWhenAssigned: expected a match, got null\n";
            return 1;
        }
        if ($match->getEntry()->getFeature() !== self::MY_FEATURE) {
            echo "FAIL testFindOwnedActiveEntryReturnsMatchWhenAssigned: unexpected feature '{$match->getEntry()->getFeature()}'\n";
            return 1;
        }
        echo "OK testFindOwnedActiveEntryReturnsMatchWhenAssigned\n";
        return 0;
    }

    private function testFindOwnedActiveEntryIgnoresOtherAgent(): int
    {
        $board = $this->makeBoard([], [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'other-feature',
                'developer' => 'd03',
                'branch' => 'feat/other-feature',
            ],
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
            ['feature' => self::ALPHA_FEATURE, 'type' => 'feat', 'title' => 'First queued task'],
            ['feature' => self::BETA_FEATURE, 'type' => 'feat', 'title' => 'Second queued task'],
        ], []);
        $selector = $this->makeSelector();

        $ref = $selector->selectFirstQueued($board, 'd05');

        if ($ref !== self::ALPHA_FEATURE) {
            echo "FAIL testSelectFirstQueuedReturnsRefOfFirstEntry: expected '" . self::ALPHA_FEATURE . "', got '{$ref}'\n";
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
            ['feature' => self::MY_FEATURE, 'type' => 'feat', 'title' => 'Plain feature task'],
        ], []);
        $selector = $this->makeSelector();

        $ref = $selector->selectFirstQueued($board, 'd05');

        if ($ref !== self::MY_FEATURE) {
            echo "FAIL testSelectFirstQueuedReturnsFeatureSlugRef: expected '" . self::MY_FEATURE . "', got '{$ref}'\n";
            return 1;
        }
        echo "OK testSelectFirstQueuedReturnsFeatureSlugRef\n";
        return 0;
    }

    private function testSelectFirstQueuedReturnsTaskRef(): int
    {
        $board = $this->makeBoard([
            ['feature' => 'parent-feature', 'task' => 'child-task', 'type' => 'tech', 'title' => 'Scoped child task'],
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

    private function testPickSkipsNotReservableAndReturnsSecond(): int
    {
        $board = $this->makeBoard([
            ['feature' => self::ALPHA_FEATURE, 'type' => 'feat', 'title' => 'First entry'],
            ['feature' => self::BETA_FEATURE, 'type' => 'feat', 'title' => 'Second entry'],
        ], []);
        $selector = $this->makeSelector();

        $callCount = 0;
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = static function (string $devCode, string $ref) use (&$callCount): void {
            $callCount++;
            if ($callCount === 1) {
                throw new EntryNotReservableException($ref, 'No queued task found for reference: ' . self::ALPHA_FEATURE);
            }
        };

        $ref = $selector->pick($board, 'd05', $runner);

        if ($ref !== self::BETA_FEATURE) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: expected '" . self::BETA_FEATURE . "', got '{$ref}'\n";
            return 1;
        }
        if ($callCount !== 2) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: expected 2 workStart calls, got {$callCount}\n";
            return 1;
        }
        $refs = array_column(array_filter($runner->calls, static fn ($c) => $c['method'] === 'workStart'), 'entryRef');
        if ($refs !== [self::ALPHA_FEATURE, self::BETA_FEATURE]) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: wrong call order: " . implode(', ', $refs) . "\n";
            return 1;
        }

        echo "OK testPickSkipsNotReservableAndReturnsSecond\n";
        return 0;
    }

    private function testPickReturnsNullWhenAllNotReservable(): int
    {
        $board = $this->makeBoard([
            ['feature' => self::ALPHA_FEATURE, 'type' => 'feat', 'title' => 'First entry'],
            ['feature' => self::BETA_FEATURE, 'type' => 'feat', 'title' => 'Second entry'],
        ], []);
        $selector = $this->makeSelector();

        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = static function (string $devCode, string $ref): void {
            throw new EntryNotReservableException($ref, 'No queued task found for reference: ' . $ref);
        };

        $threw = false;
        try {
            $ref = $selector->pick($board, 'd05', $runner);
        } catch (\Throwable $e) {
            $threw = true;
        }

        if ($threw) {
            echo "FAIL testPickReturnsNullWhenAllNotReservable: expected null return, got exception\n";
            return 1;
        }
        if ($ref !== null) {
            echo "FAIL testPickReturnsNullWhenAllNotReservable: expected null, got '{$ref}'\n";
            return 1;
        }

        echo "OK testPickReturnsNullWhenAllNotReservable\n";
        return 0;
    }

    private function testPickPropagatesUnexpectedException(): int
    {
        $board = $this->makeBoard([
            ['feature' => self::ALPHA_FEATURE, 'type' => 'feat', 'title' => 'First entry'],
            ['feature' => self::BETA_FEATURE, 'type' => 'feat', 'title' => 'Second entry'],
        ], []);
        $selector = $this->makeSelector();

        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = static function (): void {
            throw new \RuntimeException('Filesystem permission denied');
        };

        $callCount = 0;
        $caughtMessage = null;
        try {
            $selector->pick($board, 'd05', $runner);
        } catch (\RuntimeException $e) {
            $caughtMessage = $e->getMessage();
            $callCount = count($runner->calls);
        }

        if ($caughtMessage !== 'Filesystem permission denied') {
            echo "FAIL testPickPropagatesUnexpectedException: expected propagation, got: " . ($caughtMessage ?? 'null') . "\n";
            return 1;
        }
        if ($callCount !== 1) {
            echo "FAIL testPickPropagatesUnexpectedException: expected 1 call before propagation, got {$callCount}\n";
            return 1;
        }

        echo "OK testPickPropagatesUnexpectedException\n";
        return 0;
    }

    private function makeSelector(): AgentDeveloperSelector
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        return new AgentDeveloperSelector($boardService);
    }

    /**
     * Builds a BacklogBoard from structured YAML fixtures.
     *
     * @param list<array<string, mixed>> $todoEntries
     * @param list<array<string, mixed>> $activeEntries
     */
    private function makeBoard(array $todoEntries, array $activeEntries): BacklogBoard
    {
        $boardPath = $this->tmpDir . '/board-' . uniqid('', true) . '.yaml';

        $order = ['kind', 'stage', 'feature', 'task', 'developer', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'blocked', 'type'];
        $todo = array_map(static function (array $e): array {
            $item = ['feature' => $e['feature']];
            if (array_key_exists('task', $e)) {
                $item['task'] = $e['task'];
            }
            if (array_key_exists('type', $e)) {
                $item['type'] = $e['type'];
            }
            $item['title'] = $e['title'] ?? $e['feature'];

            return $item;
        }, $todoEntries);

        $active = array_map(static function (array $e) use ($order): array {
            $item = [];
            foreach ($order as $key) {
                if (array_key_exists($key, $e)) {
                    $item[$key] = $e[$key];
                }
            }
            $item['title'] = $e['title'] ?? ($e['feature'] ?? '');

            return $item;
        }, $activeEntries);

        file_put_contents($boardPath, Yaml::dump([
            'version' => 1,
            'todo' => $todo,
            'active' => $active,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

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
