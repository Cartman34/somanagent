<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AgentWhoamiCommand;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\Toolkit\TextSlugger;
use Sowapps\Toolkit\Client\FilesystemClient;
use Sowapps\Toolkit\Console;
use Symfony\Component\Yaml\Yaml;

/**
 * Command-level tests for {@see AgentWhoamiCommand}.
 *
 * Covers: env var contract refusal, developer/reviewer/manager identity output, and
 * graceful behavior when the backlog board file is missing. SOMANAGER_* env vars are
 * set/restored per case so the suite stays self-contained.
 */
final class AgentWhoamiCommandTest
{
    private string $tmpDir;

    /**
     * @var array<string, string|false>
     */
    private array $envBackup = [];

    /**
     * Creates temp directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-whoami-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes temp directory and all its contents.
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

        $failed += $this->testRefusesWhenEnvVarsMissing();
        $failed += $this->testDeveloperIdentityFromBoard();
        $failed += $this->testReviewerIdentityFromBoard();
        $failed += $this->testManagerIdentityWithoutBacklogEntry();

        return $failed;
    }

    private function testRefusesWhenEnvVarsMissing(): int
    {
        $this->resetEnv();

        $cmd = $this->buildCommand($this->tmpDir . '/missing.md');

        $threw = false;
        try {
            $cmd->handle([], []);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'must be run from a session started by backlog-agent.php');
        }

        if (!$threw) {
            echo "FAIL testRefusesWhenEnvVarsMissing: expected explicit env-vars error\n";
            return 1;
        }
        echo "OK testRefusesWhenEnvVarsMissing\n";
        return 0;
    }

    private function testDeveloperIdentityFromBoard(): int
    {
        $this->resetEnv();
        $this->setEnv('SOMANAGER_AGENT', 'd01');
        $this->setEnv('SOMANAGER_ROLE', 'developer');
        $this->setEnv('SOMANAGER_CLIENT', 'claude');

        $boardPath = $this->tmpDir . '/dev-board-' . uniqid('', true) . '.yaml';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'payments-feature',
                'developer' => 'd01',
                'branch' => 'feat/payments-feature',
                'type' => 'feat',
            ],
        ]);

        $output = $this->captureHandle($this->buildCommand($boardPath));

        foreach (['Code          : d01', 'Role          : developer', 'Client        : claude', 'Active task   : payments-feature'] as $needle) {
            if (!str_contains($output, $needle)) {
                echo "FAIL testDeveloperIdentityFromBoard: missing '{$needle}' in output\n{$output}\n";
                $this->resetEnv();
                return 1;
            }
        }
        $this->resetEnv();
        echo "OK testDeveloperIdentityFromBoard\n";
        return 0;
    }

    private function testReviewerIdentityFromBoard(): int
    {
        $this->resetEnv();
        $this->setEnv('SOMANAGER_AGENT', 'r01');
        $this->setEnv('SOMANAGER_ROLE', 'reviewer');
        $this->setEnv('SOMANAGER_CLIENT', 'codex');

        $boardPath = $this->tmpDir . '/rev-board-' . uniqid('', true) . '.yaml';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => 'crypto-feature',
                'developer' => 'd04',
                'reviewer' => 'r01',
                'branch' => 'feat/crypto-feature',
                'type' => 'feat',
            ],
        ]);

        $output = $this->captureHandle($this->buildCommand($boardPath));

        if (!str_contains($output, '[reviewing] crypto-feature (developer: d04)')) {
            echo "FAIL testReviewerIdentityFromBoard: expected reviewer-derived active task line\n{$output}\n";
            $this->resetEnv();
            return 1;
        }
        $this->resetEnv();
        echo "OK testReviewerIdentityFromBoard\n";
        return 0;
    }

    private function testManagerIdentityWithoutBacklogEntry(): int
    {
        $this->resetEnv();
        $this->setEnv('SOMANAGER_AGENT', 'm01');
        $this->setEnv('SOMANAGER_ROLE', 'manager');
        $this->setEnv('SOMANAGER_CLIENT', 'gemini');

        // Empty board: manager must still be identified without any active backlog entry.
        $boardPath = $this->tmpDir . '/mgr-board-' . uniqid('', true) . '.yaml';
        $this->writeBoard($boardPath, []);

        $output = $this->captureHandle($this->buildCommand($boardPath));

        foreach (['Role          : manager', 'Client        : gemini', 'Active task   : no active task'] as $needle) {
            if (!str_contains($output, $needle)) {
                echo "FAIL testManagerIdentityWithoutBacklogEntry: missing '{$needle}' in output\n{$output}\n";
                $this->resetEnv();
                return 1;
            }
        }
        $this->resetEnv();
        echo "OK testManagerIdentityWithoutBacklogEntry\n";
        return 0;
    }

    private function buildCommand(string $boardPath): AgentWhoamiCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new AgentWhoamiCommand(
            Console::getInstance(),
            $boardPath,
            $boardService,
        );
    }

    private function captureHandle(AgentWhoamiCommand $command): string
    {
        ob_start();
        try {
            $command->handle([], []);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * @param list<array<string, mixed>> $activeEntries
     */
    private function writeBoard(string $path, array $activeEntries): void
    {
        $order = ['kind', 'stage', 'feature', 'task', 'developer', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'blocked', 'type'];
        $active = [];
        foreach ($activeEntries as $entry) {
            $item = [];
            foreach ($order as $key) {
                if (array_key_exists($key, $entry)) {
                    $item[$key] = $entry[$key];
                }
            }
            $item['title'] = $entry['title'] ?? ($entry['feature'] ?? '');
            $active[] = $item;
        }
        file_put_contents($path, Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $active,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        putenv("{$name}={$value}");
    }

    private function resetEnv(): void
    {
        foreach (['SOMANAGER_AGENT', 'SOMANAGER_ROLE', 'SOMANAGER_CLIENT'] as $name) {
            if (!array_key_exists($name, $this->envBackup)) {
                $this->envBackup[$name] = getenv($name);
            }
            putenv($name);
        }
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
