<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;

/**
 * In-memory SessionDriverInterface for command tests.
 *
 * Records launch/resume/stop calls so tests can assert invocations.
 * Liveness is controlled per agent code via setAlive(). By default
 * sessions are dead; call setAlive($code, true) to simulate a running one.
 */
final class FakeSessionDriver implements SessionDriverInterface
{
    /** @var array<string, bool> Keyed by agent code */
    private array $aliveByCode = [];

    /** @var array<string, bool> Codes that sessionExists() should return true for */
    private array $existingByCode = [];

    public bool $dependencyCheckPasses = true;

    public int $nextExitCode = 0;
    public int $nextClientPid = 12345;
    public ?string $nextTmuxSession = null;

    /** @var array{agentCode: string, bin: string, args: list<string>, cwd: string}|null */
    public ?array $lastLaunchCall = null;

    /** @var array{agentCode: string, bin: string, args: list<string>, cwd: string}|null */
    public ?array $lastResumeCall = null;

    /** @var AgentSession|null */
    public ?AgentSession $lastStoppedSession = null;

    /**
     * Controls whether isAlive() returns true for the given agent code.
     */
    public function setAlive(string $code, bool $alive): void
    {
        $this->aliveByCode[$code] = $alive;
    }

    /**
     * Controls whether sessionExists() returns true for the given agent code.
     */
    public function setExists(string $code, bool $exists): void
    {
        $this->existingByCode[$code] = $exists;
    }

    /**
     * {@inheritdoc}
     */
    public function checkDependencies(): void
    {
        if (!$this->dependencyCheckPasses) {
            throw new \RuntimeException('Fake dependency check failed.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sessionExists(string $agentCode): bool
    {
        return $this->existingByCode[$agentCode] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function launch(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $this->lastLaunchCall = ['agentCode' => $agentCode, 'bin' => $bin, 'args' => $args, 'cwd' => $cwd];
        $onSpawned($this->nextClientPid, $this->nextTmuxSession);

        return $this->nextExitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $this->lastResumeCall = ['agentCode' => $agentCode, 'bin' => $bin, 'args' => $args, 'cwd' => $cwd];
        $onSpawned($this->nextClientPid, $this->nextTmuxSession);

        return $this->nextExitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(AgentSession $session): void
    {
        $this->lastStoppedSession = $session;
    }

    /**
     * {@inheritdoc}
     */
    public function isAlive(AgentSession $session): bool
    {
        return $this->aliveByCode[$session->code] ?? false;
    }
}
