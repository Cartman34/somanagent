<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;
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
    /**
     * Last worktree passed to listSessions(), used to assert session lookup scope.
     */
    public ?string $lastListWorktree = null;

    /**
     * Last worktree passed to prepareWorktree(), used to assert preparation scope.
     */
    public ?string $lastPreparedWorktree = null;

    /**
     * Last worktree passed to buildLaunchCommand(), used to assert launch scope.
     */
    public ?string $lastLaunchedWorktree = null;

    /**
     * Resolved model CLI args received by buildLaunchCommand(), used to assert model option propagation.
     *
     * @var list<string>|null
     */
    public ?array $lastResolvedModelCliArgs = null;

    /**
     * Optional exception thrown by prepareWorktree(), used to exercise rollback paths.
     */
    public ?\Throwable $prepareException = null;

    /**
     * Sessions returned by listSessions().
     *
     * @var list<SessionInfo>
     */
    private array $sessions;

    /**
     * Client enum returned by client().
     */
    private AgentClient $clientEnum;

    /**
     * Availability state returned by isAvailable().
     */
    private bool $available;

    /**
     * CLI flags returned by requiredCliFlags().
     *
     * @var list<string>
     */
    private array $cliFlags;

    /**
     * @param list<SessionInfo> $sessions Sessions returned by listSessions()
     * @param bool $available Value returned by isAvailable()
     * @param list<string> $cliFlags Flags returned by requiredCliFlags()
     */
    public function __construct(AgentClient $client, array $sessions = [], bool $available = true, array $cliFlags = [])
    {
        $this->clientEnum = $client;
        $this->sessions = $sessions;
        $this->available = $available;
        $this->cliFlags = $cliFlags;
    }

    /**
     * Returns the client type this launcher handles.
     */
    public function client(): AgentClient
    {
        return $this->clientEnum;
    }

    /**
     * Returns whether the underlying CLI is considered available.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Records the worktree argument; skips actual preparation.
     */
    public function prepareWorktree(string $worktree, string $contextFilePath): void
    {
        $this->lastPreparedWorktree = $worktree;
        if ($this->prepareException !== null) {
            throw $this->prepareException;
        }
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
        ?ResolvedModel $resolvedModel = null,
    ): array {
        $this->lastLaunchedWorktree = $worktree;
        $this->lastResolvedModelCliArgs = $resolvedModel?->cliArgs;

        return ['/bin/true', $resolvedModel !== null ? $resolvedModel->cliArgs : []];
    }

    /**
     * Always returns null — no real client session to capture.
     */
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

    /**
     * @return list<string>
     */
    public function requiredCliFlags(): array
    {
        return $this->cliFlags;
    }
}
