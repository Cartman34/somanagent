<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Provides default no-op implementations of optional AgentClientLauncher hooks.
 *
 * Concrete launchers extend this class and override only what differs for their CLI.
 */
abstract class AbstractAgentClientLauncher implements AgentClientLauncher
{
    /**
     * Default: no worktree preparation needed.
     */
    public function prepareWorktree(string $worktree, string $contextFilePath): void
    {
        // no-op by default
    }

    /**
     * Default: return base environment unchanged.
     *
     * @param array<string, string> $baseEnv
     * @return array<string, string>
     */
    public function buildEnvironment(array $baseEnv, string $contextFilePath): array
    {
        return $baseEnv;
    }

    /**
     * Default: no flag dependency. Concrete launchers override when their
     * buildLaunchCommand() relies on specific CLI options.
     *
     * @return list<string>
     */
    public function requiredCliFlags(): array
    {
        return [];
    }
}
