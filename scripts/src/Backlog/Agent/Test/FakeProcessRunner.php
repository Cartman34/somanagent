<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;

/**
 * In-memory ProcessRunner used by command tests.
 *
 * Returns configurable output per (command, cwd) pair. Falls back to an empty string so
 * callers that never exercise shell execution still compile and run without side effects.
 */
final class FakeProcessRunner implements ProcessRunner
{
    public bool $succeedsResult = true;

    /** @var array<string, string|null> Keyed by "$command|$cwd" or bare "$command" */
    public array $outputMap = [];

    /**
     * {@inheritdoc}
     */
    public function succeeds(string $command): bool
    {
        return $this->succeedsResult;
    }

    /**
     * {@inheritdoc}
     */
    public function output(string $command, string $cwd = ''): ?string
    {
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
