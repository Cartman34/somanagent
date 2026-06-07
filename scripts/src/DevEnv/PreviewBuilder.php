<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv;

use Sowapps\SoManAgent\Script\DevEnv\Installer\InstallerInterface;
use Sowapps\Toolkit\Console;

/**
 * Renders an InstallPlan as human-readable output.
 *
 * Separates display concerns from planning and installation logic:
 *  - render()               — preview table (action, dep, version info)
 *  - renderSimulatedCommands() — dry-run command listing per installer
 */
final class PreviewBuilder
{
    /**
     * @param Console $console Output helper
     */
    public function __construct(
        private readonly Console $console,
    ) {
    }

    /**
     * Prints the installation preview table.
     *
     * Lists each dep with its planned action and version details, then prints a summary line.
     */
    public function render(InstallPlan $plan): void
    {
        $this->console->line();
        $this->console->line('  Installation plan:');
        $this->console->line();

        if ($plan->items === []) {
            $this->console->line('    (no dependencies in lockfile)');
            $this->console->line();

            return;
        }

        $counters = [
            PlannedDep::ACTION_INSTALL => 0,
            PlannedDep::ACTION_UPGRADE => 0,
            PlannedDep::ACTION_SKIP    => 0,
            PlannedDep::ACTION_CONFIRM => 0,
            PlannedDep::ACTION_BLOCKED => 0,
        ];

        foreach ($plan->items as $dep) {
            $label = match ($dep->action) {
                PlannedDep::ACTION_SKIP    => 'SKIP   ',
                PlannedDep::ACTION_INSTALL => 'INSTALL',
                PlannedDep::ACTION_UPGRADE => 'UPGRADE',
                PlannedDep::ACTION_CONFIRM => 'CONFIRM',
                PlannedDep::ACTION_BLOCKED => 'BLOCKED',
                default                    => strtoupper($dep->action),
            };

            $versionInfo = match ($dep->action) {
                PlannedDep::ACTION_SKIP    => sprintf('(%s already installed)', $dep->installedVersion ?? '?'),
                PlannedDep::ACTION_INSTALL => sprintf('(not installed → %s)', $dep->entry->version),
                PlannedDep::ACTION_UPGRADE => sprintf('(%s → %s)', $dep->installedVersion ?? '?', $dep->entry->version),
                PlannedDep::ACTION_CONFIRM => sprintf('(%s → %s, confirmation required)', $dep->installedVersion ?? '?', $dep->entry->version),
                PlannedDep::ACTION_BLOCKED => sprintf('(%s — %s)', $dep->installedVersion ?? '?', $dep->blockReason ?? 'blocked'),
                default                    => '',
            };

            $this->console->line(sprintf(
                '    %-10s  %-25s  %s',
                $label,
                $dep->entry->key,
                $versionInfo,
            ));

            $counters[$dep->action] = ($counters[$dep->action] ?? 0) + 1;
        }

        $this->console->line();

        $summaryParts = [];
        if ($counters[PlannedDep::ACTION_INSTALL] > 0) {
            $summaryParts[] = sprintf('%d to install', $counters[PlannedDep::ACTION_INSTALL]);
        }
        if ($counters[PlannedDep::ACTION_UPGRADE] > 0) {
            $summaryParts[] = sprintf('%d to upgrade', $counters[PlannedDep::ACTION_UPGRADE]);
        }
        if ($counters[PlannedDep::ACTION_CONFIRM] > 0) {
            $summaryParts[] = sprintf('%d require confirmation', $counters[PlannedDep::ACTION_CONFIRM]);
        }
        if ($counters[PlannedDep::ACTION_SKIP] > 0) {
            $summaryParts[] = sprintf('%d already up to date', $counters[PlannedDep::ACTION_SKIP]);
        }

        if ($summaryParts !== []) {
            $this->console->line('  Summary: ' . implode(', ', $summaryParts) . '.');
        } else {
            $this->console->line('  Nothing to install.');
        }

        $this->console->line();
    }

    /**
     * Prints the simulated commands that would be run for each installer module.
     *
     * Called only in dry-run mode.
     *
     * @param list<InstallerInterface> $installers
     */
    public function renderSimulatedCommands(InstallPlan $plan, array $installers): void
    {
        $toApply = $plan->toApply();
        if ($toApply === []) {
            $this->console->line('  [dry-run] No commands to simulate.');
            $this->console->line();

            return;
        }

        $this->console->line('  [dry-run] Simulated commands:');
        $this->console->line();

        foreach ($installers as $installer) {
            $deps = array_values(array_filter($toApply, fn(PlannedDep $d): bool => $installer->supports($d)));
            if ($deps === []) {
                continue;
            }

            foreach ($installer->getSimulatedCommands($deps) as $cmd) {
                $this->console->line("    {$cmd}");
            }
        }

        $this->console->line();
        $this->console->line('  [dry-run] No changes applied.');
        $this->console->line();
    }
}
