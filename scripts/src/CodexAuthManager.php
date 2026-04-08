<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Manages Codex CLI authentication with WSL as the source of truth and Docker as a synchronized runtime copy.
 */
final class CodexAuthManager
{
    private const CODEX_CONTAINERS = 'php worker';

    private readonly Console $console;
    private readonly AuthSyncSupport $support;
    private readonly string $wslCodexDir;
    private readonly string $sharedRoot;
    private readonly string $sharedCodexDir;

    /**
     * Builds the manager around the WSL source directory and the Docker shared auth copy.
     */
    public function __construct(
        private readonly Application $app,
        string $root,
    ) {
        $this->console = $app->console;
        $this->support = new AuthSyncSupport($app, $this->console);
        $root = rtrim($root, '/');

        $wslHome = rtrim((string) getenv('HOME'), '/');
        $this->wslCodexDir = $wslHome . '/.codex';
        $this->sharedRoot = $root . '/.docker/codex/shared';
        $this->sharedCodexDir = $this->sharedRoot . '/.codex';
    }

    /**
     * Prints WSL auth state, synchronized Docker copy state, and Codex login visibility inside Docker.
     */
    public function showStatus(): void
    {
        $this->console->step('Checking WSL Codex auth files');
        $this->console->info($this->support->describePathState($this->wslCodexDir));

        $this->support->assertSharedDirectoryAccessible($this->sharedRoot);

        $this->console->step('Checking Docker shared auth files');
        $this->console->info($this->support->describePathState($this->sharedCodexDir));

        if (is_dir($this->wslCodexDir) && is_dir($this->sharedCodexDir)) {
            $sameDir = $this->support->hashDirectory($this->wslCodexDir) === $this->support->hashDirectory($this->sharedCodexDir);
            $this->console->info($sameDir ? 'Shared .codex directory is in sync with WSL.' : 'Shared .codex directory differs from WSL.');
        }

        $this->console->step('Checking Codex login status in WSL');
        $this->support->printCommandStatus('codex login status');

        $this->console->step('Checking Codex login status inside Docker');
        $this->support->printCommandStatus('docker compose run --rm --no-deps php sh -lc \'HOME=/codex-home codex login status\'');
    }

    /**
     * Synchronizes the WSL Codex auth directory into the Docker shared mount after verifying account-plan login.
     */
    public function sync(bool $force = false): void
    {
        $this->assertWslAuthExists();
        $this->assertChatGptLogin();

        if (!$force) {
            $this->support->confirmOverwrite(
                'This will overwrite the Docker Codex auth copy from your WSL auth state.',
            );
        }

        $this->support->assertSharedDirectoryAccessible($this->sharedRoot);

        $this->console->step('Syncing WSL Codex auth to Docker shared directory');
        $this->clearDockerSharedCopy();
        $this->support->ensureDirectory($this->sharedRoot);
        $this->support->ensureDirectory($this->sharedCodexDir);
        $this->support->clearDirectoryContents($this->sharedCodexDir);
        $this->support->copyDirectory($this->wslCodexDir, $this->sharedCodexDir);
        $this->support->openSharedReadPermissions($this->sharedRoot);
        $this->recreateCodexContainers();
        $this->console->ok('Docker Codex auth copy updated from WSL.');
    }

    /**
     * Performs Codex login in WSL, then synchronizes the resulting ChatGPT-based auth state into Docker.
     */
    public function loginAndSync(bool $force = false): void
    {
        $this->console->step('Running Codex login in WSL');
        $this->support->runRequiredCommand('codex login', 'Codex login failed.');

        $this->sync($force ?: true);
    }

    /**
     * Ensures the WSL Codex auth directory exists before attempting a sync.
     */
    private function assertWslAuthExists(): void
    {
        if (!is_dir($this->wslCodexDir)) {
            throw new \RuntimeException(sprintf('WSL Codex auth directory not found: %s', $this->wslCodexDir));
        }
    }

    /**
     * Refuses to sync an API-key login because the CLI must use ChatGPT plan limits rather than API credits.
     */
    private function assertChatGptLogin(): void
    {
        exec('codex login status 2>&1', $output, $exitCode);
        $status = strtolower(implode("\n", $output));

        if ($exitCode !== 0 || !str_contains($status, 'logged in using chatgpt')) {
            throw new \RuntimeException('Codex CLI must be logged in with ChatGPT before sync. If you used an API key, run "codex logout" and then "codex login".');
        }
    }

    /**
     * Clears the shared Docker auth copy through an ephemeral container so host cleanup is not blocked by container-owned files.
     */
    private function clearDockerSharedCopy(): void
    {
        $this->console->info('Resetting Docker shared Codex auth copy before sync.');
        $this->support->runRequiredCommand(
            $this->buildEphemeralPhpShellCommand(
                'if [ -d /codex-home/.codex ]; then find /codex-home/.codex -mindepth 1 -delete; fi',
            ),
            'Failed to clear Docker Codex auth copy.',
        );
    }

    /**
     * Recreates the PHP and worker containers so they read the refreshed Codex auth mount.
     */
    private function recreateCodexContainers(): void
    {
        $this->console->info('Recreating PHP and worker containers to refresh Codex auth mounts.');
        $this->removeCodexContainers();
        $this->support->runRequiredCommand(
            sprintf('docker compose up -d %s', self::CODEX_CONTAINERS),
            'Failed to recreate Codex-enabled containers.',
        );
    }

    /**
     * Removes the Codex-enabled runtime containers when they already exist.
     */
    private function removeCodexContainers(): void
    {
        $this->app->runCommand(sprintf('docker compose rm -sf %s >/dev/null 2>&1 || true', self::CODEX_CONTAINERS));
    }

    /**
     * Builds a one-shot PHP container command for maintenance tasks on the shared Codex auth mount.
     */
    private function buildEphemeralPhpShellCommand(string $shellCommand): string
    {
        return sprintf(
            'docker compose run --rm --no-deps php sh -lc %s',
            escapeshellarg($shellCommand),
        );
    }
}
