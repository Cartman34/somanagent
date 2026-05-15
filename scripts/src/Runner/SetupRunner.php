<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\DevEnv\InstallPlan;
use SoManAgent\Script\DevEnv\InstallPlanner;
use SoManAgent\Script\DevEnv\Installer\ClientsInstaller;
use SoManAgent\Script\DevEnv\Installer\DockerInstaller;
use SoManAgent\Script\DevEnv\Installer\InstallerInterface;
use SoManAgent\Script\DevEnv\Installer\ProjectDepsInstaller;
use SoManAgent\Script\DevEnv\Installer\SystemDepsInstaller;
use SoManAgent\Script\DevEnv\LockfileManager;
use SoManAgent\Script\DevEnv\ManifestParser;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\PlannedDep;
use SoManAgent\Script\DevEnv\PreviewBuilder;
use SoManAgent\Script\DevEnv\StateInspector;
use SoManAgent\Script\DevEnv\SystemCommandRunner;

/**
 * Setup orchestrator for the SoManAgent development environment.
 *
 * Manages installation and lifecycle of host-level dependencies (apt, npm, GitHub releases)
 * using a manifest + lockfile model (similar to Composer). Help is driven by YAML resources
 * under scripts/resources/setup/.
 *
 * Subcommand status:
 *   install   — implemented (this task)
 *   update, verify, uninstall, reset, status, dep-config — not yet implemented (setup-lifecycle task)
 *
 * Execution modes available on install:
 *   --preview-only  Show the installation plan and exit without applying
 *   --dry-run       Show the plan and simulated commands, then exit without applying
 *   --force         Apply without confirmation (preview still printed)
 */
final class SetupRunner extends AbstractScriptRunner
{
    public const NAME = 'setup';

    private const MANIFEST_PATH = 'scripts/resources/dependencies.yaml';
    private const LOCK_PATH = 'scripts/resources/dependencies.lock';

    private bool $previewOnly = false;
    private bool $force = false;

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * Execution-mode options are declared per-command in YAML; suppress the base-class defaults.
     *
     * @return list<never>
     */
    protected function getExecutionModeOptionsAsDtos(): array
    {
        return [];
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs($args);
        $subcommand = array_shift($parsedArgs) ?? '';

        if ($subcommand === '' || $subcommand === 'help') {
            $target = $parsedArgs[0] ?? '';
            if ($target !== '') {
                $this->printYamlCommandHelp($target);

                return 0;
            }
            $this->printHelp();

            return 0;
        }

        if (isset($options['help'])) {
            $this->printYamlCommandHelp($subcommand);

            return 0;
        }

        return match ($subcommand) {
            'install' => $this->runInstall($options),
            'update', 'verify', 'uninstall', 'reset', 'status', 'dep-config' => throw new \RuntimeException(
                sprintf(
                    "Subcommand '%s' is not yet implemented. It will be available in a future task (setup-lifecycle).",
                    $subcommand,
                ),
            ),
            default => throw new \RuntimeException(
                sprintf(
                    "Unknown subcommand: '%s'. Run 'php scripts/setup.php help' for available commands.",
                    $subcommand,
                ),
            ),
        };
    }

    /**
     * Implements `setup.php install` per spec §4.1.
     *
     * 1. Verifies the lockfile exists.
     * 2. Reads lockfile + manifest, builds install plan.
     * 3. Exits before preview if any dep is BLOCKED (§3.2).
     * 4. Prints preview.
     * 5. Exits without applying on --preview-only or --dry-run.
     * 6. Asks confirmation unless --force.
     * 7. Applies via installer modules and updates lockfile.
     * 8. Runs project-level steps (composer, npm in container, migrations).
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runInstall(array $options): int
    {
        $this->previewOnly = isset($options['preview-only']);
        $this->dryRun = isset($options['dry-run']);
        $this->force = isset($options['force']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }

        $lockPath = $this->projectRoot . '/' . self::LOCK_PATH;
        $manifestPath = $this->projectRoot . '/' . self::MANIFEST_PATH;

        if (!is_file($lockPath)) {
            throw new \RuntimeException(
                "Lockfile absent — run 'php scripts/setup.php update' first to resolve dependencies.",
            );
        }

        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);
        $manifest = (new ManifestParser())->parseFile($manifestPath);
        $inspector = new StateInspector(new SystemCommandRunner());

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->hasBlocked()) {
            $blockedList = implode("\n", array_map(
                static fn(PlannedDep $d): string => sprintf('  - %s: %s', $d->entry->key, $d->blockReason ?? 'blocked'),
                $plan->blocked(),
            ));
            throw new \RuntimeException("Installation blocked by policy:\n{$blockedList}");
        }

        $installers = $this->buildInstallers();
        $preview = new PreviewBuilder($this->console);

        $this->console->step('install');
        $preview->render($plan);

        if ($this->previewOnly) {
            return 0;
        }

        if ($this->dryRun) {
            $preview->renderSimulatedCommands($plan, $installers);
            $this->renderProjectStepsDryRun();

            return 0;
        }

        if (!$plan->hasActions()) {
            $this->console->info('Nothing to install — all dependencies are up to date.');

            return 0;
        }

        if (!$this->force && !$this->confirmApply()) {
            return 0;
        }

        $this->applyInstall($plan, $installers, $lockfileManager, $lockPath, $lockfile);

        (new ProjectDepsInstaller($this->app, $this->console))->runProjectSteps();

        $this->console->line();
        $this->console->ok('Installation complete.');

        return 0;
    }

    /**
     * Applies all install actions and persists the updated lockfile after each installer.
     *
     * @param list<InstallerInterface>  $installers
     * @param \SoManAgent\Script\DevEnv\Model\Lockfile $lockfile
     */
    private function applyInstall(
        InstallPlan $plan,
        array $installers,
        LockfileManager $lockfileManager,
        string $lockPath,
        Lockfile $lockfile,
    ): void {
        $toApply = $plan->toApply();

        foreach ($installers as $installer) {
            $batch = array_values(array_filter($toApply, fn(PlannedDep $d): bool => $installer->supports($d)));
            if ($batch === []) {
                continue;
            }

            $updatedEntries = $installer->install($batch);
            foreach ($updatedEntries as $entry) {
                $lockfile = $lockfile->withEntry($entry);
            }

            // Persist after each installer so partial progress is saved on failure
            $lockfileManager->write($lockPath, $lockfile);
        }
    }

    /**
     * Prints the project-level setup steps that would run in dry-run mode.
     *
     * Project steps are not lockfile-driven, so they are not included in
     * PreviewBuilder::renderSimulatedCommands() — this method covers the gap.
     */
    private function renderProjectStepsDryRun(): void
    {
        $commands = (new ProjectDepsInstaller($this->app, $this->console))->getSimulatedCommands();
        if ($commands === []) {
            return;
        }

        $this->console->line('  [dry-run] Project setup steps (conditional on containers running):');
        foreach ($commands as $cmd) {
            $this->console->line("    {$cmd}");
        }
        $this->console->line();
    }

    /**
     * Builds the ordered list of installer modules used by the install command.
     *
     * @return list<InstallerInterface>
     */
    private function buildInstallers(): array
    {
        return [
            new DockerInstaller($this->app, $this->console),
            new SystemDepsInstaller($this->app, $this->console),
            new ClientsInstaller($this->app, $this->console),
        ];
    }

    /**
     * Prompts for confirmation on an interactive terminal.
     *
     * Throws when stdin is not a tty and neither --force, --preview-only nor --dry-run
     * was passed, because there is no safe default for a potentially destructive operation
     * in non-interactive mode.
     */
    private function confirmApply(): bool
    {
        if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
            throw new \RuntimeException(
                'Non-interactive: use --force to apply without confirmation, '
                . 'or --preview-only / --dry-run to show the plan without applying.',
            );
        }

        echo '  Apply? [y/N] ';
        $answer = trim((string) (fgets(STDIN) ?: ''));
        if (!preg_match('/^[yY]/', $answer)) {
            $this->console->info('Aborted.');

            return false;
        }

        return true;
    }
}
