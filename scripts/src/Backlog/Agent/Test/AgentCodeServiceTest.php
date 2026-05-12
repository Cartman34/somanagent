<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for AgentCodeService.
 */
final class AgentCodeServiceTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-test-' . uniqid('', true);
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

        $failed += $this->testAllocateFirstCode();
        $failed += $this->testAllocateSkipsUsedWorktree();
        $failed += $this->testAllocateSkipsSessionEntry();
        $failed += $this->testValidateFormatError();
        $failed += $this->testValidateRoleMismatch();
        $failed += $this->testValidateActiveSessionThrows();
        $failed += $this->testAllocateManagerPrefix();

        return $failed;
    }

    private function testAllocateFirstCode(): int
    {
        $service = $this->makeService();
        $code = $service->allocateForRole(AgentRole::DEVELOPER);
        if ($code !== 'd01') {
            echo "FAIL testAllocateFirstCode: expected d01, got {$code}\n";
            return 1;
        }
        echo "OK testAllocateFirstCode\n";
        return 0;
    }

    private function testAllocateSkipsUsedWorktree(): int
    {
        $worktrees = $this->tmpDir . '/worktrees';
        mkdir($worktrees . '/d01', 0755, true);

        $service = $this->makeService(worktreesRoot: $worktrees);
        $code = $service->allocateForRole(AgentRole::DEVELOPER);

        $this->rmdir($worktrees);

        if ($code !== 'd02') {
            echo "FAIL testAllocateSkipsUsedWorktree: expected d02, got {$code}\n";
            return 1;
        }
        echo "OK testAllocateSkipsUsedWorktree\n";
        return 0;
    }

    private function testAllocateSkipsSessionEntry(): int
    {
        $sessionsDir = $this->tmpDir . '/local/tmp';
        mkdir($sessionsDir, 0755, true);
        file_put_contents($sessionsDir . '/agent-sessions.json', json_encode([
            'd01' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 99999,
                'worktree' => '/fake/path',
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]));

        $service = $this->makeService(projectRoot: $this->tmpDir);
        $code = $service->allocateForRole(AgentRole::DEVELOPER);

        $this->rmdir($sessionsDir);
        rmdir($this->tmpDir . '/local');

        if ($code !== 'd02') {
            echo "FAIL testAllocateSkipsSessionEntry: expected d02, got {$code}\n";
            return 1;
        }
        echo "OK testAllocateSkipsSessionEntry\n";
        return 0;
    }

    private function testValidateFormatError(): int
    {
        $service = $this->makeService();
        try {
            $service->validate('invalid', AgentRole::DEVELOPER);
            echo "FAIL testValidateFormatError: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Invalid agent code format')) {
                echo "FAIL testValidateFormatError: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testValidateFormatError\n";
        return 0;
    }

    private function testValidateRoleMismatch(): int
    {
        $service = $this->makeService();
        try {
            $service->validate('r01', AgentRole::DEVELOPER);
            echo "FAIL testValidateRoleMismatch: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), "does not match role")) {
                echo "FAIL testValidateRoleMismatch: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testValidateRoleMismatch\n";
        return 0;
    }

    private function testValidateActiveSessionThrows(): int
    {
        $sessionsDir = $this->tmpDir . '/local/tmp';
        mkdir($sessionsDir, 0755, true);
        file_put_contents($sessionsDir . '/agent-sessions.json', json_encode([
            'd01' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 1,
                'worktree' => '/fake',
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]));

        $service = $this->makeService(projectRoot: $this->tmpDir);
        try {
            $service->validate('d01', AgentRole::DEVELOPER);
            echo "FAIL testValidateActiveSessionThrows: expected ActiveSessionException\n";
            $this->rmdir($sessionsDir);
            rmdir($this->tmpDir . '/local');
            return 1;
        } catch (ActiveSessionException $e) {
            echo "OK testValidateActiveSessionThrows\n";
        }

        $this->rmdir($sessionsDir);
        if (is_dir($this->tmpDir . '/local')) {
            rmdir($this->tmpDir . '/local');
        }
        return 0;
    }

    private function testAllocateManagerPrefix(): int
    {
        $service = $this->makeService();
        $code = $service->allocateForRole(AgentRole::MANAGER);
        if ($code !== 'm01') {
            echo "FAIL testAllocateManagerPrefix: expected m01, got {$code}\n";
            return 1;
        }
        echo "OK testAllocateManagerPrefix\n";
        return 0;
    }

    private function makeService(?string $projectRoot = null, ?string $worktreesRoot = null): AgentCodeService
    {
        $projectRoot = $projectRoot ?? $this->tmpDir;
        $worktreesRoot = $worktreesRoot ?? ($this->tmpDir . '/worktrees-empty');
        $boardPath = $projectRoot . '/local/backlog-board.md';

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        return new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService);
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
