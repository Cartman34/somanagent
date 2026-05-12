<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\SessionInfo;

/**
 * In-memory test double for {@see AgentClientLauncher}.
 *
 * Captures arguments passed to the listSessions/buildLaunchCommand hooks so test cases
 * can assert that the framework forwards the right worktree/role and never invokes a
 * real CLI binary (claude/codex/opencode/gemini) during the test run.
 */
final class FakeAgentClientLauncher implements AgentClientLauncher
{
    public ?string $lastListWorktree = null;

    public ?string $lastPreparedWorktree = null;

    public ?string $lastLaunchedWorktree = null;

    /** @var list<SessionInfo> */
    private array $sessions;

    private AgentClient $clientEnum;

    private bool $available;

    /**
     * @param list<SessionInfo> $sessions Sessions returned by listSessions()
     * @param bool $available Value returned by isAvailable()
     */
    public function __construct(AgentClient $client, array $sessions = [], bool $available = true)
    {
        $this->clientEnum = $client;
        $this->sessions = $sessions;
        $this->available = $available;
    }

    public function client(): AgentClient
    {
        return $this->clientEnum;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function prepareWorktree(string $worktree, string $contextFilePath): void
    {
        $this->lastPreparedWorktree = $worktree;
    }

    /**
     * @param array<string, string> $baseEnv
     * @return array<string, string>
     */
    public function buildEnvironment(array $baseEnv, string $contextFilePath): array
    {
        return $baseEnv;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public function buildLaunchCommand(
        string $worktree,
        string $contextFilePath,
        AgentRole $role,
        ?string $resumeSessionId = null,
        bool $continueLast = false,
    ): array {
        $this->lastLaunchedWorktree = $worktree;
        return ['/bin/true', []];
    }

    public function captureCurrentSessionId(string $worktree): ?string
    {
        return null;
    }

    /**
     * @return list<SessionInfo>
     */
    public function listSessions(string $worktree): array
    {
        $this->lastListWorktree = $worktree;
        return $this->sessions;
    }
}
