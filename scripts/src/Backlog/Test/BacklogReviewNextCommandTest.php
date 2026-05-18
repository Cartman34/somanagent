<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\BacklogPaths;
use SoManAgent\Script\Backlog\Command\BacklogReviewNextCommand;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression tests for implicit review-next selection in reviewer sessions.
 */
final class BacklogReviewNextCommandTest
{
    private string $tmpDir;

    /**
     * Creates a unique temporary directory for this test run.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-review-next-command-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory created for this test run.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs every test case and returns the cumulative number of failures.
     */
    public function run(): int
    {
        $failed = 0;
        $failed += $this->testReviewerSessionImplicitPickPrefersCurrentDeveloperWa();
        $failed += $this->testReviewerSessionImplicitPickRefusesOtherDeveloperWa();
        $failed += $this->testManualImplicitPickKeepsFirstReviewEntry();

        return $failed;
    }

    private function testReviewerSessionImplicitPickPrefersCurrentDeveloperWa(): int
    {
        $projectRoot = $this->makeProject('session-prefers-wa');
        $this->writeBoard($projectRoot, [
            $this->featureEntry('other-feature', 'd06'),
            $this->featureEntry('target-feature', 'd05'),
        ]);
        $this->writeReviewerSession($projectRoot, 'r01', 'd05');

        try {
            $this->withEnv([
                'SOMANAGER_ROLE' => 'reviewer',
                'SOMANAGER_AGENT' => 'r01',
                'PWD' => $projectRoot . '/.agent-worktrees/d05',
            ], function () use ($projectRoot): void {
                $this->makeCommand($projectRoot)->handle([], []);
            });
        } catch (\Throwable $e) {
            echo "FAIL testReviewerSessionImplicitPickPrefersCurrentDeveloperWa: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $board = $this->loadBoard($projectRoot);
        $other = $board->getEntries(BacklogBoard::SECTION_ACTIVE)[0];
        $target = $board->getEntries(BacklogBoard::SECTION_ACTIVE)[1];
        if ($other->getStage() !== BacklogBoard::STAGE_IN_REVIEW || $target->getStage() !== BacklogBoard::STAGE_REVIEWING) {
            echo "FAIL testReviewerSessionImplicitPickPrefersCurrentDeveloperWa: expected d05 entry only to move to reviewing\n";
            return 1;
        }
        if ($target->getReviewer() !== 'r01') {
            echo "FAIL testReviewerSessionImplicitPickPrefersCurrentDeveloperWa: expected reviewer r01\n";
            return 1;
        }

        echo "OK testReviewerSessionImplicitPickPrefersCurrentDeveloperWa\n";
        return 0;
    }

    private function testReviewerSessionImplicitPickRefusesOtherDeveloperWa(): int
    {
        $projectRoot = $this->makeProject('session-refuses-other-wa');
        $this->writeBoard($projectRoot, [
            $this->featureEntry('other-feature', 'd06'),
        ]);
        $this->writeReviewerSession($projectRoot, 'r01', 'd05');

        $threw = false;
        try {
            $this->withEnv([
                'SOMANAGER_ROLE' => 'reviewer',
                'SOMANAGER_AGENT' => 'r01',
                'PWD' => $projectRoot . '/.agent-worktrees/d05',
            ], function () use ($projectRoot): void {
                $this->makeCommand($projectRoot)->handle([], []);
            });
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'No review-stage entry belongs to the current reviewer WA')
                && str_contains($e->getMessage(), 'other-feature');
        }

        if (!$threw) {
            echo "FAIL testReviewerSessionImplicitPickRefusesOtherDeveloperWa: expected WA mismatch refusal\n";
            return 1;
        }

        $entry = $this->loadBoard($projectRoot)->getEntries(BacklogBoard::SECTION_ACTIVE)[0];
        if ($entry->getStage() !== BacklogBoard::STAGE_IN_REVIEW || $entry->getReviewer() !== null) {
            echo "FAIL testReviewerSessionImplicitPickRefusesOtherDeveloperWa: refusal must not claim another WA entry\n";
            return 1;
        }

        echo "OK testReviewerSessionImplicitPickRefusesOtherDeveloperWa\n";
        return 0;
    }

    private function testManualImplicitPickKeepsFirstReviewEntry(): int
    {
        $projectRoot = $this->makeProject('manual-keeps-first');
        $this->writeBoard($projectRoot, [
            $this->featureEntry('other-feature', 'd06'),
            $this->featureEntry('target-feature', 'd05'),
        ]);
        $this->writeReviewerSession($projectRoot, 'r01', 'd05');

        try {
            $this->withEnv([
                'SOMANAGER_ROLE' => false,
                'SOMANAGER_AGENT' => 'r01',
                'PWD' => $projectRoot,
            ], function () use ($projectRoot): void {
                $this->makeCommand($projectRoot)->handle([], []);
            });
        } catch (\Throwable $e) {
            echo "FAIL testManualImplicitPickKeepsFirstReviewEntry: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $board = $this->loadBoard($projectRoot);
        $other = $board->getEntries(BacklogBoard::SECTION_ACTIVE)[0];
        $target = $board->getEntries(BacklogBoard::SECTION_ACTIVE)[1];
        if ($other->getStage() !== BacklogBoard::STAGE_REVIEWING || $target->getStage() !== BacklogBoard::STAGE_IN_REVIEW) {
            echo "FAIL testManualImplicitPickKeepsFirstReviewEntry: expected old first-entry behavior outside reviewer session\n";
            return 1;
        }

        echo "OK testManualImplicitPickKeepsFirstReviewEntry\n";
        return 0;
    }

    private function makeCommand(string $projectRoot): BacklogReviewNextCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $command = new BacklogReviewNextCommand(
            new BacklogPresenter(Console::getInstance(), new ConsoleClient($projectRoot, false, Application::getInstance(), static function (string $_message): void {}), $boardService),
            false,
            $projectRoot,
            $projectRoot . '/.agent-worktrees',
            new AgentSessionService($projectRoot),
            $boardService,
        );
        $command->setBoardPath(BacklogPaths::boardPath($projectRoot));

        return $command;
    }

    private function makeProject(string $name): string
    {
        $projectRoot = $this->tmpDir . '/' . $name;
        mkdir($projectRoot . '/local/tmp', 0755, true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/.agent-worktrees/d05', 0755, true);
        mkdir($projectRoot . '/.agent-worktrees/d06', 0755, true);

        return $projectRoot;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeBoard(string $projectRoot, array $entries): void
    {
        file_put_contents(BacklogPaths::boardPath($projectRoot), Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $entries,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function loadBoard(string $projectRoot): BacklogBoard
    {
        return (new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false))
            ->loadBoard(BacklogPaths::boardPath($projectRoot));
    }

    private function writeReviewerSession(string $projectRoot, string $reviewer, string $developer): void
    {
        file_put_contents($projectRoot . '/local/tmp/agent-sessions.json', json_encode([
            $reviewer => [
                'client' => 'codex',
                'role' => 'reviewer',
                'pid' => 12345,
                'client_pid' => null,
                'tmux_session' => 'somanagent-' . $reviewer,
                'worktree' => $projectRoot . '/.agent-worktrees/' . $developer,
                'started_at' => '2026-05-18T00:00:00+00:00',
                'last_seen_at' => '2026-05-18T00:00:00+00:00',
                'session_id' => null,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function featureEntry(string $feature, string $agent): array
    {
        return [
            'kind' => 'feature',
            'stage' => 'review',
            'feature' => $feature,
            'agent' => $agent,
            'branch' => 'tech/' . $feature,
            'base' => 'abc123',
            'pr' => 'none',
            'type' => 'tech',
            'title' => 'Feature ' . $feature,
        ];
    }

    /**
     * @param array<string, string|false> $env
     * @param callable(): void $callback
     */
    private function withEnv(array $env, callable $callback): void
    {
        $previous = [];
        foreach ($env as $key => $_value) {
            $current = getenv($key);
            $previous[$key] = $current === false ? false : $current;
        }

        try {
            foreach ($env as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
