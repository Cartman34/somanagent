<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\CodexAuthManager;

/**
 * Codex auth management script runner.
 *
 * Manages Codex CLI auth with WSL as the source of truth and syncs it to Docker.
 */
final class CodexAuthRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Manage Codex CLI auth with WSL as the source of truth and sync it to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login with ChatGPT and sync'],
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
            'php scripts/codex-auth.php status',
            'php scripts/codex-auth.php sync',
            'php scripts/codex-auth.php sync --force',
            'php scripts/codex-auth.php login',
            'php scripts/codex-auth.php login --force',
        ];
    }

    /**
     * Dispatches the requested Codex auth action to the manager.
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'status';
        $force = in_array('--force', $args, true);

        try {
            $manager = new CodexAuthManager($this->app, $this->projectRoot);

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
