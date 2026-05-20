<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Storage\BoardYamlStorage;

/**
 * Unit tests for {@see BoardYamlStorage}.
 *
 * Covers the YAML round-trip contract of the backlog board storage:
 * empty board, active entry full/partial fields, todo entry shapes,
 * body lines, extra metadata, blocked flag, field ordering, and
 * the initialContent() helper used by the runner bootstrap.
 */
final class BoardYamlStorageTest
{
    private const FEATURE_SLUG = 'my-feature';
    private const SCOPED_FEATURE = 'parent-feature';
    private const SCOPED_TASK = 'child-task';
    private const FULL_FEATURE = 'crypto-feature';

    private string $tmpDir;

    /**
     * Creates a temporary directory for board fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/board-yaml-storage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on cleanup.
     */
    public function __destruct()
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;
        $failed += $this->testInitialContentIsEmptySkeleton();
        $failed += $this->testEmptyBoardRoundTrip();
        $failed += $this->testTodoPlainFeatureRoundTrip();
        $failed += $this->testTodoScopedTaskRoundTrip();
        $failed += $this->testActiveEntryFullFieldsRoundTrip();
        $failed += $this->testActiveEntryNullFieldsOmitted();
        $failed += $this->testBodyLinesRoundTrip();
        $failed += $this->testExtraMetadataRoundTrip();
        $failed += $this->testBlockedFlagRoundTrip();
        $failed += $this->testActiveFieldOrderingPreserved();

        return $failed;
    }

    private function testInitialContentIsEmptySkeleton(): int
    {
        $content = BoardYamlStorage::initialContent();
        if (!str_contains($content, 'version: 1')
            || !str_contains($content, 'todo: []')
            || !str_contains($content, 'active: []')) {
            echo "FAIL testInitialContentIsEmptySkeleton: unexpected content:\n{$content}\n";
            return 1;
        }
        echo "OK testInitialContentIsEmptySkeleton\n";
        return 0;
    }

    private function testEmptyBoardRoundTrip(): int
    {
        $path = $this->tmpDir . '/empty-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        if ($board->getEntries(BacklogBoard::SECTION_TODO) !== []
            || $board->getEntries(BacklogBoard::SECTION_ACTIVE) !== []) {
            echo "FAIL testEmptyBoardRoundTrip: expected empty sections\n";
            return 1;
        }

        $storage->save($board);
        $reloaded = $storage->load($path);
        if ($reloaded->getEntries(BacklogBoard::SECTION_TODO) !== []
            || $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE) !== []) {
            echo "FAIL testEmptyBoardRoundTrip: round-trip lost emptiness\n";
            return 1;
        }
        echo "OK testEmptyBoardRoundTrip\n";
        return 0;
    }

    private function testTodoPlainFeatureRoundTrip(): int
    {
        $path = $this->tmpDir . '/todo-plain-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Plain feature title');
        $entry->setFeature(self::FEATURE_SLUG);
        $entry->setType('feat');
        $board->setEntries(BacklogBoard::SECTION_TODO, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $entries = $reloaded->getEntries(BacklogBoard::SECTION_TODO);

        if (count($entries) !== 1) {
            echo "FAIL testTodoPlainFeatureRoundTrip: expected 1 entry, got " . count($entries) . "\n";
            return 1;
        }
        $r = $entries[0];
        if ($r->getFeature() !== self::FEATURE_SLUG
            || $r->getType() !== 'feat'
            || $r->getText() !== 'Plain feature title'
            || $r->getTask() !== null) {
            echo "FAIL testTodoPlainFeatureRoundTrip: lost field on reload\n";
            return 1;
        }
        echo "OK testTodoPlainFeatureRoundTrip\n";
        return 0;
    }

    private function testTodoScopedTaskRoundTrip(): int
    {
        $path = $this->tmpDir . '/todo-scoped-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Scoped child task title');
        $entry->setFeature(self::SCOPED_FEATURE);
        $entry->setTask(self::SCOPED_TASK);
        $entry->setType('tech');
        $entry->setDeveloper('d05');
        $board->setEntries(BacklogBoard::SECTION_TODO, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $r = $reloaded->getEntries(BacklogBoard::SECTION_TODO)[0];

        if ($r->getFeature() !== self::SCOPED_FEATURE
            || $r->getTask() !== self::SCOPED_TASK
            || $r->getType() !== 'tech'
            || $r->getDeveloper() !== 'd05'
            || $r->getText() !== 'Scoped child task title') {
            echo "FAIL testTodoScopedTaskRoundTrip: lost field on reload\n";
            return 1;
        }
        echo "OK testTodoScopedTaskRoundTrip\n";
        return 0;
    }

    private function testActiveEntryFullFieldsRoundTrip(): int
    {
        $path = $this->tmpDir . '/active-full-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Feature in review');
        $entry->setKind('feature');
        $entry->setStage('reviewing');
        $entry->setFeature(self::FULL_FEATURE);
        $entry->setDeveloper('d04');
        $entry->setReviewer('r01');
        $entry->setBranch('feat/' . self::FULL_FEATURE);
        $entry->setBase('abc123def456');
        $entry->setPr(BacklogMetaValue::NONE->value);
        $entry->setType('feat');
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $r = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0];

        if ($r->getKind() !== 'feature'
            || $r->getStage() !== 'reviewing'
            || $r->getFeature() !== self::FULL_FEATURE
            || $r->getDeveloper() !== 'd04'
            || $r->getReviewer() !== 'r01'
            || $r->getBranch() !== 'feat/crypto-feature'
            || $r->getBase() !== 'abc123def456'
            || $r->getPr() !== BacklogMetaValue::NONE->value
            || $r->getType() !== 'feat'
            || $r->getText() !== 'Feature in review') {
            echo "FAIL testActiveEntryFullFieldsRoundTrip: lost field on reload\n";
            return 1;
        }
        echo "OK testActiveEntryFullFieldsRoundTrip\n";
        return 0;
    }

    private function testActiveEntryNullFieldsOmitted(): int
    {
        $path = $this->tmpDir . '/active-null-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Plain title');
        $entry->setKind('feature');
        $entry->setStage('development');
        $entry->setFeature(self::FEATURE_SLUG);
        $entry->setBranch('feat/' . self::FEATURE_SLUG);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        $storage->save($board);
        $raw = (string) file_get_contents($path);

        if (str_contains($raw, 'developer:')
            || str_contains($raw, 'reviewer:')
            || str_contains($raw, 'task:')
            || str_contains($raw, 'pr:')) {
            echo "FAIL testActiveEntryNullFieldsOmitted: null fields not omitted:\n{$raw}\n";
            return 1;
        }
        echo "OK testActiveEntryNullFieldsOmitted\n";
        return 0;
    }

    private function testBodyLinesRoundTrip(): int
    {
        $path = $this->tmpDir . '/body-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Title with body', [
            '  - Sub-item one',
            '  - Sub-item two',
        ]);
        $entry->setFeature(self::FEATURE_SLUG);
        $board->setEntries(BacklogBoard::SECTION_TODO, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $r = $reloaded->getEntries(BacklogBoard::SECTION_TODO)[0];

        $lines = $r->getExtraLines();
        if ($lines !== ['  - Sub-item one', '  - Sub-item two']) {
            echo "FAIL testBodyLinesRoundTrip: extraLines mismatch: " . json_encode($lines) . "\n";
            return 1;
        }
        echo "OK testBodyLinesRoundTrip\n";
        return 0;
    }

    private function testExtraMetadataRoundTrip(): int
    {
        $path = $this->tmpDir . '/extra-meta-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Active with extra meta');
        $entry->setKind('feature');
        $entry->setStage('development');
        $entry->setFeature('db-feature');
        $entry->setBranch('feat/db-feature');
        $entry->setExtraMetadata(['database' => 'test_db_v1']);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $r = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0];

        $extras = $r->getExtraMetadata();
        if (($extras['database'] ?? null) !== 'test_db_v1') {
            echo "FAIL testExtraMetadataRoundTrip: extra metadata lost: " . json_encode($extras) . "\n";
            return 1;
        }
        echo "OK testExtraMetadataRoundTrip\n";
        return 0;
    }

    private function testBlockedFlagRoundTrip(): int
    {
        $path = $this->tmpDir . '/blocked-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Blocked entry');
        $entry->setKind('feature');
        $entry->setStage('development');
        $entry->setFeature('blocked-feature');
        $entry->setBranch('feat/blocked-feature');
        $entry->setBlocked(true);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        $storage->save($board);
        $reloaded = $storage->load($path);
        $r = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0];

        if (!$r->checkIsBlocked()) {
            echo "FAIL testBlockedFlagRoundTrip: blocked flag lost\n";
            return 1;
        }
        echo "OK testBlockedFlagRoundTrip\n";
        return 0;
    }

    private function testActiveFieldOrderingPreserved(): int
    {
        $path = $this->tmpDir . '/order-' . uniqid('', true) . '.yaml';
        file_put_contents($path, BoardYamlStorage::initialContent());

        $storage = new BoardYamlStorage();
        $board = $storage->load($path);

        $entry = new BoardEntry('Order check entry');
        $entry->setKind('feature');
        $entry->setStage('development');
        $entry->setFeature('order-feature');
        $entry->setDeveloper('d01');
        $entry->setBranch('feat/order-feature');
        $entry->setBase('abc');
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, [$entry]);

        $storage->save($board);
        $raw = (string) file_get_contents($path);

        $expected = [
            'kind:',
            'stage:',
            'feature:',
            'developer:',
            'branch:',
            'base:',
            'title:',
        ];
        $previousPos = -1;
        foreach ($expected as $needle) {
            $pos = strpos($raw, $needle);
            if ($pos === false) {
                echo "FAIL testActiveFieldOrderingPreserved: missing key '{$needle}'\n{$raw}\n";
                return 1;
            }
            if ($pos < $previousPos) {
                echo "FAIL testActiveFieldOrderingPreserved: key '{$needle}' appears out of order\n{$raw}\n";
                return 1;
            }
            $previousPos = $pos;
        }
        echo "OK testActiveFieldOrderingPreserved\n";
        return 0;
    }
}
