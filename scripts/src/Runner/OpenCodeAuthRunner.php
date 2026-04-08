<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\OpenCodeAuthManager;

/**
 * OpenCode auth management script runner.
 *
 * Manages OpenCode provider credentials with WSL as the source of truth and syncs them to Docker.
 */
final class OpenCodeAuthRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Manage OpenCode CLI provider credentials with WSL as the source of truth and sync them to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login to a provider and sync'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--force', 'description' => 'Force overwrite existing auth (sync) or re-authenticate (login)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/opencode-auth.php status',
            'php scripts/opencode-auth.php sync',
            'php scripts/opencode-auth.php sync --force',
            'php scripts/opencode-auth.php login',
            'php scripts/opencode-auth.php login openrouter',
        ];
    }

    /**
     * Dispatches the requested OpenCode auth action to the manager.
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'status';
        $force = in_array('--force', $args, true);
        $provider = null;

        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '--')) {
                continue;
            }

            $provider = $arg;
            break;
        }

        try {
            $manager = new OpenCodeAuthManager($this->app, $this->projectRoot);

            match ($command) {
                'status' => $manager->showStatus(),
                'sync' => $manager->sync($force),
                'login' => $manager->loginAndSync($provider, $force),
                default => throw new \RuntimeException(sprintf('Unknown command "%s". Use status, sync, or login.', $command)),
            };
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }
}
