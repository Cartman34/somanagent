<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Service\PostMergeSessionStopper;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\Toolkit\TextSlugger;
use Sowapps\Toolkit\Client\FilesystemClient;
use Sowapps\Toolkit\Console;
use Sowapps\Toolkit\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\SoManAgentApplication;

/**
 * Regression coverage for post-merge session stop target selection.
 */
final class PostMergeSessionStopperTest
{
    private string $tmpDir;

    /**
     * Sets up a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = dirname(__DIR__, 4) . '/local/tests/post-merge-session-stopper-test-' . uniqid('', true);
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
        return $this->testStopsDeveloperAndReviewer();
    }

    private function testStopsDeveloperAndReviewer(): int
    {
        $projectRoot = $this->makeProject('stops-dev-and-reviewer');
        $stopsPath = $projectRoot . '/local/tmp/stops.txt';

        try {
            $this->withEnv(['SOMANAGER_AGENT' => 'm01'], function () use ($projectRoot): void {
                $stopper = new PostMergeSessionStopper($this->makePresenter($projectRoot), $projectRoot);
                ob_start();
                $stopper->stopSessions('d13', 'r12');
                $output = (string) ob_get_clean();
                if (!str_contains($output, 'Auto-stopping sessions: d13, r12')) {
                    throw new \RuntimeException('expected auto-stop output to include developer and reviewer');
                }
            });
        } catch (\Throwable $e) {
            echo "FAIL testStopsDeveloperAndReviewer: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $actual = is_file($stopsPath) ? trim((string) file_get_contents($stopsPath)) : '';
        if ($actual !== "--code=d13\n--code=r12") {
            echo "FAIL testStopsDeveloperAndReviewer: expected d13 and r12 stop calls, got " . var_export($actual, true) . "\n";
            return 1;
        }

        echo "OK testStopsDeveloperAndReviewer\n";
        return 0;
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

    private function makeProject(string $name): string
    {
        $projectRoot = $this->tmpDir . '/' . $name;
        mkdir($projectRoot . '/scripts', 0755, true);
        mkdir($projectRoot . '/local/tmp', 0755, true);
        mkdir($projectRoot . '/local/backlog', 0755, true);
        file_put_contents(
            $projectRoot . '/scripts/backlog-agent.php',
            <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../local/tmp/stops.txt', ($argv[2] ?? '') . PHP_EOL, FILE_APPEND);
exit(0);
PHP
        );

        return $projectRoot;
    }

    /**
     * @param array<string, string|false> $env
     * @param callable(): void $callback
     */
    private function withEnv(array $env, callable $callback): void
    {
        $bufferLevel = ob_get_level();
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
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
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
