<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;

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

    /** @var array<string, bool> Keyed by agent code */
    private array $attachedByCode = [];

    /** @var array<string, bool> Codes that sessionExists() should return true for */
    private array $existingByCode = [];

    /** @var list<string> Codes returned by listLiveSessions() */
    private array $liveSessionCodes = [];

    /** @var list<string> Agent codes passed to kill(), in invocation order */
    public array $killedCodes = [];

    /**
     * Prompts recorded by injectPrompt(), keyed sequentially.
     *
     * @var list<array{session: AgentSession, text: string}>
     */
    public array $injectedPrompts = [];

    /**
     * Return value for injectPrompt(). Default true (injection succeeds).
     */
    public bool $injectPromptResult = true;

    /**
     * Whether the fake driver allows resume when isAlive() returns true.
     */
    private bool $allowsResumeWhileAlive = false;

    private bool $dependencyCheckPasses = true;

    private int $nextExitCode = 0;
    /** @var list<int> */
    private array $exitCodeQueue = [];
    private int $nextClientPid = 12345;

    /** @var array{agentCode: string, bin: string, args: list<string>, cwd: string}|null */
    public ?array $lastLaunchCall = null;

    /** @var array{agentCode: string, bin: string, args: list<string>, cwd: string}|null */
    public ?array $lastResumeCall = null;

    /** @var AgentSession|null */
    public ?AgentSession $lastStoppedSession = null;

    /**
     * Optional callback invoked at the start of launch(), before returning.
     * Used by tests to simulate side effects that happen during a real session
     * (e.g. worktree directory deletion by a concurrent worktree-clean).
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $onLaunchHook = null;

    /**
     * @param list<int> $exitCodes
     */
    public function setExitCodeQueue(array $exitCodes): void
    {
        $this->exitCodeQueue = $exitCodes;
    }

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
     * Controls whether isAttached() returns true for the given agent code.
     */
    public function setAttached(string $code, bool $attached): void
    {
        $this->attachedByCode[$code] = $attached;
    }

    /**
     * Controls whether resume is allowed while isAlive() reports true.
     */
    public function setAllowsResumeWhileAlive(bool $allows): void
    {
        $this->allowsResumeWhileAlive = $allows;
    }

    /**
     * Sets the list of codes returned by listLiveSessions().
     *
     * @param list<string> $codes
     */
    public function setLiveSessions(array $codes): void
    {
        $this->liveSessionCodes = $codes;
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
    public function allowsResumeWhileAlive(): bool
    {
        return $this->allowsResumeWhileAlive;
    }

    /**
     * {@inheritdoc}
     */
    public function launch(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $this->lastLaunchCall = ['agentCode' => $agentCode, 'bin' => $bin, 'args' => $args, 'cwd' => $cwd];
        $onSpawned($this->nextClientPid, null);
        if ($this->onLaunchHook !== null) {
            ($this->onLaunchHook)();
        }

        return array_shift($this->exitCodeQueue) ?? $this->nextExitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $this->lastResumeCall = ['agentCode' => $agentCode, 'bin' => $bin, 'args' => $args, 'cwd' => $cwd];
        $onSpawned($this->nextClientPid, null);

        return array_shift($this->exitCodeQueue) ?? $this->nextExitCode;
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

    /**
     * {@inheritdoc}
     */
    public function isAttached(AgentSession $session): bool
    {
        return $this->attachedByCode[$session->code] ?? false;
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string>
     */
    public function listLiveSessions(): array
    {
        return $this->liveSessionCodes;
    }

    /**
     * {@inheritdoc}
     */
    public function kill(string $agentCode): void
    {
        $this->killedCodes[] = $agentCode;
        $this->existingByCode[$agentCode] = false;
        $this->aliveByCode[$agentCode] = false;
    }

    /**
     * {@inheritdoc}
     */
    public function driverName(): string
    {
        return 'fake';
    }

    /**
     * {@inheritdoc}
     *
     * Records the call and returns injectPromptResult.
     */
    public function injectPrompt(AgentSession $session, string $text): bool
    {
        $this->injectedPrompts[] = ['session' => $session, 'text' => $text];

        return $this->injectPromptResult;
    }
}
