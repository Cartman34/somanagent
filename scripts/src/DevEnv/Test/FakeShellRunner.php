<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\ShellRunnerInterface;

/**
 * Fake shell runner for unit tests.
 *
 * Records every command passed to runCommand() and returns a configurable
 * exit code (0 = success by default).
 */
final class FakeShellRunner implements ShellRunnerInterface
{
    /**
     * @var list<string>
     */
    private array $calls = [];

    private int $exitCode;

    public function __construct(int $exitCode = 0)
    {
        $this->exitCode = $exitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function runCommand(string $cmd): int
    {
        $this->calls[] = $cmd;

        return $this->exitCode;
    }

    /**
     * Returns every command string passed to runCommand(), in call order.
     *
     * @return list<string>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }
}
