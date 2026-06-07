<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Command\BacklogReviewRejectCommand;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\Toolkit\TextSlugger;
use Sowapps\Toolkit\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\Toolkit\Console;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\Toolkit\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Symfony\Component\Yaml\Yaml;

/**
 * Command-level regression tests for {@see BacklogReviewRejectCommand}.
 *
 * Verifies that review-reject preserves meta.reviewer in the board entry
 * after the command executes (both feature and task paths).
 */
final class BacklogReviewRejectCommandTest
{
    private const FEATURE_SLUG = 'reject-test-feature';

    private const TASK_SLUG = 'reject-test-task';

    private const REVIEWER_CODE = 'r12';

    private const DEVELOPER_CODE = 'd13';

    private string $tmpDir;

    /**
     * Sets up a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = dirname(__DIR__, 4) . '/local/tests/backlog-review-reject-command-test-' . uniqid('', true);
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
        $failed = 0;
        $failed += $this->testFeatureRejectPreservesReviewer();
        $failed += $this->testTaskRejectPreservesReviewer();

        return $failed;
    }

    private function testFeatureRejectPreservesReviewer(): int
    {
        $projectRoot = $this->makeProject('feature-reject-preserves-reviewer');
        $boardPath = $projectRoot . '/local/backlog-board.yaml';
        $reviewPath = $projectRoot . '/local/backlog-review.md';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => self::FEATURE_SLUG,
                'developer' => self::DEVELOPER_CODE,
                'reviewer' => self::REVIEWER_CODE,
                'branch' => 'tech/' . self::FEATURE_SLUG,
                'base' => 'abc123',
                'pr' => 'none',
                'type' => 'tech',
                'title' => 'Reject test feature',
            ],
        ]);
        file_put_contents($reviewPath, "# Backlog review\n\n## Current review\n\n");

        $bodyFile = $projectRoot . '/reject-body.md';
        file_put_contents($bodyFile, "BLOCKER — something is wrong\n");

        $threw = false;
        try {
            $this->withEnv(['SOMANAGER_AGENT' => self::REVIEWER_CODE], function () use ($projectRoot, $boardPath, $reviewPath, $bodyFile): void {
                $command = $this->makeCommand($projectRoot, $boardPath, $reviewPath);
                $command->handle([self::FEATURE_SLUG], [BacklogCliOption::BODY_FILE->value => $bodyFile]);
            });
        } catch (\Throwable $e) {
            $threw = true;
            echo "FAIL testFeatureRejectPreservesReviewer: unexpected exception: {$e->getMessage()}\n";
        }

        if ($threw) {
            return 1;
        }

        $entries = $this->loadBoard($boardPath)->getEntries(BacklogBoard::SECTION_ACTIVE);
        if ($entries === []) {
            echo "FAIL testFeatureRejectPreservesReviewer: board has no active entries after command\n";
            return 1;
        }

        $entry = $entries[0];
        if ($entry->getStage() !== BacklogBoard::STAGE_REJECTED) {
            echo "FAIL testFeatureRejectPreservesReviewer: expected stage=rejected, got {$entry->getStage()}\n";
            return 1;
        }
        if ($entry->getReviewer() !== self::REVIEWER_CODE) {
            echo "FAIL testFeatureRejectPreservesReviewer: reviewer cleared after reject (got {$entry->getReviewer()})\n";
            return 1;
        }

        echo "OK testFeatureRejectPreservesReviewer\n";
        return 0;
    }

    private function testTaskRejectPreservesReviewer(): int
    {
        $projectRoot = $this->makeProject('task-reject-preserves-reviewer');
        $boardPath = $projectRoot . '/local/backlog-board.yaml';
        $reviewPath = $projectRoot . '/local/backlog-review.md';

        $taskRef = self::FEATURE_SLUG . '/' . self::TASK_SLUG;

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => self::FEATURE_SLUG,
                'developer' => self::DEVELOPER_CODE,
                'branch' => 'tech/' . self::FEATURE_SLUG,
                'base' => 'abc123',
                'pr' => 'none',
                'type' => 'tech',
                'title' => 'Reject test feature',
            ],
            [
                'kind' => 'task',
                'stage' => 'reviewing',
                'feature' => self::FEATURE_SLUG,
                'task' => self::TASK_SLUG,
                'developer' => self::DEVELOPER_CODE,
                'reviewer' => self::REVIEWER_CODE,
                'branch' => 'tech/' . self::FEATURE_SLUG . '--' . self::TASK_SLUG,
                'feature-branch' => 'tech/' . self::FEATURE_SLUG,
                'base' => 'abc123',
                'pr' => 'none',
                'type' => 'tech',
                'title' => 'Reject test task',
            ],
        ]);
        file_put_contents($reviewPath, "# Backlog review\n\n## Current review\n\n");

        $bodyFile = $projectRoot . '/reject-task-body.md';
        file_put_contents($bodyFile, "BLOCKER — task is wrong\n");

        $threw = false;
        try {
            $this->withEnv(['SOMANAGER_AGENT' => self::REVIEWER_CODE], function () use ($projectRoot, $boardPath, $reviewPath, $bodyFile, $taskRef): void {
                $command = $this->makeCommand($projectRoot, $boardPath, $reviewPath);
                $command->handle([$taskRef], [BacklogCliOption::BODY_FILE->value => $bodyFile]);
            });
        } catch (\Throwable $e) {
            $threw = true;
            echo "FAIL testTaskRejectPreservesReviewer: unexpected exception: {$e->getMessage()}\n";
        }

        if ($threw) {
            return 1;
        }

        $entries = $this->loadBoard($boardPath)->getEntries(BacklogBoard::SECTION_ACTIVE);
        $taskEntry = null;
        foreach ($entries as $entry) {
            if ($entry->getTask() === self::TASK_SLUG) {
                $taskEntry = $entry;
            }
        }

        if ($taskEntry === null) {
            echo "FAIL testTaskRejectPreservesReviewer: task entry not found in board after command\n";
            return 1;
        }

        if ($taskEntry->getStage() !== BacklogBoard::STAGE_REJECTED) {
            echo "FAIL testTaskRejectPreservesReviewer: expected stage=rejected, got {$taskEntry->getStage()}\n";
            return 1;
        }
        if ($taskEntry->getReviewer() !== self::REVIEWER_CODE) {
            echo "FAIL testTaskRejectPreservesReviewer: reviewer cleared after reject (got {$taskEntry->getReviewer()})\n";
            return 1;
        }

        echo "OK testTaskRejectPreservesReviewer\n";
        return 0;
    }

    private function makeCommand(string $projectRoot, string $boardPath, string $reviewPath): BacklogReviewRejectCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $bodyFilePathResolver = new BodyFilePathResolver(
            $boardService,
            $this->uninitialized(BacklogWorktreeService::class),
            Console::getInstance(),
            $boardPath,
        );

        $command = new BacklogReviewRejectCommand(
            $this->makePresenter($projectRoot),
            false,
            $projectRoot,
            $boardService,
            new BacklogReviewBodyFormatter(),
            $bodyFilePathResolver,
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
            new ConsoleClient($projectRoot, false, SoManAgentApplication::getInstance(), static function (string $_message): void {}),
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
