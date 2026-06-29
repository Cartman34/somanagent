<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Agent\Codex;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\AgentAuthStatus;

/**
 * Manages Codex CLI authentication with WSL as the source of truth and Docker as a synchronized copy.
 *
 * Carries the Codex-specific policy (the `.codex` directory, the in-container `/codex-home` path, the
 * ChatGPT-plan login guard and the auth-consuming containers) and orchestrates the status/sync
 * sequences on top of the shared mechanics provided by {@see AbstractAgentAuthManager}.
 *
 * @api Injected as a collaborator of the transient {@see \Sowapps\SoManAgent\Script\Runner\CodexAuthRunner}.
 */
final class CodexAuthManager extends AbstractAgentAuthManager
{
    /**
     * In-container path of the shared Codex home directory, cleared before each sync.
     */
    private const DOCKER_CODEX_DIR = '/codex-home/.codex';

    private function wslCodexDir(): string
    {
        return $this->wslHome() . '/.codex';
    }

    private function sharedRoot(): string
    {
        return $this->projectRoot() . '/.docker/codex/shared';
    }

    private function sharedCodexDir(): string
    {
        return $this->sharedRoot() . '/.codex';
    }

    public function collectStatus(): AgentAuthStatus
    {
        $status = new AgentAuthStatus();

        $status->addPathGroup('Checking WSL Codex auth files', [
            $this->describePathState($this->wslCodexDir()),
        ]);

        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $status->addPathGroup('Checking Docker shared auth files', [
            $this->describePathState($this->sharedCodexDir()),
        ]);

        if ($this->filesystem->isDirectory($this->wslCodexDir()) && $this->filesystem->isDirectory($this->sharedCodexDir())) {
            $status->addComparison(
                'Shared .codex directory',
                $this->filesystem->hashDirectory($this->wslCodexDir()) === $this->filesystem->hashDirectory($this->sharedCodexDir()),
            );
        }

        [$wslCode, $wslOutput] = $this->captureCommandStatus('codex login status');
        $status->addCommandOutput('Checking Codex login status in WSL', $wslCode, $wslOutput);

        [$dockerCode, $dockerOutput] = $this->captureCommandStatus(
            'docker compose run --rm --no-deps php sh -lc \'HOME=/codex-home codex login status\'',
        );
        $status->addCommandOutput('Checking Codex login status inside Docker', $dockerCode, $dockerOutput);

        return $status;
    }

    public function sync(): void
    {
        $this->assertWslAuthExists();
        $this->assertChatGptLogin();
        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $this->clearDockerSharedCopy();
        $this->filesystem->makeDirectory($this->sharedRoot());
        $this->filesystem->makeDirectory($this->sharedCodexDir());
        $this->filesystem->clearDirectory($this->sharedCodexDir());
        $this->filesystem->copy($this->wslCodexDir(), $this->sharedCodexDir());
        $this->openSharedReadPermissions($this->sharedRoot());
        $this->recreateContainers([$this->docker->main->php, $this->docker->main->worker]);
    }

    public function loginAndSync(): void
    {
        // Interactive OAuth login needs a real TTY: attachCommand() runs attached (passthru), unlike the
        // capture-only run()/mustRun(). It returns the exit code without throwing, so we fail loud here.
        $exitCode = $this->console->attachCommand('codex login');
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Codex login failed (exit code %d).', $exitCode));
        }

        $this->sync();
    }

    /**
     * Ensures the WSL Codex auth directory exists before attempting a sync.
     */
    public function assertWslAuthExists(): void
    {
        if (!$this->filesystem->isDirectory($this->wslCodexDir())) {
            throw new \RuntimeException(sprintf('WSL Codex auth directory not found: %s', $this->wslCodexDir()));
        }
    }

    /**
     * Refuses to sync an API-key login because the CLI must use ChatGPT plan limits, not API credits.
     */
    public function assertChatGptLogin(): void
    {
        [$exitCode, $output] = $this->captureCommandStatus('codex login status');
        $status = strtolower($output);

        if ($exitCode !== 0 || !str_contains($status, 'logged in using chatgpt')) {
            throw new \RuntimeException('Codex CLI must be logged in with ChatGPT before sync. If you used an API key, run "codex logout" and then "codex login".');
        }
    }

    /**
     * Clears the shared Docker auth copy through an ephemeral container so host-side cleanup is not
     * blocked by container-owned files. The in-container path is Codex policy; the run is mechanics.
     */
    private function clearDockerSharedCopy(): void
    {
        $this->dockerClient->runDisposable($this->docker->main->php, [
            'sh', '-lc',
            sprintf('if [ -d %1$s ]; then find %1$s -mindepth 1 -delete; fi', self::DOCKER_CODEX_DIR),
        ], noDeps: true);
    }
}
