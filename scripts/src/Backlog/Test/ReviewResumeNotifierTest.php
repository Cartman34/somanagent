<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeSessionDriver;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Service\ReviewResumeNotifier;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Tests for {@see ReviewResumeNotifier}.
 *
 * Uses FakeSessionDriver to exercise injection without running real tmux.
 */
final class ReviewResumeNotifierTest
{
    private const REVIEWER_CODE = 'r01';

    private const DEVELOPER_CODE = 'd01';

    private const FEATURE_SLUG = 'my-feature';

    private const TASK_SLUG = 'my-task';

    private const WORKTREES_ROOT = '/fake/worktrees';

    private string $tmpDir;

    /**
     * Creates a unique temporary directory for sessions.json.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/review-resume-notifier-test-' . uniqid('', true);
        mkdir($this->tmpDir . '/local/tmp', 0755, true);
    }

    /**
     * Removes the temporary directory created for this test run.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the cumulative number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testNoReviewerSkipsNotification();
        $failed += $this->testAbsentSessionSkipsNotification();
        $failed += $this->testDeadSessionSkipsNotification();
        $failed += $this->testNonReviewerRoleSkipsNotification();
        $failed += $this->testNonTmuxSessionSkipsNotification();
        $failed += $this->testIncoherentWorktreeSkipsNotification();
        $failed += $this->testConfigAbsentSkipsNotification();
        $failed += $this->testConfigBoardEnabledTriggersNotification();
        $failed += $this->testSessionOverrideOnOverridesBoardOff();
        $failed += $this->testSessionOverrideOffOverridesBoardOn();
        $failed += $this->testInjectionFailureDoesNotThrow();
        $failed += $this->testInjectedPromptContainsEntryRef();
        $failed += $this->testFeatureEntrySetStagePreservesReviewer();
        $failed += $this->testTaskEntrySetStagePreservesReviewer();

        return $failed;
    }

    private function testNoReviewerSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $notifier = $this->makeNotifier($driver);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: null);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testNoReviewerSkipsNotification: expected no injection when reviewer is null\n";
            return 1;
        }

        echo "OK testNoReviewerSkipsNotification\n";
        return 0;
    }

    private function testAbsentSessionSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $notifier = $this->makeNotifier($driver);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testAbsentSessionSkipsNotification: expected no injection when session is absent\n";
            return 1;
        }

        echo "OK testAbsentSessionSkipsNotification\n";
        return 0;
    }

    private function testDeadSessionSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, false);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testDeadSessionSkipsNotification: expected no injection when session is dead\n";
            return 1;
        }

        echo "OK testDeadSessionSkipsNotification\n";
        return 0;
    }

    private function testNonReviewerRoleSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE, role: AgentRole::DEVELOPER);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testNonReviewerRoleSkipsNotification: expected no injection for non-reviewer session\n";
            return 1;
        }

        echo "OK testNonReviewerRoleSkipsNotification\n";
        return 0;
    }

    private function testNonTmuxSessionSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE, tmuxSession: null);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testNonTmuxSessionSkipsNotification: expected no injection for non-tmux session\n";
            return 1;
        }

        echo "OK testNonTmuxSessionSkipsNotification\n";
        return 0;
    }

    private function testIncoherentWorktreeSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE, worktree: '/other/path/d99');

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testIncoherentWorktreeSkipsNotification: expected no injection for incoherent worktree\n";
            return 1;
        }

        echo "OK testIncoherentWorktreeSkipsNotification\n";
        return 0;
    }

    private function testConfigAbsentSkipsNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE);

        $board = $this->boardWithReviewResumeEnabled(null);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testConfigAbsentSkipsNotification: expected no injection when board config is absent\n";
            return 1;
        }

        echo "OK testConfigAbsentSkipsNotification\n";
        return 0;
    }

    private function testConfigBoardEnabledTriggersNotification(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts === []) {
            echo "FAIL testConfigBoardEnabledTriggersNotification: expected injection when board config is enabled\n";
            return 1;
        }

        echo "OK testConfigBoardEnabledTriggersNotification\n";
        return 0;
    }

    private function testSessionOverrideOnOverridesBoardOff(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE, reviewResume: true);

        $board = $this->boardWithReviewResumeEnabled(false);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts === []) {
            echo "FAIL testSessionOverrideOnOverridesBoardOff: expected injection when session override=on\n";
            return 1;
        }

        echo "OK testSessionOverrideOnOverridesBoardOff\n";
        return 0;
    }

    private function testSessionOverrideOffOverridesBoardOn(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE, reviewResume: false);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts !== []) {
            echo "FAIL testSessionOverrideOffOverridesBoardOn: expected no injection when session override=off\n";
            return 1;
        }

        echo "OK testSessionOverrideOffOverridesBoardOn\n";
        return 0;
    }

    private function testInjectionFailureDoesNotThrow(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);
        $driver->injectPromptResult = false;

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        try {
            $notifier->notify($board, $entry);
        } catch (\Throwable $e) {
            echo "FAIL testInjectionFailureDoesNotThrow: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        echo "OK testInjectionFailureDoesNotThrow\n";
        return 0;
    }

    private function testInjectedPromptContainsEntryRef(): int
    {
        $driver = new FakeSessionDriver();
        $driver->setAlive(self::REVIEWER_CODE, true);

        $notifier = $this->makeNotifier($driver, self::REVIEWER_CODE);

        $board = $this->boardWithReviewResumeEnabled(true);
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        $notifier->notify($board, $entry);

        if ($driver->injectedPrompts === []) {
            echo "FAIL testInjectedPromptContainsEntryRef: expected injection\n";
            return 1;
        }

        $text = $driver->injectedPrompts[0]['text'];
        if (!str_contains($text, self::FEATURE_SLUG)) {
            echo "FAIL testInjectedPromptContainsEntryRef: injected text does not contain feature ref '{$text}'\n";
            return 1;
        }
        if (!str_contains($text, 'review-next')) {
            echo "FAIL testInjectedPromptContainsEntryRef: injected text does not mention review-next '{$text}'\n";
            return 1;
        }

        echo "OK testInjectedPromptContainsEntryRef\n";
        return 0;
    }

    /**
     * Model-level: BoardEntry.setStage() does not clear reviewer regardless of stage.
     * Command-level coverage (BacklogReviewRejectCommand preserves reviewer) is in BacklogReviewRejectCommandTest.
     */
    private function testFeatureEntrySetStagePreservesReviewer(): int
    {
        $entry = $this->featureEntry(reviewer: self::REVIEWER_CODE);

        foreach ([BacklogBoard::STAGE_REJECTED, BacklogBoard::STAGE_PENDING_REVIEW] as $stage) {
            $entry->setStage($stage);
            if ($entry->getReviewer() !== self::REVIEWER_CODE) {
                echo "FAIL testFeatureEntrySetStagePreservesReviewer: reviewer cleared after setStage({$stage})\n";
                return 1;
            }
        }

        echo "OK testFeatureEntrySetStagePreservesReviewer\n";
        return 0;
    }

    /**
     * Model-level: BoardEntry.setStage() does not clear reviewer regardless of stage.
     * Command-level coverage (BacklogReviewRejectCommand preserves reviewer) is in BacklogReviewRejectCommandTest.
     */
    private function testTaskEntrySetStagePreservesReviewer(): int
    {
        $entry = $this->taskEntry(reviewer: self::REVIEWER_CODE);

        foreach ([BacklogBoard::STAGE_REJECTED, BacklogBoard::STAGE_PENDING_REVIEW] as $stage) {
            $entry->setStage($stage);
            if ($entry->getReviewer() !== self::REVIEWER_CODE) {
                echo "FAIL testTaskEntrySetStagePreservesReviewer: reviewer cleared after setStage({$stage})\n";
                return 1;
            }
        }

        echo "OK testTaskEntrySetStagePreservesReviewer\n";
        return 0;
    }

    private function makeNotifier(
        FakeSessionDriver $driver,
        ?string $reviewerCode = null,
        AgentRole $role = AgentRole::REVIEWER,
        ?string $tmuxSession = 'somanagent-r01',
        ?string $worktree = null,
        ?bool $reviewResume = null,
    ): ReviewResumeNotifier {
        $sessionService = new AgentSessionService($this->tmpDir);

        if ($reviewerCode !== null) {
            $now = new \DateTimeImmutable();
            $session = new AgentSession(
                code: $reviewerCode,
                client: AgentClient::CLAUDE,
                role: $role,
                pid: 12345,
                worktree: $worktree ?? self::WORKTREES_ROOT . '/' . self::DEVELOPER_CODE,
                startedAt: $now,
                lastSeenAt: $now,
                sessionId: null,
                clientPid: null,
                tmuxSession: $tmuxSession,
                reviewResume: $reviewResume,
            );
            $sessionService->add($session);
        }

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new ReviewResumeNotifier($sessionService, $driver, $boardService, self::WORKTREES_ROOT);
    }

    private function boardWithReviewResumeEnabled(?bool $enabled): BacklogBoard
    {
        $board = new BacklogBoard('/tmp/board.yaml');
        $board->setReviewResumeEnabled($enabled);

        return $board;
    }

    private function featureEntry(?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry(self::FEATURE_SLUG);
        $entry->setKind(BacklogBoardService::ENTRY_KIND_FEATURE);
        $entry->setFeature(self::FEATURE_SLUG);
        $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
        $entry->setDeveloper(self::DEVELOPER_CODE);
        $entry->setReviewer($reviewer);
        $entry->setBranch('tech/' . self::FEATURE_SLUG);

        return $entry;
    }

    private function taskEntry(?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry(self::TASK_SLUG);
        $entry->setKind(BacklogBoardService::ENTRY_KIND_TASK);
        $entry->setFeature(self::FEATURE_SLUG);
        $entry->setTask(self::TASK_SLUG);
        $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
        $entry->setDeveloper(self::DEVELOPER_CODE);
        $entry->setReviewer($reviewer);

        return $entry;
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
