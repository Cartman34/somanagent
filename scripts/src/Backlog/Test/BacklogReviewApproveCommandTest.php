<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Command\BacklogReviewApproveCommand;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression coverage for reviewer metadata preserved by review approvals.
 */
final class BacklogReviewApproveCommandTest
{
    private const string FEATURE_SLUG = 'reviewer-stop';

    private string $tmpDir;

    /**
     * Sets up a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = dirname(__DIR__, 4) . '/local/tests/backlog-review-approve-command-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory after the test.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the cumulative exit code.
     */
    public function run(): int
    {
        return $this->testTaskApprovalPreservesReviewer()
            + $this->testPresenterShowsReviewerForEveryActiveStage();
    }

    private function testTaskApprovalPreservesReviewer(): int
    {
        $projectRoot = $this->makeProject('task-approve-preserves-reviewer');
        $boardPath = $projectRoot . '/local/backlog-board.yaml';
        $reviewPath = $projectRoot . '/local/backlog-review.md';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => self::FEATURE_SLUG,
                'branch' => 'tech/reviewer-stop',
                'base' => 'abc123',
                'pr' => 'none',
                'type' => 'tech',
                'title' => 'Reviewer stop',
            ],
            [
                'kind' => 'task',
                'stage' => 'reviewing',
                'feature' => self::FEATURE_SLUG,
                'task' => 'approved-task',
                'agent' => 'd13',
                'reviewer' => 'r12',
                'branch' => 'tech/reviewer-stop--approved-task',
                'feature-branch' => 'tech/reviewer-stop',
                'base' => 'abc123',
                'pr' => 'none',
                'type' => 'tech',
                'title' => 'Approved task',
            ],
        ]);
        file_put_contents($reviewPath, "# Backlog review\n\n## Current review\n\n### reviewer-stop/approved-task\n\n- stale note\n");

        try {
            $this->withEnv(['SOMANAGER_AGENT' => 'r12'], function () use ($projectRoot, $boardPath, $reviewPath): void {
                $command = $this->makeCommand($projectRoot, $boardPath, $reviewPath);
                $command->handle(['reviewer-stop/approved-task'], []);
            });
        } catch (\Throwable $e) {
            echo "FAIL testTaskApprovalPreservesReviewer: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $entry = $this->loadBoard($boardPath)->getEntries(BacklogBoard::SECTION_ACTIVE)[1];
        if ($entry->getStage() !== BacklogBoard::STAGE_APPROVED || $entry->getReviewer() !== 'r12') {
            echo "FAIL testTaskApprovalPreservesReviewer: expected stage=approved and reviewer=r12\n";
            return 1;
        }
        $presentationFailure = $this->assertPresenterShowsApprovedReviewer($projectRoot, $entry);
        if ($presentationFailure !== null) {
            echo "FAIL testTaskApprovalPreservesReviewer: {$presentationFailure}\n";
            return 1;
        }

        echo "OK testTaskApprovalPreservesReviewer\n";
        return 0;
    }

    private function assertPresenterShowsApprovedReviewer(string $projectRoot, BoardEntry $entry): ?string
    {
        $presenter = $this->makePresenter($projectRoot);

        ob_start();
        $presenter->displayEntryStatus($entry);
        $statusOutput = (string) ob_get_clean();
        if (!str_contains($statusOutput, 'Reviewer: r12')) {
            return 'status output should include Reviewer: r12 for approved entries';
        }

        ob_start();
        $presenter->displayEntryLine($entry);
        $lineOutput = (string) ob_get_clean();
        if (!str_contains($lineOutput, 'reviewer=r12')) {
            return 'list output should include reviewer=r12 for approved entries';
        }

        return null;
    }

    private function testPresenterShowsReviewerForEveryActiveStage(): int
    {
        $projectRoot = $this->makeProject('presenter-reviewer-by-stage');
        $stages = [
            BacklogBoard::STAGE_IN_PROGRESS,
            BacklogBoard::STAGE_PENDING_REVIEW,
            BacklogBoard::STAGE_REVIEWING,
            BacklogBoard::STAGE_APPROVED,
            BacklogBoard::STAGE_REJECTED,
        ];

        foreach ($stages as $stage) {
            foreach ([null, 'r22'] as $reviewer) {
                $entry = $this->entryForStage($stage, $reviewer);
                $expected = $reviewer ?? 'none';
                $failure = $this->assertPresenterShowsReviewer($projectRoot, $entry, $expected);
                if ($failure !== null) {
                    echo "FAIL testPresenterShowsReviewerForEveryActiveStage: stage={$stage} reviewer={$expected} {$failure}\n";
                    return 1;
                }
            }
        }

        echo "OK testPresenterShowsReviewerForEveryActiveStage\n";
        return 0;
    }

    private function assertPresenterShowsReviewer(string $projectRoot, BoardEntry $entry, string $expectedReviewer): ?string
    {
        $presenter = $this->makePresenter($projectRoot);

        ob_start();
        $presenter->displayEntryStatus($entry);
        $statusOutput = (string) ob_get_clean();
        if (!str_contains($statusOutput, 'Reviewer: ' . $expectedReviewer)) {
            return 'status output should include Reviewer: ' . $expectedReviewer;
        }

        ob_start();
        $presenter->displayEntryLine($entry);
        $lineOutput = (string) ob_get_clean();
        if (!str_contains($lineOutput, 'reviewer=' . $expectedReviewer)) {
            return 'list output should include reviewer=' . $expectedReviewer;
        }

        return null;
    }

    private function entryForStage(string $stage, ?string $reviewer): BoardEntry
    {
        $entry = new BoardEntry('Reviewer visibility');
        $entry->setKind('feature');
        $entry->setStage($stage);
        $entry->setFeature('reviewer-visibility-' . str_replace('-', '', $stage));
        $entry->setDeveloper('d13');
        $entry->setReviewer($reviewer);
        $entry->setBranch('tech/reviewer-visibility');
        $entry->setBase('abc123');
        $entry->setPr('none');
        $entry->setType('tech');

        return $entry;
    }

    private function makeCommand(string $projectRoot, string $boardPath, string $reviewPath): BacklogReviewApproveCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $command = new BacklogReviewApproveCommand(
            $this->makePresenter($projectRoot),
            false,
            $projectRoot,
            $boardService,
            $this->uninitialized(GitService::class),
            $this->uninitialized(PullRequestService::class),
            $this->uninitialized(BodyFilePathResolver::class),
        );
        $command->setBoardPath($boardPath);
        $command->setReviewFilePath($reviewPath);

        return $command;
    }

    private function makePresenter(string $projectRoot): BacklogPresenter
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new BacklogPresenter(
            Console::getInstance(),
            new ConsoleClient($projectRoot, false, Application::getInstance(), static function (string $_message): void {}),
            $boardService,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeBoard(string $path, array $entries): void
    {
        file_put_contents($path, Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $entries,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function loadBoard(string $path): BacklogBoard
    {
        return (new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false))->loadBoard($path);
    }

    private function makeProject(string $name): string
    {
        $projectRoot = $this->tmpDir . '/' . $name;
        mkdir($projectRoot . '/local', 0755, true);

        return $projectRoot;
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
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
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
