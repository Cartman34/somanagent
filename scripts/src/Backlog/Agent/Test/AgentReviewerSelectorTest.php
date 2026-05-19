<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\BacklogPaths;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for AgentReviewerSelector — auto-selection, owned reviewing entry, and conflict detection.
 */
final class AgentReviewerSelectorTest
{
    private const FEATURE_MY = 'my-feature';

    private const FEATURE_A = 'feat-a';

    private const FEATURE_B = 'feat-b';

    private const FEATURE_Z = 'feat-z';

    private const FEATURE_TARGET = 'target-feature';

    private const FEATURE_DEV = 'dev-feature';

    private const FEATURE_MY_FEAT = 'my-feat';

    private const TASK_CHILD = 'child-task';

    private const FEATURE_SOME = 'some-feature';

    private const YAML_KEY_FEATURE_BRANCH = 'feature-branch';

    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-reviewer-selector-test-' . uniqid('', true);
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

        $failed += $this->testAutoSelectPicksFirstReviewEntry();
        $failed += $this->testAutoSelectSkipsClaimedWorktree();
        $failed += $this->testAutoSelectThrowsWhenAllClaimed();
        $failed += $this->testAutoSelectReturnsMatchDirectly();
        $failed += $this->testSelectByFeatureFound();
        $failed += $this->testSelectByFeatureNotFoundWhenWrongStage();
        $failed += $this->testSelectByFeatureReviewingForSameReviewer();
        $failed += $this->testSelectByFeatureReviewingForOtherReviewerThrows();
        $failed += $this->testSelectByTaskFound();
        $failed += $this->testSelectByTaskInvalidRef();
        $failed += $this->testSelectByTaskReviewingForOtherReviewerThrows();
        $failed += $this->testSelectByDeveloperFound();
        $failed += $this->testSelectByDeveloperNotFound();
        $failed += $this->testSelectByDeveloperReviewingForOtherReviewerThrows();
        $failed += $this->testFindOwnedReviewingEntryFound();
        $failed += $this->testFindOwnedReviewingEntryReturnsNullWhenNone();
        $failed += $this->testFindOwnedReviewingEntryIgnoresOtherReviewer();
        $failed += $this->testFindExistingReviewerForWorktree();
        $failed += $this->testFindExistingReviewerReturnsNullWhenNone();
        $failed += $this->testPickSkipsNotReservableAndReturnsSecond();
        $failed += $this->testPickReturnsNullWhenAllNotReservable();
        $failed += $this->testPickPropagatesUnexpectedException();
        return $failed;
    }

    private function testAutoSelectPicksFirstReviewEntry(): int
    {
        $projectRoot = $this->makeTmpSubdir('auto-pick');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_MY, 'd01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $match = $selector->autoSelect($board);
        } catch (\RuntimeException $e) {
            echo "FAIL testAutoSelectPicksFirstReviewEntry: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getAgent() !== 'd01') {
            echo "FAIL testAutoSelectPicksFirstReviewEntry: expected agent=d01, got {$match->getEntry()->getAgent()}\n";
            return 1;
        }
        if ($match->getEntry()->getFeature() !== self::FEATURE_MY) {
            echo "FAIL testAutoSelectPicksFirstReviewEntry: expected feature=my-feature\n";
            return 1;
        }

        echo "OK testAutoSelectPicksFirstReviewEntry\n";
        return 0;
    }

    private function testAutoSelectSkipsClaimedWorktree(): int
    {
        $projectRoot = $this->makeTmpSubdir('auto-skip');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);

        $this->writeBoard($boardPath, $this->boardWithEntries([
            $this->featureEntryAtReview(self::FEATURE_A, 'd01'),
            $this->featureEntryAtReview(self::FEATURE_B, 'd02'),
        ]));

        // Simulate a reviewer already reviewing d01's worktree
        $this->writeSessionsJson($projectRoot, [
            'r01' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $worktreesRoot . '/d01',
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $selector = $this->makeSelector($projectRoot, $worktreesRoot);
        $boardObj = $this->loadBoard($projectRoot);

        try {
            $match = $selector->autoSelect($boardObj);
        } catch (\RuntimeException $e) {
            echo "FAIL testAutoSelectSkipsClaimedWorktree: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getAgent() !== 'd02') {
            echo "FAIL testAutoSelectSkipsClaimedWorktree: expected agent=d02 (d01 claimed), got {$match->getEntry()->getAgent()}\n";
            return 1;
        }

        echo "OK testAutoSelectSkipsClaimedWorktree\n";
        return 0;
    }

    private function testAutoSelectThrowsWhenAllClaimed(): int
    {
        $projectRoot = $this->makeTmpSubdir('auto-all-claimed');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview('feat-x', 'd01'));

        $this->writeSessionsJson($projectRoot, [
            'r01' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $worktreesRoot . '/d01',
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $selector = $this->makeSelector($projectRoot, $worktreesRoot);
        $boardObj = $this->loadBoard($projectRoot);

        try {
            $selector->autoSelect($boardObj);
            echo "FAIL testAutoSelectThrowsWhenAllClaimed: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'No review available')) {
                echo "FAIL testAutoSelectThrowsWhenAllClaimed: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testAutoSelectThrowsWhenAllClaimed\n";
        return 0;
    }

    private function testAutoSelectReturnsMatchDirectly(): int
    {
        $projectRoot = $this->makeTmpSubdir('auto-match-type');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_Z, 'd03'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $match = $selector->autoSelect($board);

        if ($match->getEntry()->getFeature() !== self::FEATURE_Z) {
            echo "FAIL testAutoSelectReturnsMatchDirectly: expected feature feat-z, got " . $match->getEntry()->getFeature() . "\n";
            return 1;
        }

        echo "OK testAutoSelectReturnsMatchDirectly\n";
        return 0;
    }

    private function testSelectByFeatureFound(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-feature-found');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_TARGET, 'd03'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $match = $selector->selectByFeature($board, self::FEATURE_TARGET, 'r01');
        } catch (\RuntimeException $e) {
            echo "FAIL testSelectByFeatureFound: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getFeature() !== self::FEATURE_TARGET) {
            echo "FAIL testSelectByFeatureFound: wrong feature in match\n";
            return 1;
        }

        echo "OK testSelectByFeatureFound\n";
        return 0;
    }

    private function testSelectByFeatureNotFoundWhenWrongStage(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-feature-wrong-stage');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        // Entry is at development, not review
        $this->writeBoard($boardPath, $this->boardWithFeatureAtDevelopment(self::FEATURE_DEV, 'd01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByFeature($board, self::FEATURE_DEV, 'r01');
            echo "FAIL testSelectByFeatureNotFoundWhenWrongStage: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), "stage=review")) {
                echo "FAIL testSelectByFeatureNotFoundWhenWrongStage: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByFeatureNotFoundWhenWrongStage\n";
        return 0;
    }

    private function testSelectByFeatureReviewingForSameReviewer(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-feature-reviewing-same');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_MY_FEAT, 'd01', 'r01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $match = $selector->selectByFeature($board, self::FEATURE_MY_FEAT, 'r01');
        } catch (\RuntimeException $e) {
            echo "FAIL testSelectByFeatureReviewingForSameReviewer: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getStage() !== BacklogBoard::STAGE_REVIEWING) {
            echo "FAIL testSelectByFeatureReviewingForSameReviewer: expected stage=reviewing\n";
            return 1;
        }

        echo "OK testSelectByFeatureReviewingForSameReviewer\n";
        return 0;
    }

    private function testSelectByFeatureReviewingForOtherReviewerThrows(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-feature-reviewing-other');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_MY_FEAT, 'd01', 'r99'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByFeature($board, self::FEATURE_MY_FEAT, 'r01');
            echo "FAIL testSelectByFeatureReviewingForOtherReviewerThrows: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'r99')) {
                echo "FAIL testSelectByFeatureReviewingForOtherReviewerThrows: expected error to mention r99, got: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByFeatureReviewingForOtherReviewerThrows\n";
        return 0;
    }

    private function testSelectByTaskFound(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-task-found');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithTaskAtReview('parent-feat', self::TASK_CHILD, 'd02'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $match = $selector->selectByTask($board, 'parent-feat/child-task', 'r01');
        } catch (\RuntimeException $e) {
            echo "FAIL testSelectByTaskFound: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getTask() !== self::TASK_CHILD) {
            echo "FAIL testSelectByTaskFound: wrong task in match\n";
            return 1;
        }

        echo "OK testSelectByTaskFound\n";
        return 0;
    }

    private function testSelectByTaskInvalidRef(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-task-bad-ref');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview('any', 'd01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByTask($board, 'bare-task-slug', 'r01');
            echo "FAIL testSelectByTaskInvalidRef: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Invalid task reference')) {
                echo "FAIL testSelectByTaskInvalidRef: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByTaskInvalidRef\n";
        return 0;
    }

    private function testSelectByTaskReviewingForOtherReviewerThrows(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-task-reviewing-other');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithTaskAtReviewing(self::FEATURE_A, 'task-b', 'd01', 'r99'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByTask($board, 'feat-a/task-b', 'r01');
            echo "FAIL testSelectByTaskReviewingForOtherReviewerThrows: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'r99')) {
                echo "FAIL testSelectByTaskReviewingForOtherReviewerThrows: got: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByTaskReviewingForOtherReviewerThrows\n";
        return 0;
    }

    private function testSelectByDeveloperFound(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-dev-found');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_SOME, 'd05'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $match = $selector->selectByDeveloper($board, 'd05', 'r01');
        } catch (\RuntimeException $e) {
            echo "FAIL testSelectByDeveloperFound: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match->getEntry()->getAgent() !== 'd05') {
            echo "FAIL testSelectByDeveloperFound: wrong agent in match\n";
            return 1;
        }

        echo "OK testSelectByDeveloperFound\n";
        return 0;
    }

    private function testSelectByDeveloperNotFound(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-dev-not-found');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_SOME, 'd01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByDeveloper($board, 'd99', 'r01');
            echo "FAIL testSelectByDeveloperNotFound: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), "has no active entry")) {
                echo "FAIL testSelectByDeveloperNotFound: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByDeveloperNotFound\n";
        return 0;
    }

    private function testSelectByDeveloperReviewingForOtherReviewerThrows(): int
    {
        $projectRoot = $this->makeTmpSubdir('by-dev-reviewing-other');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReviewing('feat-c', 'd03', 'r99'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        try {
            $selector->selectByDeveloper($board, 'd03', 'r01');
            echo "FAIL testSelectByDeveloperReviewingForOtherReviewerThrows: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'r99')) {
                echo "FAIL testSelectByDeveloperReviewingForOtherReviewerThrows: got: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testSelectByDeveloperReviewingForOtherReviewerThrows\n";
        return 0;
    }

    private function testFindOwnedReviewingEntryFound(): int
    {
        $projectRoot = $this->makeTmpSubdir('owned-reviewing-found');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_MY_FEAT, 'd01', 'r01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $match = $selector->findOwnedReviewingEntry($board, 'r01');

        if ($match === null) {
            echo "FAIL testFindOwnedReviewingEntryFound: expected match, got null\n";
            return 1;
        }
        if ($match->getEntry()->getReviewer() !== 'r01') {
            echo "FAIL testFindOwnedReviewingEntryFound: expected reviewer=r01\n";
            return 1;
        }

        echo "OK testFindOwnedReviewingEntryFound\n";
        return 0;
    }

    private function testFindOwnedReviewingEntryReturnsNullWhenNone(): int
    {
        $projectRoot = $this->makeTmpSubdir('owned-reviewing-none');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReview(self::FEATURE_MY_FEAT, 'd01'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $match = $selector->findOwnedReviewingEntry($board, 'r01');

        if ($match !== null) {
            echo "FAIL testFindOwnedReviewingEntryReturnsNullWhenNone: expected null\n";
            return 1;
        }

        echo "OK testFindOwnedReviewingEntryReturnsNullWhenNone\n";
        return 0;
    }

    private function testFindOwnedReviewingEntryIgnoresOtherReviewer(): int
    {
        $projectRoot = $this->makeTmpSubdir('owned-reviewing-other');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        // Board has an entry at reviewing for r99, not r01
        $this->writeBoard($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_MY_FEAT, 'd01', 'r99'));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $match = $selector->findOwnedReviewingEntry($board, 'r01');

        if ($match !== null) {
            echo "FAIL testFindOwnedReviewingEntryIgnoresOtherReviewer: expected null for r01 (owned by r99)\n";
            return 1;
        }

        echo "OK testFindOwnedReviewingEntryIgnoresOtherReviewer\n";
        return 0;
    }

    private function testFindExistingReviewerForWorktree(): int
    {
        $projectRoot = $this->makeTmpSubdir('find-reviewer');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $targetWorktree = $worktreesRoot . '/d01';

        $this->writeSessionsJson($projectRoot, [
            'r01' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $targetWorktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $selector = $this->makeSelector($projectRoot, $worktreesRoot);
        $session = $selector->findExistingReviewerForWorktree($targetWorktree);

        if ($session === null) {
            echo "FAIL testFindExistingReviewerForWorktree: expected reviewer session, got null\n";
            return 1;
        }
        if ($session->code !== 'r01') {
            echo "FAIL testFindExistingReviewerForWorktree: expected r01, got {$session->code}\n";
            return 1;
        }

        echo "OK testFindExistingReviewerForWorktree\n";
        return 0;
    }

    private function testFindExistingReviewerReturnsNullWhenNone(): int
    {
        $projectRoot = $this->makeTmpSubdir('find-reviewer-none');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';

        $selector = $this->makeSelector($projectRoot, $worktreesRoot);
        $session = $selector->findExistingReviewerForWorktree($worktreesRoot . '/d01');

        if ($session !== null) {
            echo "FAIL testFindExistingReviewerReturnsNullWhenNone: expected null, got a session\n";
            return 1;
        }

        echo "OK testFindExistingReviewerReturnsNullWhenNone\n";
        return 0;
    }

    private function testPickSkipsNotReservableAndReturnsSecond(): int
    {
        $projectRoot = $this->makeTmpSubdir('pick-skip-first');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithEntries([
            $this->featureEntryAtReview(self::FEATURE_A, 'd01'),
            $this->featureEntryAtReview(self::FEATURE_B, 'd02'),
        ]));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $callCount = 0;
        $runner = new FakeBacklogCommandRunner();
        $runner->onReviewNext = static function (string $reviewerCode, string $ref) use (&$callCount): void {
            $callCount++;
            if ($callCount === 1) {
                throw new EntryNotReservableException($ref, 'Entry "feat-a" is already in reviewing by r99.');
            }
        };

        try {
            $match = $selector->pick($board, 'r01', $runner);
        } catch (\RuntimeException $e) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        if ($match === null) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: expected match, got null\n";
            return 1;
        }
        if ($match->getEntry()->getFeature() !== self::FEATURE_B) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: expected feat-b, got {$match->getEntry()->getFeature()}\n";
            return 1;
        }
        if ($callCount !== 2) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: expected 2 reviewNext calls, got {$callCount}\n";
            return 1;
        }
        $refs = array_column(array_filter($runner->calls, static fn ($c) => $c['method'] === 'reviewNext'), 'entryRef');
        if ($refs !== [self::FEATURE_A, self::FEATURE_B]) {
            echo "FAIL testPickSkipsNotReservableAndReturnsSecond: wrong call order: " . implode(', ', $refs) . "\n";
            return 1;
        }

        echo "OK testPickSkipsNotReservableAndReturnsSecond\n";
        return 0;
    }

    private function testPickReturnsNullWhenAllNotReservable(): int
    {
        $projectRoot = $this->makeTmpSubdir('pick-all-not-reservable');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithEntries([
            $this->featureEntryAtReview(self::FEATURE_A, 'd01'),
            $this->featureEntryAtReview(self::FEATURE_B, 'd02'),
        ]));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $runner = new FakeBacklogCommandRunner();
        $runner->onReviewNext = static function (string $reviewerCode, string $ref): void {
            throw new EntryNotReservableException($ref, 'Entry "' . $ref . '" is not in review.');
        };

        $threw = false;
        $match = null;
        try {
            $match = $selector->pick($board, 'r01', $runner);
        } catch (\Throwable $e) {
            $threw = true;
        }

        if ($threw) {
            echo "FAIL testPickReturnsNullWhenAllNotReservable: expected null return, got exception\n";
            return 1;
        }
        if ($match !== null) {
            echo "FAIL testPickReturnsNullWhenAllNotReservable: expected null, got a match\n";
            return 1;
        }

        echo "OK testPickReturnsNullWhenAllNotReservable\n";
        return 0;
    }

    private function testPickPropagatesUnexpectedException(): int
    {
        $projectRoot = $this->makeTmpSubdir('pick-unexpected-exception');
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $this->writeBoard($boardPath, $this->boardWithEntries([
            $this->featureEntryAtReview(self::FEATURE_A, 'd01'),
            $this->featureEntryAtReview(self::FEATURE_B, 'd02'),
        ]));

        $selector = $this->makeSelector($projectRoot);
        $board = $this->loadBoard($projectRoot);

        $runner = new FakeBacklogCommandRunner();
        $runner->onReviewNext = static function (): void {
            throw new \RuntimeException('Backlog registry corrupted');
        };

        $caughtMessage = null;
        $callCount = 0;
        try {
            $selector->pick($board, 'r01', $runner);
        } catch (\RuntimeException $e) {
            $caughtMessage = $e->getMessage();
            $callCount = count($runner->calls);
        }

        if ($caughtMessage !== 'Backlog registry corrupted') {
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSelector(string $projectRoot, ?string $worktreesRoot = null): AgentReviewerSelector
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $worktreesRoot = $worktreesRoot ?? ($projectRoot . '/.agent-worktrees');

        return new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
    }

    private function loadBoard(string $projectRoot): BacklogBoard
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        return $boardService->loadBoard(BacklogPaths::boardPath($projectRoot));
    }

    private function makeTmpSubdir(string $name): string
    {
        $dir = $this->tmpDir . '/' . $name;
        mkdir(BacklogPaths::directory($dir), 0755, true);
        return $dir;
    }

    private function writeBoard(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    private function writeSessionsJson(string $projectRoot, array $data): void
    {
        $dir = $projectRoot . '/local/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/agent-sessions.json', json_encode($data));
    }

    /**
     * Assembles a YAML board from a list of structured active entries.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function boardWithEntries(array $entries): string
    {
        $order = ['kind', 'stage', 'feature', 'task', 'agent', 'reviewer', 'branch', self::YAML_KEY_FEATURE_BRANCH, 'base', 'pr', 'blocked', 'type'];
        $active = [];
        foreach ($entries as $entry) {
            $item = [];
            foreach ($order as $key) {
                if (array_key_exists($key, $entry)) {
                    $item[$key] = $entry[$key];
                }
            }
            $item['title'] = $entry['title'] ?? ($entry['feature'] ?? '');
            $active[] = $item;
        }

        return Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $active,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private function boardWithFeatureAtReview(string $feature, string $agent): string
    {
        return $this->boardWithEntries([$this->featureEntryAtReview($feature, $agent)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function featureEntryAtReview(string $feature, string $agent): array
    {
        return [
            'kind' => 'feature',
            'stage' => 'review',
            'feature' => $feature,
            'agent' => $agent,
            'branch' => 'feat/' . $feature,
            'base' => 'abc123def456',
            'pr' => 'none',
            'title' => 'Feature ' . $feature,
        ];
    }

    private function boardWithFeatureAtReviewing(string $feature, string $agent, string $reviewer): string
    {
        return $this->boardWithEntries([$this->featureEntryAtReviewing($feature, $agent, $reviewer)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function featureEntryAtReviewing(string $feature, string $agent, string $reviewer): array
    {
        return [
            'kind' => 'feature',
            'stage' => 'reviewing',
            'feature' => $feature,
            'agent' => $agent,
            'reviewer' => $reviewer,
            'branch' => 'feat/' . $feature,
            'base' => 'abc123def456',
            'pr' => 'none',
            'title' => 'Feature ' . $feature,
        ];
    }

    private function boardWithFeatureAtDevelopment(string $feature, string $agent): string
    {
        return $this->boardWithEntries([
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => $feature,
                'agent' => $agent,
                'branch' => 'feat/' . $feature,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Feature ' . $feature,
            ],
        ]);
    }

    private function boardWithTaskAtReview(string $feature, string $task, string $agent): string
    {
        return $this->boardWithEntries([
            [
                'kind' => 'task',
                'stage' => 'review',
                'feature' => $feature,
                'task' => $task,
                'agent' => $agent,
                self::YAML_KEY_FEATURE_BRANCH => 'feat/' . $feature,
                'branch' => 'feat/' . $feature . '--' . $task,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Task ' . $task,
            ],
        ]);
    }

    private function boardWithTaskAtReviewing(string $feature, string $task, string $agent, string $reviewer): string
    {
        return $this->boardWithEntries([
            [
                'kind' => 'task',
                'stage' => 'reviewing',
                'feature' => $feature,
                'task' => $task,
                'agent' => $agent,
                'reviewer' => $reviewer,
                self::YAML_KEY_FEATURE_BRANCH => 'feat/' . $feature,
                'branch' => 'feat/' . $feature . '--' . $task,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Task ' . $task,
            ],
        ]);
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
