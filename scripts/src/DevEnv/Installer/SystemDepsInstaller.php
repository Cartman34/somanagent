<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Installer;

use Sowapps\SoManAgent\Script\DevEnv\Model\LockEntry;
use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Sowapps\Toolkit\Console;
use Sowapps\SoManAgent\Script\DevEnv\PlannedDep;
use Sowapps\SoManAgent\Script\DevEnv\Model\SideEffects;

/**
 * Installs host-level system dependencies via apt.
 *
 * Handles the `system` section of the lockfile (PHP, git, tmux, etc.).
 * Supports both the default apt source and PPA sources (ppa:ondrej/php).
 * Side effects (PPA repo additions) are tracked in the returned lock entries.
 */
final class SystemDepsInstaller implements InstallerInterface
{
    /**
     * @param SoManAgentApplication $app Command runner
     * @param Console $console Output helper
     */
    public function __construct(
        private readonly SoManAgentApplication $app,
        private readonly Console $console,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function supports(PlannedDep $dep): bool
    {
        return $dep->entry->section === 'system' && $dep->entry->installer === 'apt';
    }

    /**
     * {@inheritdoc}
     *
     * @param list<PlannedDep> $deps
     * @return list<string>
     */
    public function getSimulatedCommands(array $deps): array
    {
        $commands = [];
        $ppas = $this->collectPpas($deps);

        foreach ($ppas as $ppa) {
            $commands[] = sprintf('sudo add-apt-repository -y %s', escapeshellarg($ppa));
        }

        if ($ppas !== []) {
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
        $ppas = $this->collectPpas($deps);
        $now = new \DateTimeImmutable();

        foreach ($ppas as $ppa) {
            $this->console->info(sprintf('Adding apt repository: %s', $ppa));
            $code = $this->app->runCommand(sprintf('sudo add-apt-repository -y %s', escapeshellarg($ppa)));
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Failed to add apt repository: %s (exit %d)', $ppa, $code));
            }
        }

        if ($ppas !== []) {
            $this->console->info('Updating apt package index...');
            $code = $this->app->runCommand('sudo apt-get update -qq');
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('apt-get update failed (exit %d)', $code));
            }
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

            $ppa = $this->resolveSourcePpa($dep);
            $sideEffects = $ppa !== null ? new SideEffects(aptRepo: $ppa) : null;
            $wasPreExisting = $dep->installedVersion !== null;

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
     * Returns unique PPA sources required by the given deps (non-default sources only).
     *
     * @param list<PlannedDep> $deps
     * @return list<string>
     */
    private function collectPpas(array $deps): array
    {
        $ppas = [];
        foreach ($deps as $dep) {
            if ($dep->action === PlannedDep::ACTION_SKIP) {
                continue;
            }
            $source = $dep->entry->source;
            if ($source !== 'default' && str_starts_with($source, 'ppa:') && !in_array($source, $ppas, true)) {
                $ppas[] = $source;
            }
        }

        return $ppas;
    }

    /**
     * Returns the PPA source for a dep if it uses a non-default source, null otherwise.
     */
    private function resolveSourcePpa(PlannedDep $dep): ?string
    {
        $source = $dep->entry->source;

        return (str_starts_with($source, 'ppa:')) ? $source : null;
    }
}
