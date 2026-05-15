<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessResult;
use SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessRunner;

/**
 * In-memory InteractiveProcessRunner used by command tests.
 *
 * Records the last `run()` call so tests can assert that the runner would have been invoked. By
 * default returns a successful exit code with a fake client PID; failing branches in the command
 * under test must throw before run() is reached.
 */
final class FakeInteractiveProcessRunner implements InteractiveProcessRunner
{
    /** @var array{bin: string, args: list<string>, cwd: string, env: array<string, string>}|null */
    public ?array $lastCall = null;

    public int $nextExitCode = 0;
    public int $nextClientPid = 12345;

    /**
     * {@inheritdoc}
     */
    public function run(string $bin, array $args, string $cwd, array $env, ?callable $onSpawned = null): InteractiveProcessResult
    {
        $this->lastCall = ['bin' => $bin, 'args' => $args, 'cwd' => $cwd, 'env' => $env];
        if ($onSpawned !== null) {
            $onSpawned($this->nextClientPid);
        }

        return new InteractiveProcessResult($this->nextExitCode, $this->nextClientPid);
    }
}
