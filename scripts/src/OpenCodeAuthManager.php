<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Manages OpenCode CLI provider credentials with WSL as the source of truth and Docker as a synchronized runtime copy.
 */
final class OpenCodeAuthManager
{
    private const OPENCODE_CONTAINERS = 'php worker';

    private readonly Console $console;
    private readonly AuthSyncSupport $support;
    private readonly string $wslAuthFile;
    private readonly string $sharedRoot;
    private readonly string $sharedLocalDir;
    private readonly string $sharedAuthFile;

    /**
     * Builds the manager around the WSL auth file and the Docker-side shared `.local` tree used by OpenCode.
     */
    public function __construct(
        private readonly Application $app,
        string $root,
    ) {
        $this->console = $app->console;
        $this->support = new AuthSyncSupport($app, $this->console);
        $root = rtrim($root, '/');

        $wslHome = rtrim((string) getenv('HOME'), '/');
        $this->wslAuthFile = $wslHome . '/.local/share/opencode/auth.json';
        $this->sharedRoot = $root . '/.docker/opencode/shared';
        $this->sharedLocalDir = $this->sharedRoot . '/.local';
        $this->sharedAuthFile = $this->sharedLocalDir . '/share/opencode/auth.json';
    }

    /**
     * Prints WSL auth state, synchronized Docker copy state, and OpenCode auth visibility inside Docker.
     */
    public function showStatus(): void
    {
        $this->console->step('Checking WSL OpenCode auth files');
        $this->console->info($this->support->describePathState($this->wslAuthFile));

        $this->support->assertSharedDirectoryAccessible($this->sharedRoot);

        $this->console->step('Checking Docker shared auth files');
        $this->console->info($this->support->describePathState($this->sharedAuthFile));

        if (is_file($this->wslAuthFile) && is_file($this->sharedAuthFile)) {
            $sameFile = $this->support->hashFile($this->wslAuthFile) === $this->support->hashFile($this->sharedAuthFile);
            $this->console->info($sameFile ? 'Shared OpenCode auth file is in sync with WSL.' : 'Shared OpenCode auth file differs from WSL.');
        }

        $this->console->warn('OpenCode CLI currently relies on provider credentials. No subscription-based account usage mode has been detected.');

        $this->console->step('Checking OpenCode auth status in WSL');
        $this->support->printCommandStatus('opencode auth list');

        $this->console->step('Checking OpenCode auth status inside Docker');
        $this->support->printCommandStatus('docker compose run --rm --no-deps php sh -lc \'HOME=/opencode-home XDG_STATE_HOME=/opencode-home/.local/state opencode auth list\'');
    }

    /**
     * Synchronizes the WSL OpenCode auth file into the Docker shared mount and ensures the runtime state directory exists.
     */
    public function sync(bool $force = false): void
    {
        $this->assertWslAuthExists();

        if (!$force) {
            $this->support->confirmOverwrite(
                'This will overwrite the Docker OpenCode auth copy from your WSL auth state.',
            );
        }

        $this->support->assertSharedDirectoryAccessible($this->sharedRoot);

        $this->console->step('Syncing WSL OpenCode auth to Docker shared directory');
        $this->support->ensureDirectory($this->sharedRoot);
        $this->support->ensureDirectory($this->sharedLocalDir . '/share/opencode');
        $this->support->ensureDirectory($this->sharedLocalDir . '/state/opencode');
        $this->support->copyFile($this->wslAuthFile, $this->sharedAuthFile);
        $this->support->openSharedReadPermissions($this->sharedRoot);
        $this->recreateOpenCodeContainers();
        $this->console->ok('Docker OpenCode auth copy updated from WSL.');
    }

    /**
     * Performs OpenCode provider login in WSL, then synchronizes the resulting auth file into Docker.
     */
    public function loginAndSync(?string $provider = null, bool $force = false): void
    {
        $this->console->step('Running OpenCode login in WSL');
        $command = 'opencode auth login';

        if (is_string($provider) && $provider !== '') {
            $command .= ' ' . escapeshellarg($provider);
        }

        $this->support->runRequiredCommand($command, 'OpenCode login failed.');

        $this->sync($force ?: true);
    }

    /**
     * Ensures the WSL OpenCode auth file exists before attempting a sync.
     */
    private function assertWslAuthExists(): void
    {
        if (!is_file($this->wslAuthFile)) {
            throw new \RuntimeException(sprintf('WSL OpenCode auth file not found: %s', $this->wslAuthFile));
        }
    }

    /**
     * Recreates the PHP and worker containers so they read the refreshed OpenCode auth mount.
     */
    private function recreateOpenCodeContainers(): void
    {
        $this->console->info('Recreating PHP and worker containers to refresh OpenCode auth mounts.');
        $this->removeOpenCodeContainers();
        $this->support->runRequiredCommand(
            sprintf('docker compose up -d %s', self::OPENCODE_CONTAINERS),
            'Failed to recreate OpenCode-enabled containers.',
        );
    }

    /**
     * Removes the OpenCode-enabled runtime containers when they already exist.
     */
    private function removeOpenCodeContainers(): void
    {
        $this->app->runCommand(sprintf('docker compose rm -sf %s >/dev/null 2>&1 || true', self::OPENCODE_CONTAINERS));
    }
}
