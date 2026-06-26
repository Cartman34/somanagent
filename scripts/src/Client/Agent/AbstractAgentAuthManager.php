<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Agent;

use Sowapps\SoManAgent\Script\Client\Docker\SoManAgentDockerCompose;
use Sowapps\Toolkit\Client\Console\ConsoleClientInterface;
use Sowapps\Toolkit\Client\FilesystemClientInterface;
use Sowapps\Toolkit\Docker\Client\DockerComposeClientInterface;
use Sowapps\Toolkit\Docker\DockerCompose;

/**
 * Shared home for the CLI agent auth managers (Claude / Codex / OpenCode).
 *
 * Holds the bits shared across agents — and the Auth-Agent-specific mechanics — composing the
 * toolkit filesystem and docker-compose clients plus the local console client. Concrete managers
 * provide their per-agent policy (paths, container set, login command, status probes) and orchestrate
 * the sync/collect sequences on top of the shared mechanics declared here.
 *
 * Silent thin model: it performs filesystem/docker/probe operations and fills an
 * {@see AgentAuthStatus} or throws, but never formats progress for the terminal. Confirmation,
 * dispatch and rendering live in the runner.
 *
 * @api Base auth manager; injected as a collaborator of the transient auth runners.
 */
abstract class AbstractAgentAuthManager
{
    /**
     * Permissions opening the shared mount so the www-data PHP-FPM process can read and refresh the
     * auth state at runtime (e.g. OAuth token rotation): 0666 on files, 0777 on directories.
     */
    private const SHARED_FILE_MODE = 0666;
    private const SHARED_DIR_MODE = 0777;

    protected readonly SoManAgentDockerCompose $docker;

    public function __construct(
        protected readonly FilesystemClientInterface $filesystem,
        protected readonly ConsoleClientInterface $console,
        protected readonly DockerComposeClientInterface $dockerClient,
        DockerCompose $docker,
    ) {
        if (!$docker instanceof SoManAgentDockerCompose) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                SoManAgentDockerCompose::class,
                $docker::class,
            ));
        }

        $this->docker = $docker;
    }

    /**
     * Collects the full auth state into a structured snapshot (no rendering).
     */
    abstract public function collectStatus(): AgentAuthStatus;

    /**
     * Synchronizes the WSL auth state into the Docker shared mount and refreshes the consuming containers.
     */
    abstract public function sync(): void;

    /**
     * Performs the agent CLI login in WSL, then synchronizes the resulting state into Docker.
     */
    abstract public function loginAndSync(): void;

    /**
     * Returns the user's WSL home directory without a trailing slash.
     */
    protected function wslHome(): string
    {
        return rtrim((string) getenv('HOME'), '/');
    }

    /**
     * Returns the project root used to resolve the Docker shared-copy paths.
     */
    protected function projectRoot(): string
    {
        return rtrim($this->docker->main->projectDirectory ?? '', '/');
    }

    /**
     * Builds the structured state of a path (type + size + mtime), without any formatting.
     *
     * Uses the non-opening {@see FilesystemClientInterface::getInfo()} so it works on directories too.
     *
     * @return array{path: string, type: string, size: int, mtime: int}
     */
    protected function describePathState(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return ['path' => $path, 'type' => AgentAuthStatus::TYPE_MISSING, 'size' => 0, 'mtime' => 0];
        }

        $info = $this->filesystem->getInfo($path);
        $isDir = $this->filesystem->isDirectory($path);

        return [
            'path' => $path,
            'type' => $isDir ? AgentAuthStatus::TYPE_DIR : AgentAuthStatus::TYPE_FILE,
            'size' => $isDir ? 0 : ($info->getSize() ?: 0),
            'mtime' => $info->getMTime() ?: 0,
        ];
    }

    /**
     * Runs an auth-status probe command locally and captures its exit code and combined output.
     *
     * Status probes never abort the report — the caller stores the captured result in the snapshot.
     *
     * @return array{0: int, 1: string}
     */
    protected function captureCommandStatus(string $command): array
    {
        // captureWithExitCode() captures combined output WITHOUT streaming it, so the runner stays the
        // single place that renders the probe output (the legacy behaviour). run()/mustRun() would
        // stream to the terminal here and cause a double print once the runner re-emits the capture.
        return $this->console->captureWithExitCode($command);
    }

    /**
     * Throws a descriptive error when any entry under the shared mount is not accessible by the current user.
     *
     * This happens when Docker created the mount as root before the host-side scripts had a chance to
     * create it themselves (e.g. a fresh clone before setup.php ran). The message carries the exact fix.
     */
    protected function assertSharedDirectoryAccessible(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $problematic = [];

        if (!is_readable($path) || !is_writable($path)) {
            $problematic[] = $path;
        }

        if ($problematic === []) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $item) {
                    if (!is_readable($item->getPathname())) {
                        $problematic[] = $item->getPathname();
                    }
                }
            } catch (\Exception) {
                // The directory itself was unreadable — already captured above.
            }
        }

        if ($problematic === []) {
            return;
        }

        $sample = array_slice($problematic, 0, 3);

        throw new \RuntimeException(sprintf(
            "%d path(s) in %s are not accessible by the current user.\n" .
            "  This happens when Docker creates mount directories as root before setup.php runs.\n\n" .
            "  Affected path(s):\n    %s\n\n" .
            "  Fix:\n    sudo chown -R \$(whoami): %s\n\n" .
            "  Then re-run this command.",
            count($problematic),
            $path,
            implode("\n    ", $sample),
            $path,
        ));
    }

    /**
     * Opens the shared mount permissions so the PHP-FPM process can read and refresh the auth state.
     */
    protected function openSharedReadPermissions(string $path): void
    {
        $this->filesystem->setPermissions($path, self::SHARED_FILE_MODE, self::SHARED_DIR_MODE, true);
    }

    /**
     * Recreates the auth-consuming containers so they read the refreshed auth files from the shared mount.
     *
     * @param list<\Sowapps\Toolkit\Docker\DockerContainer> $containers
     */
    protected function recreateContainers(array $containers): void
    {
        foreach ($containers as $container) {
            $this->dockerClient->remove($container);
        }

        foreach ($containers as $container) {
            $this->dockerClient->recreate($container);
        }
    }
}
