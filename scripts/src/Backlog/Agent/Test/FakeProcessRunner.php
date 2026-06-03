<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;

/**
 * In-memory ProcessRunner used by command tests.
 *
 * Returns configurable output per (command, cwd) pair. Falls back to an empty string so
 * callers that never exercise shell execution still compile and run without side effects.
 */
final class FakeProcessRunner implements ProcessRunner
{
    public bool $succeedsResult = true;

    /**
     * @var list<bool> Per-call result queue for succeeds(); shifts one entry per call, falls back to $succeedsResult when empty
     */
    public array $succeedsQueue = [];

    /** @var array<string, string|null> Keyed by "$command|$cwd" or bare "$command" */
    public array $outputMap = [];

    /** @var list<string> Commands passed to succeeds(), in invocation order */
    public array $succeedsCalls = [];

    /** @var list<string> Commands passed to output(), in invocation order */
    public array $outputCalls = [];

    /**
     * {@inheritdoc}
     */
    public function succeeds(string $command): bool
    {
        $this->succeedsCalls[] = $command;

        if ($this->succeedsQueue !== []) {
            return (bool) array_shift($this->succeedsQueue);
        }

        return $this->succeedsResult;
    }

    /**
     * {@inheritdoc}
     */
    public function output(string $command, string $cwd = ''): ?string
    {
        $this->outputCalls[] = $command;

        $fullKey = $cwd !== '' ? $command . '|' . $cwd : $command;
        if (array_key_exists($fullKey, $this->outputMap)) {
            return $this->outputMap[$fullKey];
        }
        if (array_key_exists($command, $this->outputMap)) {
            return $this->outputMap[$command];
        }

        return '';
    }
}
