<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\ClaudeAuthManager;

/**
 * Claude auth management script runner.
 *
 * Manages Claude CLI auth with WSL as the source of truth and syncs it to Docker.
 */
final class ClaudeAuthRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Manage Claude CLI auth with WSL as the source of truth and sync it to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login and sync'],
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
            'php scripts/claude-auth.php status',
            'php scripts/claude-auth.php sync',
            'php scripts/claude-auth.php sync --force',
            'php scripts/claude-auth.php login',
            'php scripts/claude-auth.php login --force',
        ];
    }

    public function run(array $args): int
    {
        $command = $args[0] ?? 'status';
        $force = in_array('--force', $args, true);

        try {
            $manager = new ClaudeAuthManager($this->app, $this->projectRoot);

            match ($command) {
                'status' => $manager->showStatus(),
                'sync' => $manager->sync($force),
                'login' => $manager->loginAndSync($force),
                default => throw new \RuntimeException(sprintf('Unknown command "%s". Use status, sync, or login.', $command)),
            };
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }
}
