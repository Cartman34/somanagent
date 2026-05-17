<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Command\BacklogCommitGateCommand;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for BacklogCommitGateCommand — verifies that commits are allowed in development
 * stage and blocked in all other stages.
 */
final class BacklogCommitGateCommandTest
{
    private string $projectRoot;
    private string $testBoardDir;

    /**
     * Resolves paths from the test file location.
     */
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 4);
        $this->testBoardDir = $this->projectRoot . '/local/tests/commit-gate-command';
    }

    /**
     * Runs all tests and returns the number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testAllowsDevelopmentStage();
        $failed += $this->testBlocksReviewStage();
        $failed += $this->testBlocksReviewingStage();
        $failed += $this->testBlocksRejectedStage();
        $failed += $this->testBlocksApprovedStage();
        $failed += $this->testBlocksWhenNoActiveEntry();

        return $failed;
    }

    private function testAllowsDevelopmentStage(): int
    {
        $board = $this->boardWithFeature('d99', BacklogBoard::STAGE_IN_PROGRESS);
        $command = $this->createCommand($board, 'd99');
        try {
            $command->handle([], []);
        } catch (\RuntimeException $e) {
            echo "FAIL testAllowsDevelopmentStage: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        echo "OK testAllowsDevelopmentStage\n";
        return 0;
    }

    private function testBlocksReviewStage(): int
    {
        return $this->assertBlocked('testBlocksReviewStage', BacklogBoard::STAGE_IN_REVIEW, "'review'");
    }

    private function testBlocksReviewingStage(): int
    {
        return $this->assertBlocked('testBlocksReviewingStage', BacklogBoard::STAGE_REVIEWING, "'reviewing'");
    }

    private function testBlocksRejectedStage(): int
    {
        return $this->assertBlocked('testBlocksRejectedStage', BacklogBoard::STAGE_REJECTED, "'rejected'");
    }

    private function testBlocksApprovedStage(): int
    {
        return $this->assertBlocked('testBlocksApprovedStage', BacklogBoard::STAGE_APPROVED, "'approved'");
    }

    private function testBlocksWhenNoActiveEntry(): int
    {
        $board = $this->emptyBoard();
        $command = $this->createCommand($board, 'd99');
        try {
            $command->handle([], []);
            echo "FAIL testBlocksWhenNoActiveEntry: expected RuntimeException, none thrown\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'no active backlog entry')) {
                echo "FAIL testBlocksWhenNoActiveEntry: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testBlocksWhenNoActiveEntry\n";
        return 0;
    }

    private function assertBlocked(string $testName, string $stage, string $expectedFragment): int
    {
        $board = $this->boardWithFeature('d99', $stage);
        $command = $this->createCommand($board, 'd99');
        try {
            $command->handle([], []);
            echo "FAIL {$testName}: expected RuntimeException, none thrown\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), $expectedFragment)) {
                echo "FAIL {$testName}: expected message to contain {$expectedFragment}, got: {$e->getMessage()}\n";
                return 1;
            }
            if (!str_contains($e->getMessage(), '❌ Commit blocked')) {
                echo "FAIL {$testName}: expected '❌ Commit blocked' in message, got: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK {$testName}\n";
        return 0;
    }

    private function createCommand(string $boardPath, string $agentCode): BacklogCommitGateCommand
    {
        putenv("SOMANAGER_AGENT={$agentCode}");

        $app = Application::getInstance();
        $consoleClient = new ConsoleClient(
            $this->projectRoot,
            false,
            $app,
            static fn(string $message) => null,
        );
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $presenter = new BacklogPresenter(Console::getInstance(), $consoleClient, $boardService);
        $permissionService = new BacklogPermissionService();

        $command = new BacklogCommitGateCommand($presenter, false, $this->projectRoot, $boardService, $permissionService);
        $command->setBoardPath($boardPath);

        return $command;
    }

    private function boardWithFeature(string $agentCode, string $stage): string
    {
        $path = $this->boardPath($stage);
        if (!is_dir($this->testBoardDir)) {
            mkdir($this->testBoardDir, 0o755, true);
        }
        $yaml = "version: 1\ntodo: []\nactive:\n  - kind: feature\n    stage: {$stage}\n    feature: test-feature\n    agent: {$agentCode}\n    branch: tech/test-feature\n    base: abc123\n    pr: none\n    type: tech\n    title: Test feature\n";
        file_put_contents($path, $yaml);

        return $path;
    }

    private function emptyBoard(): string
    {
        $path = $this->boardPath('empty');
        if (!is_dir($this->testBoardDir)) {
            mkdir($this->testBoardDir, 0o755, true);
        }
        file_put_contents($path, "version: 1\ntodo: []\nactive: []\n");

        return $path;
    }

    private function boardPath(string $suffix): string
    {
        return $this->testBoardDir . "/board-{$suffix}.yaml";
    }
}
