<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Agent\Claude;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\AgentAuthStatus;

/**
 * Manages Claude CLI authentication with WSL as the source of truth and Docker as a synchronized copy.
 *
 * Carries the Claude-specific policy (the `.claude` directory + `.claude.json` file, the in-container
 * `/claude-home` paths, the login command and the auth-consuming containers) and orchestrates the
 * status/sync sequences on top of the shared mechanics provided by {@see AbstractAgentAuthManager}.
 *
 * @api Injected as a collaborator of the transient {@see \Sowapps\SoManAgent\Script\Runner\ClaudeAuthRunner}.
 */
final class ClaudeAuthManager extends AbstractAgentAuthManager
{
    /**
     * In-container path of the shared Claude home directory, cleared before each sync.
     */
    private const DOCKER_CLAUDE_DIR = '/claude-home/.claude';

    /**
     * Returns the WSL `.claude` directory (source of truth for the auth state).
     */
    private function wslClaudeDir(): string
    {
        return $this->wslHome() . '/.claude';
    }

    /**
     * Returns the WSL `.claude.json` file (source of truth for the auth config).
     */
    private function wslClaudeJson(): string
    {
        return $this->wslHome() . '/.claude.json';
    }

    /**
     * Returns the root of the Docker-side shared Claude auth copy.
     */
    private function sharedRoot(): string
    {
        return $this->projectRoot() . '/.docker/claude/shared';
    }

    private function sharedClaudeDir(): string
    {
        return $this->sharedRoot() . '/.claude';
    }

    private function sharedClaudeJson(): string
    {
        return $this->sharedRoot() . '/.claude.json';
    }

    public function collectStatus(): AgentAuthStatus
    {
        $status = new AgentAuthStatus();

        $status->addPathGroup('Checking WSL Claude auth files', [
            $this->describePathState($this->wslClaudeDir()),
            $this->describePathState($this->wslClaudeJson()),
        ]);

        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $status->addPathGroup('Checking Docker shared auth files', [
            $this->describePathState($this->sharedClaudeDir()),
            $this->describePathState($this->sharedClaudeJson()),
        ]);

        if ($this->filesystem->isDirectory($this->wslClaudeDir()) && $this->filesystem->isDirectory($this->sharedClaudeDir())) {
            $status->addComparison(
                'Shared .claude directory',
                $this->filesystem->hashDirectory($this->wslClaudeDir()) === $this->filesystem->hashDirectory($this->sharedClaudeDir()),
            );
        }

        if ($this->filesystem->isFile($this->wslClaudeJson()) && $this->filesystem->isFile($this->sharedClaudeJson())) {
            $status->addComparison(
                'Shared .claude.json file',
                $this->filesystem->hashFile($this->wslClaudeJson()) === $this->filesystem->hashFile($this->sharedClaudeJson()),
            );
        }

        [$wslCode, $wslOutput] = $this->captureCommandStatus('claude auth status');
        $status->addCommandOutput('Checking Claude auth status in WSL', $wslCode, $wslOutput);

        [$dockerCode, $dockerOutput] = $this->captureCommandStatus(
            'docker compose run --rm --no-deps php sh -lc \'HOME=/claude-home claude auth status\'',
        );
        $status->addCommandOutput('Checking Claude auth status inside Docker', $dockerCode, $dockerOutput);

        return $status;
    }

    public function sync(): void
    {
        $this->assertWslAuthExists();
        $this->assertSharedDirectoryAccessible($this->sharedRoot());

        $this->clearDockerSharedCopy();
        $this->filesystem->makeDirectory($this->sharedRoot());
        $this->filesystem->makeDirectory($this->sharedClaudeDir());
        $this->filesystem->clearDirectory($this->sharedClaudeDir());
        $this->filesystem->copy($this->wslClaudeDir(), $this->sharedClaudeDir());
        $this->filesystem->copy($this->wslClaudeJson(), $this->sharedClaudeJson());
        $this->openSharedReadPermissions($this->sharedRoot());
        $this->recreateContainers([$this->docker->main->php, $this->docker->main->worker]);
    }

    public function loginAndSync(): void
    {
        // Interactive OAuth login needs a real TTY: attachCommand() runs attached (passthru), unlike the
        // capture-only run()/mustRun(). It returns the exit code without throwing, so we fail loud here.
        $exitCode = $this->console->attachCommand('claude auth login');
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Claude login failed (exit code %d).', $exitCode));
        }

        $this->sync();
    }

    /**
     * Ensures the WSL Claude auth files exist before attempting a sync.
     */
    public function assertWslAuthExists(): void
    {
        if (!$this->filesystem->isDirectory($this->wslClaudeDir())) {
            throw new \RuntimeException(sprintf('WSL Claude auth directory not found: %s', $this->wslClaudeDir()));
        }

        if (!$this->filesystem->isFile($this->wslClaudeJson())) {
            throw new \RuntimeException(sprintf('WSL Claude auth file not found: %s', $this->wslClaudeJson()));
        }
    }

    /**
     * Clears the shared Docker auth copy through an ephemeral container so host-side cleanup is not
     * blocked by container-owned files. The in-container path is Claude policy; the run is mechanics.
     */
    private function clearDockerSharedCopy(): void
    {
        $this->dockerClient->runDisposable($this->docker->main->php, [
            'sh', '-lc',
            sprintf('if [ -d %1$s ]; then find %1$s -mindepth 1 -delete; fi', self::DOCKER_CLAUDE_DIR),
        ], noDeps: true);
    }
}
