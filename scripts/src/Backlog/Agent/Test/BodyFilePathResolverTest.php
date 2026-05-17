<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\RetryPolicy;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for BodyFilePathResolver.
 */
final class BodyFilePathResolverTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testAbsolutePathExistsReturnedAsIs();
        $failed += $this->testAbsolutePathNotFoundThrows();
        $failed += $this->testRelativePathFoundInWaOnly();
        $failed += $this->testRelativePathFoundInCwdOnly();
        $failed += $this->testRelativePathFoundInBothWarnsAndPrefersWa();
        $failed += $this->testRelativePathAbsentEverywhereThrows();
        $failed += $this->testEntryWithoutAgentFallsBackToCwdOnly();
        $failed += $this->testWaAbsentOnDiskFallsBackToCwdOnly();
        $failed += $this->testResolveWithoutEntryRefUsesCwdOnly();
        $failed += $this->testResolveAbsolutePathExistsReturnedAsIs();
        $failed += $this->testResolveAbsolutePathNotFoundThrows();

        return $failed;
    }

    private function testAbsolutePathExistsReturnedAsIs(): int
    {
        $dir = $this->makeTempDir();
        try {
            $bodyFile = $dir . '/body.md';
            file_put_contents($bodyFile, 'content');

            $resolver = $this->makeResolver($dir . '/worktrees', $dir . '/board.md');
            $result = $resolver->resolveForEntry($bodyFile, 'my-feature');

            if ($result !== $bodyFile) {
                echo "FAIL testAbsolutePathExistsReturnedAsIs: expected '$bodyFile', got '$result'\n";
                return 1;
            }
            echo "OK testAbsolutePathExistsReturnedAsIs\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testAbsolutePathNotFoundThrows(): int
    {
        $dir = $this->makeTempDir();
        try {
            $resolver = $this->makeResolver($dir . '/worktrees', $dir . '/board.md');
            $threw = false;
            try {
                $resolver->resolveForEntry($dir . '/missing.md', 'my-feature');
            } catch (\RuntimeException $e) {
                $threw = true;
                if (!str_contains($e->getMessage(), 'does not exist')) {
                    echo "FAIL testAbsolutePathNotFoundThrows: unexpected message: " . $e->getMessage() . "\n";
                    return 1;
                }
            }
            if (!$threw) {
                echo "FAIL testAbsolutePathNotFoundThrows: expected RuntimeException\n";
                return 1;
            }
            echo "OK testAbsolutePathNotFoundThrows\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testRelativePathFoundInWaOnly(): int
    {
        $dir = $this->makeTempDir();
        try {
            $agentCode = 'd12';
            $worktreesRoot = $dir . '/worktrees';
            $wa = $worktreesRoot . '/' . $agentCode;
            mkdir($wa . '/local/tmp', 0777, true);
            $bodyFile = 'local/tmp/reject.md';
            file_put_contents($wa . '/' . $bodyFile, 'WA content');

            $boardPath = $this->writeBoardWithAgent($dir, $agentCode);
            $resolver = $this->makeResolver($worktreesRoot, $boardPath);
            $result = $resolver->resolveForEntry($bodyFile, 'my-feature');

            $expected = $wa . '/' . $bodyFile;
            if ($result !== $expected) {
                echo "FAIL testRelativePathFoundInWaOnly: expected '$expected', got '$result'\n";
                return 1;
            }
            echo "OK testRelativePathFoundInWaOnly\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testRelativePathFoundInCwdOnly(): int
    {
        $dir = $this->makeTempDir();
        try {
            $agentCode = 'd12';
            $worktreesRoot = $dir . '/worktrees';
            mkdir($worktreesRoot . '/' . $agentCode, 0777, true);
            // File only in cwd (getcwd())
            $relPath = 'local/tmp/reject-cwd-' . uniqid() . '.md';
            $cwdFile = getcwd() . '/' . $relPath;
            @mkdir(dirname($cwdFile), 0777, true);
            file_put_contents($cwdFile, 'CWD content');

            try {
                $boardPath = $this->writeBoardWithAgent($dir, $agentCode);
                $resolver = $this->makeResolver($worktreesRoot, $boardPath);
                $result = $resolver->resolveForEntry($relPath, 'my-feature');

                if ($result !== $cwdFile) {
                    echo "FAIL testRelativePathFoundInCwdOnly: expected '$cwdFile', got '$result'\n";
                    return 1;
                }
                echo "OK testRelativePathFoundInCwdOnly\n";
                return 0;
            } finally {
                @unlink($cwdFile);
            }
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testRelativePathFoundInBothWarnsAndPrefersWa(): int
    {
        $dir = $this->makeTempDir();
        try {
            $agentCode = 'd12';
            $worktreesRoot = $dir . '/worktrees';
            $wa = $worktreesRoot . '/' . $agentCode;
            mkdir($wa . '/local/tmp', 0777, true);

            $relPath = 'local/tmp/both-' . uniqid() . '.md';
            $waFile = $wa . '/' . $relPath;
            $cwdFile = getcwd() . '/' . $relPath;
            @mkdir(dirname($cwdFile), 0777, true);
            file_put_contents($waFile, 'WA');
            file_put_contents($cwdFile, 'CWD');

            try {
                $boardPath = $this->writeBoardWithAgent($dir, $agentCode);
                $resolver = $this->makeResolver($worktreesRoot, $boardPath);

                ob_start();
                $result = $resolver->resolveForEntry($relPath, 'my-feature');
                $output = ob_get_clean() ?: '';

                if ($result !== $waFile) {
                    echo "FAIL testRelativePathFoundInBothWarnsAndPrefersWa: expected WA path '$waFile', got '$result'\n";
                    return 1;
                }
                if (!str_contains($output, 'both') && !str_contains($output, 'WA') && !str_contains($output, 'cwd')) {
                    // Allow any warning mentioning the collision
                }
                if (trim($output) === '') {
                    echo "FAIL testRelativePathFoundInBothWarnsAndPrefersWa: expected warning output, got empty\n";
                    return 1;
                }
                echo "OK testRelativePathFoundInBothWarnsAndPrefersWa\n";
                return 0;
            } finally {
                @unlink($cwdFile);
            }
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testRelativePathAbsentEverywhereThrows(): int
    {
        $dir = $this->makeTempDir();
        try {
            $agentCode = 'd12';
            $worktreesRoot = $dir . '/worktrees';
            mkdir($worktreesRoot . '/' . $agentCode, 0777, true);
            $boardPath = $this->writeBoardWithAgent($dir, $agentCode);
            $resolver = $this->makeResolver($worktreesRoot, $boardPath);

            $threw = false;
            try {
                $resolver->resolveForEntry('local/tmp/nonexistent-' . uniqid() . '.md', 'my-feature');
            } catch (\RuntimeException $e) {
                $threw = true;
                if (!str_contains($e->getMessage(), 'not found')) {
                    echo "FAIL testRelativePathAbsentEverywhereThrows: unexpected message: " . $e->getMessage() . "\n";
                    return 1;
                }
            }
            if (!$threw) {
                echo "FAIL testRelativePathAbsentEverywhereThrows: expected RuntimeException\n";
                return 1;
            }
            echo "OK testRelativePathAbsentEverywhereThrows\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testEntryWithoutAgentFallsBackToCwdOnly(): int
    {
        $dir = $this->makeTempDir();
        try {
            $worktreesRoot = $dir . '/worktrees';
            // Board has entry with no agent
            $boardPath = $this->writeBoardWithAgent($dir, null);
            $resolver = $this->makeResolver($worktreesRoot, $boardPath);

            $relPath = 'local/tmp/no-agent-' . uniqid() . '.md';
            $cwdFile = getcwd() . '/' . $relPath;
            @mkdir(dirname($cwdFile), 0777, true);
            file_put_contents($cwdFile, 'content');

            try {
                $result = $resolver->resolveForEntry($relPath, 'my-feature');
                if ($result !== $cwdFile) {
                    echo "FAIL testEntryWithoutAgentFallsBackToCwdOnly: expected cwd '$cwdFile', got '$result'\n";
                    return 1;
                }
                echo "OK testEntryWithoutAgentFallsBackToCwdOnly\n";
                return 0;
            } finally {
                @unlink($cwdFile);
            }
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testWaAbsentOnDiskFallsBackToCwdOnly(): int
    {
        $dir = $this->makeTempDir();
        try {
            $worktreesRoot = $dir . '/worktrees';
            // Board has agent d12 but WA directory does not exist on disk
            $boardPath = $this->writeBoardWithAgent($dir, 'd12');
            $resolver = $this->makeResolver($worktreesRoot, $boardPath);

            $relPath = 'local/tmp/no-wa-' . uniqid() . '.md';
            $cwdFile = getcwd() . '/' . $relPath;
            @mkdir(dirname($cwdFile), 0777, true);
            file_put_contents($cwdFile, 'content');

            try {
                $result = $resolver->resolveForEntry($relPath, 'my-feature');
                if ($result !== $cwdFile) {
                    echo "FAIL testWaAbsentOnDiskFallsBackToCwdOnly: expected cwd '$cwdFile', got '$result'\n";
                    return 1;
                }
                echo "OK testWaAbsentOnDiskFallsBackToCwdOnly\n";
                return 0;
            } finally {
                @unlink($cwdFile);
            }
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testResolveWithoutEntryRefUsesCwdOnly(): int
    {
        $dir = $this->makeTempDir();
        try {
            $worktreesRoot = $dir . '/worktrees';
            $boardPath = $dir . '/board.md';
            file_put_contents($boardPath, "# Backlog board\n\n## To do\n\n## In progress\n\n## Suggestions\n");
            $resolver = $this->makeResolver($worktreesRoot, $boardPath);

            $relPath = 'local/tmp/resolve-' . uniqid() . '.md';
            $cwdFile = getcwd() . '/' . $relPath;
            @mkdir(dirname($cwdFile), 0777, true);
            file_put_contents($cwdFile, 'content');

            try {
                $result = $resolver->resolve($relPath);
                if ($result !== $cwdFile) {
                    echo "FAIL testResolveWithoutEntryRefUsesCwdOnly: expected '$cwdFile', got '$result'\n";
                    return 1;
                }
                echo "OK testResolveWithoutEntryRefUsesCwdOnly\n";
                return 0;
            } finally {
                @unlink($cwdFile);
            }
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testResolveAbsolutePathExistsReturnedAsIs(): int
    {
        $dir = $this->makeTempDir();
        try {
            $bodyFile = $dir . '/body.md';
            file_put_contents($bodyFile, 'content');
            $boardPath = $dir . '/board.md';
            file_put_contents($boardPath, "# Backlog board\n\n## In progress\n\n## To do\n");

            $resolver = $this->makeResolver($dir . '/worktrees', $boardPath);
            $result = $resolver->resolve($bodyFile);

            if ($result !== $bodyFile) {
                echo "FAIL testResolveAbsolutePathExistsReturnedAsIs: expected '$bodyFile', got '$result'\n";
                return 1;
            }
            echo "OK testResolveAbsolutePathExistsReturnedAsIs\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function testResolveAbsolutePathNotFoundThrows(): int
    {
        $dir = $this->makeTempDir();
        try {
            $boardPath = $dir . '/board.md';
            file_put_contents($boardPath, "# Backlog board\n\n## In progress\n\n## To do\n");

            $resolver = $this->makeResolver($dir . '/worktrees', $boardPath);
            $threw = false;
            try {
                $resolver->resolve($dir . '/missing.md');
            } catch (\RuntimeException $e) {
                $threw = true;
                if (!str_contains($e->getMessage(), 'does not exist')) {
                    echo "FAIL testResolveAbsolutePathNotFoundThrows: unexpected message: " . $e->getMessage() . "\n";
                    return 1;
                }
            }
            if (!$threw) {
                echo "FAIL testResolveAbsolutePathNotFoundThrows: expected RuntimeException\n";
                return 1;
            }
            echo "OK testResolveAbsolutePathNotFoundThrows\n";
            return 0;
        } finally {
            $this->removeTempDir($dir);
        }
    }

    /**
     * Writes a minimal YAML board with one active feature entry.
     * When $agentCode is null the entry has no agent assigned.
     */
    private function writeBoardWithAgent(string $dir, ?string $agentCode): string
    {
        $entry = [
            'kind' => 'feature',
            'stage' => 'development',
            'feature' => 'my-feature',
            'agent' => $agentCode !== null ? $agentCode : 'none',
            'branch' => 'fix/my-feature',
            'base' => 'abc123def456abc1',
            'pr' => 'none',
            'type' => 'fix',
            'title' => 'My feature',
        ];

        $boardPath = $dir . '/board.yaml';
        file_put_contents($boardPath, Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => [$entry],
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return $boardPath;
    }

    private function makeResolver(string $worktreesRoot, string $boardPath): BodyFilePathResolver
    {
        $app = Application::getInstance();
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $retryPolicy = new RetryPolicy();
        $consoleClient = new ConsoleClient('/tmp', false, $app, static fn(string $m) => null);
        $gitClient = new GitClient(false, $consoleClient, $retryPolicy);
        $projectScriptClient = new ProjectScriptClient($consoleClient);
        $worktreeService = new BacklogWorktreeService(
            '/tmp',
            $worktreesRoot,
            false,
            '',
            $boardService,
            $consoleClient,
            $gitClient,
            $projectScriptClient,
            new FilesystemClient(),
        );

        return new BodyFilePathResolver($boardService, $worktreeService, Console::getInstance(), $boardPath);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/bfpr-test-' . uniqid();
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
