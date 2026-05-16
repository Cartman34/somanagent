<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Installer;

use SoManAgent\Script\Application;
use SoManAgent\Script\Console;
use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\SideEffects;
use SoManAgent\Script\DevEnv\PlannedDep;

/**
 * Installs Docker Engine and Compose plugin via the official docker.com apt repository.
 *
 * Handles the `docker` section of the lockfile. Adds the Docker GPG key and apt source
 * list as side effects, tracked in the returned lock entries so uninstall can clean them.
 *
 * Side effects created (tracked in lockfile):
 *   apt_repo : /etc/apt/sources.list.d/docker.list
 *   gpg_key  : /etc/apt/keyrings/docker.gpg
 */
final class DockerInstaller implements InstallerInterface
{
    private const GPG_PATH = '/etc/apt/keyrings/docker.gpg';
    private const REPO_PATH = '/etc/apt/sources.list.d/docker.list';

    /**
     * @param Application $app     Command runner
     * @param Console     $console Output helper
     */
    public function __construct(
        private readonly Application $app,
        private readonly Console $console,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function supports(PlannedDep $dep): bool
    {
        return $dep->entry->section === 'docker' && $dep->entry->installer === 'apt';
    }

    /**
     * {@inheritdoc}
     *
     * @param list<PlannedDep> $deps
     * @return list<string>
     */
    public function getSimulatedCommands(array $deps): array
    {
        $hasActions = array_filter($deps, fn(PlannedDep $d): bool => $d->action !== PlannedDep::ACTION_SKIP) !== [];
        if (!$hasActions) {
            return [];
        }

        $commands = [];

        if (!is_file(self::REPO_PATH)) {
            $commands[] = 'sudo apt-get install -y ca-certificates curl';
            $commands[] = 'sudo install -m 0755 -d /etc/apt/keyrings';
            $commands[] = 'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o ' . self::GPG_PATH;
            $commands[] = 'sudo chmod a+r ' . self::GPG_PATH;
            $commands[] = 'echo "deb [arch=$(dpkg --print-architecture) signed-by=' . self::GPG_PATH . '] '
                . 'https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" '
                . '| sudo tee ' . self::REPO_PATH . ' > /dev/null';
            $commands[] = 'sudo apt-get update -qq';
        }

        foreach ($deps as $dep) {
            if ($dep->action === PlannedDep::ACTION_SKIP) {
                continue;
            }
            $commands[] = sprintf(
                'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y %s',
                escapeshellarg($dep->entry->package . '=' . $dep->entry->version),
            );
        }

        return $commands;
    }

    /**
     * {@inheritdoc}
     *
     * @param list<PlannedDep> $deps
     * @return list<LockEntry>
     */
    public function install(array $deps): array
    {
        $hasActions = array_filter($deps, fn(PlannedDep $d): bool => $d->action !== PlannedDep::ACTION_SKIP) !== [];
        if (!$hasActions) {
            return [];
        }

        $now = new \DateTimeImmutable();
        $repoAdded = false;

        if (!is_file(self::REPO_PATH)) {
            $this->setupDockerRepo();
            $repoAdded = true;
        }

        $updatedEntries = [];
        foreach ($deps as $dep) {
            if ($dep->action === PlannedDep::ACTION_SKIP) {
                continue;
            }

            $this->console->info(sprintf('Installing %s (%s)', $dep->entry->key, $dep->entry->version));

            $packageSpec = escapeshellarg($dep->entry->package . '=' . $dep->entry->version);
            $code = $this->app->runCommand(
                'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y ' . $packageSpec,
            );

            if ($code !== 0) {
                throw new \RuntimeException(sprintf(
                    'Failed to install %s (exit %d)',
                    $dep->entry->key,
                    $code,
                ));
            }

            $wasPreExisting = $dep->installedVersion !== null;
            $sideEffects = $repoAdded
                ? new SideEffects(aptRepo: self::REPO_PATH, gpgKey: self::GPG_PATH)
                : $dep->entry->sideEffects;

            $updatedEntries[] = $dep->entry->withApplied(
                wasPreExisting: $wasPreExisting,
                previousVersion: $dep->installedVersion,
                sideEffects: $sideEffects,
                appliedAt: $now,
            );
        }

        return $updatedEntries;
    }

    /**
     * Adds the Docker GPG key and apt source list, then runs apt-get update.
     *
     * @throws \RuntimeException on any setup command failure
     */
    private function setupDockerRepo(): void
    {
        $this->console->info('Setting up Docker apt repository...');

        $steps = [
            'sudo apt-get install -y ca-certificates curl',
            'sudo install -m 0755 -d /etc/apt/keyrings',
            'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o ' . self::GPG_PATH,
            'sudo chmod a+r ' . self::GPG_PATH,
            'bash -c \'echo "deb [arch=$(dpkg --print-architecture) signed-by=' . self::GPG_PATH . '] '
                . 'https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" '
                . '| sudo tee ' . self::REPO_PATH . ' > /dev/null\'',
            'sudo apt-get update -qq',
        ];

        foreach ($steps as $cmd) {
            $code = $this->app->runCommand($cmd);
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Docker repo setup failed (exit %d): %s', $code, $cmd));
            }
        }
    }
}
