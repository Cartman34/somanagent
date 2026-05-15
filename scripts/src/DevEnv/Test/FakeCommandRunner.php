<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\DevEnv\CommandRunnerInterface;

/**
 * Fake command runner for unit tests.
 *
 * Returns predefined output strings. Matches by exact command first,
 * then by command prefix. Tracks call count for cache-related assertions.
 */
final class FakeCommandRunner implements CommandRunnerInterface
{
    /**
     * @var array<string, string|null>
     */
    private array $responses = [];

    private int $callCount = 0;

    /**
     * Registers an output for a specific command.
     *
     * Pass null to simulate a command failure (non-zero exit / no output).
     */
    public function setOutput(string $command, ?string $output): void
    {
        $this->responses[$command] = $output;
    }

    /**
     * Returns the number of times output() was called.
     */
    public function getCallCount(): int
    {
        return $this->callCount;
    }

    /**
     * {@inheritdoc}
     */
    public function output(string $command): ?string
    {
        $this->callCount++;

        if (array_key_exists($command, $this->responses)) {
            return $this->responses[$command];
        }

        foreach ($this->responses as $key => $value) {
            if (str_starts_with($command, $key)) {
                return $value;
            }
        }

        return null;
    }
}
