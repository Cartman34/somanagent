<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Agent\OpenCode;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\AgentAuthStatus;

/**
 * Manages OpenCode CLI provider credentials with WSL as the source of truth and Docker as a copy.
 *
 * Carries the OpenCode-specific policy (the `.local/share|state/opencode` tree, the `auth.json` file,
 * the provider-aware login command and the auth-consuming containers) and orchestrates the
 * status/sync sequences on top of the shared mechanics provided by {@see AbstractAgentAuthManager}.
 *
 * @api Injected as a collaborator of the transient {@see \Sowapps\SoManAgent\Script\Runner\OpenCodeAuthRunner}.
 */
final class OpenCodeAuthManager extends AbstractAgentAuthManager
{
    /**
     * The provider passed to `opencode auth login`, set by the runner before login.
     */
    private ?string $provider = null;

    private function wslAuthFile(): string
    {
        return $this->wslHome() . '/.local/share/opencode/auth.json';
    }

    private function sharedRoot(): string
    {
        return $this->projectRoot() . '/.docker/opencode/shared';
    }

    private function sharedLocalDir(): string
    {
        return $this->sharedRoot() . '/.local';
    }

    private function sharedAuthFile(): string
    {
        return $this->sharedLocalDir() . '/share/opencode/auth.json';
    }

    /**
     * Sets the provider used by the next {@see loginAndSync()} call (e.g. "openrouter").
     */
    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function collectStatus(): AgentAuthStatus
    {
        $status = new AgentAuthStatus();

        $status->addPathGroup('Checking WSL OpenCode auth files', [
            $this->describePathState($this->wslAuthFile()),
        ]);

        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $status->addPathGroup('Checking Docker shared auth files', [
            $this->describePathState($this->sharedAuthFile()),
        ]);

        if ($this->filesystem->isFile($this->wslAuthFile()) && $this->filesystem->isFile($this->sharedAuthFile())) {
            $status->addComparison(
                'Shared OpenCode auth file',
                $this->filesystem->hashFile($this->wslAuthFile()) === $this->filesystem->hashFile($this->sharedAuthFile()),
            );
        }

        $status->addNote('OpenCode CLI currently relies on provider credentials. No subscription-based account usage mode has been detected.');

        [$wslCode, $wslOutput] = $this->captureCommandStatus('opencode auth list');
        $status->addCommandOutput('Checking OpenCode auth status in WSL', $wslCode, $wslOutput);

        [$dockerCode, $dockerOutput] = $this->captureCommandStatus(
            'docker compose run --rm --no-deps php sh -lc \'HOME=/opencode-home XDG_STATE_HOME=/opencode-home/.local/state opencode auth list\'',
        );
        $status->addCommandOutput('Checking OpenCode auth status inside Docker', $dockerCode, $dockerOutput);

        return $status;
    }

    public function sync(): void
    {
        $this->assertWslAuthExists();
        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $this->filesystem->makeDirectory($this->sharedRoot());
        $this->filesystem->makeDirectory($this->sharedLocalDir() . '/share/opencode');
        $this->filesystem->makeDirectory($this->sharedLocalDir() . '/state/opencode');
        $this->filesystem->copy($this->wslAuthFile(), $this->sharedAuthFile());
        $this->openSharedReadPermissions($this->sharedRoot());
        $this->recreateContainers([$this->docker->main->php, $this->docker->main->worker]);
    }

    public function loginAndSync(): void
    {
        $command = 'opencode auth login';
        if (is_string($this->provider) && $this->provider !== '') {
            $command .= ' ' . escapeshellarg($this->provider);
        }

        // Interactive provider login needs a real TTY: attachCommand() runs attached (passthru), unlike the
        // capture-only run()/mustRun(). It returns the exit code without throwing, so we fail loud here.
        $exitCode = $this->console->attachCommand($command);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('OpenCode login failed (exit code %d).', $exitCode));
        }

        $this->sync();
    }

    /**
     * Ensures the WSL OpenCode auth file exists before attempting a sync.
     */
    public function assertWslAuthExists(): void
    {
        if (!$this->filesystem->isFile($this->wslAuthFile())) {
            throw new \RuntimeException(sprintf('WSL OpenCode auth file not found: %s', $this->wslAuthFile()));
        }
    }
}
