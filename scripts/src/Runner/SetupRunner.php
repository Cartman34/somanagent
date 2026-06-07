<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\DevEnv\Installer\InstallerInterface;
use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Sowapps\Toolkit\LocalWorkingDirectories;
use Sowapps\Toolkit\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\DevEnv\LockfileManager;
use Sowapps\SoManAgent\Script\DevEnv\ManifestParser;
use Sowapps\SoManAgent\Script\DevEnv\StateInspector;
use Sowapps\SoManAgent\Script\DevEnv\SystemCommandRunner;
use Sowapps\SoManAgent\Script\DevEnv\InstallPlanner;
use Sowapps\SoManAgent\Script\DevEnv\PlannedDep;
use Sowapps\SoManAgent\Script\DevEnv\PreviewBuilder;
use Sowapps\SoManAgent\Script\DevEnv\Installer\ProjectDepsInstaller;
use Sowapps\SoManAgent\Script\DevEnv\ManifestResolver;
use Sowapps\SoManAgent\Script\DevEnv\SystemSourceQuerier;
use Sowapps\SoManAgent\Script\DevEnv\SystemHttpFetcher;
use Sowapps\SoManAgent\Script\DevEnv\VersionConstraint;
use Sowapps\SoManAgent\Script\DevEnv\Model\Dependency;
use Sowapps\SoManAgent\Script\DevEnv\Model\Lockfile;
use Sowapps\SoManAgent\Script\DevEnv\UninstallPlan;
use Sowapps\SoManAgent\Script\DevEnv\PlannedUninstall;
use Sowapps\SoManAgent\Script\DevEnv\Model\LockEntry;
use Sowapps\SoManAgent\Script\DevEnv\InstallPlan;
use Sowapps\SoManAgent\Script\DevEnv\Installer\DockerInstaller;
use Sowapps\SoManAgent\Script\DevEnv\Installer\SystemDepsInstaller;
use Sowapps\SoManAgent\Script\DevEnv\Installer\ClientsInstaller;
use Sowapps\SoManAgent\Script\DevEnv\Model\Manifest;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Setup orchestrator for the SoManAgent development environment.
 *
 * Manages installation and lifecycle of host-level dependencies (apt, npm, GitHub releases)
 * using a manifest + lockfile model (similar to Composer). Help is driven by YAML resources
 * under scripts/resources/setup/.
 *
 * Subcommands:
 *   install    — Install host dependencies from the lockfile (spec §4.1)
 *   update     — Re-resolve constraints and apply diffs (spec §4.2)
 *   verify     — Check alignment of system ↔ lockfile ↔ manifest (spec §4.3)
 *   uninstall  — Remove installed deps, respecting pre_existing policy (spec §4.4)
 *   reset      — Drop DB and Docker volumes, keeping host deps (spec §4.5)
 *   status     — Display manifest, lockfile, system state, and Docker services (spec §4.6)
 *   dep-config — Read/write per-dep overrides stored in the lockfile (spec §4.7)
 *
 * Execution modes available on mutation commands:
 *   --preview-only  Show the plan and exit without applying
 *   --dry-run       Show the plan and simulated commands, then exit without applying
 *   --force         Apply without confirmation (preview still printed)
 */
final class SetupRunner extends AbstractScriptRunner
{
    private const NAME = 'setup';

    private const MANIFEST_PATH = 'scripts/resources/dependencies.yaml';
    private const LOCK_PATH = 'scripts/resources/dependencies.lock';

    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_PREVIEW_ONLY = 'preview-only';
    private const CMD_DEP_CONFIG = 'dep-config';

    /**
     * Allowed per-dep override property names (v1).
     *
     * @var list<string>
     */
    private const ALLOWED_DEP_CONFIG_PROPERTIES = ['on_uninstall_pre_existing'];

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

        if (!isset($options[self::OPT_DRY_RUN])) {
            LocalWorkingDirectories::ensure($this->resolveRoot(), new FilesystemClient());
        }

        return match ($subcommand) {
            'install'              => $this->runInstall($options),
            'update'               => $this->runUpdate($options),
            'verify'               => $this->runVerify(),
            'uninstall'            => $this->runUninstall($options),
            'reset'                => $this->runReset($options),
            'status'               => $this->runStatus(),
            self::CMD_DEP_CONFIG   => $this->runDepConfig($parsedArgs, $options),
            default      => throw new \RuntimeException(
                sprintf(
                    "Unknown subcommand: '%s'. Run 'php scripts/setup.php help' for available commands.",
                    $subcommand,
                ),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // install
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php install` per spec §4.1.
     *
     * 1. Verifies the lockfile is initialized (absent or sentinel → error).
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
    /**
     * Delegates backlog config preparation to the backlog package's own installer (its wrapper).
     */
    private function installBacklog(): void
    {
        if ($this->dryRun) {
            $this->console->line('  [dry-run] Would run: php scripts/backlog/install.php');

            return;
        }
        $this->app->runCommand('php scripts/backlog/install.php');
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runInstall(array $options): int
    {
        $this->previewOnly = isset($options[self::OPT_PREVIEW_ONLY]);
        $this->dryRun = isset($options[self::OPT_DRY_RUN]);
        $this->force = isset($options['force']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }

        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $manifestPath = $root . '/' . self::MANIFEST_PATH;

        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);
        $lockfileManager->assertInitialized($lockfile);
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

        $this->installBacklog();

        if ($this->dryRun) {
            $preview->renderSimulatedCommands($plan, $installers);
            $this->renderProjectStepsDryRun();

            return 0;
        }

        if ($plan->hasActions()) {
            if (!$this->force && !$this->confirmApply()) {
                return 0;
            }
            $this->applyInstall($plan, $installers, $lockfileManager, $lockPath, $lockfile);
        } else {
            $this->console->info('All host dependencies are already up to date.');
        }

        (new ProjectDepsInstaller(SoManAgentApplication::getInstance(), $this->console, $this->projectRoot . '/backend'))->runProjectSteps();

        $this->console->line();
        $this->console->ok('Installation complete.');

        return 0;
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php update` per spec §4.2.
     *
     * 1. Re-resolves manifest constraints via ManifestResolver → new lockfile in memory.
     * 2. Builds install plan from the new lockfile (system diff).
     * 3. Shows preview.
     * 4. Applies system changes + writes new lockfile.
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runUpdate(array $options): int
    {
        $this->previewOnly = isset($options[self::OPT_PREVIEW_ONLY]);
        $this->dryRun = isset($options[self::OPT_DRY_RUN]);
        $this->force = isset($options['force']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }

        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $manifestPath = $root . '/' . self::MANIFEST_PATH;

        $manifest = (new ManifestParser())->parseFile($manifestPath);
        $lockfileManager = new LockfileManager();
        $existingLockfile = $lockfileManager->read($lockPath);
        $inspector = new StateInspector(new SystemCommandRunner());

        // Resolve manifest constraints → new lockfile in memory
        $resolver = new ManifestResolver(new SystemSourceQuerier(new SystemCommandRunner(), new SystemHttpFetcher()));
        $newLockfile = $resolver->resolve($manifest, $existingLockfile, new \DateTimeImmutable());

        // Build install plan against the new lockfile to detect system diffs
        $plan = (new InstallPlanner())->plan($manifest, $newLockfile, $inspector);

        if ($plan->hasBlocked()) {
            $blockedList = implode("\n", array_map(
                static fn(PlannedDep $d): string => sprintf('  - %s: %s', $d->entry->key, $d->blockReason ?? 'blocked'),
                $plan->blocked(),
            ));
            throw new \RuntimeException("Update blocked by policy:\n{$blockedList}");
        }

        $installers = $this->buildInstallers();
        $preview = new PreviewBuilder($this->console);

        $this->console->step('update');
        $preview->render($plan);

        if ($this->previewOnly) {
            return 0;
        }

        $this->installBacklog();

        if ($this->dryRun) {
            $preview->renderSimulatedCommands($plan, $installers);

            return 0;
        }

        if ($plan->hasActions()) {
            if (!$this->force && !$this->confirmApply()) {
                return 0;
            }
            $this->applyInstall($plan, $installers, $lockfileManager, $lockPath, $newLockfile);
        } else {
            $this->console->info('All host dependencies are already up to date.');
            // Write new lockfile to refresh manifest_hash and resolved_at
            $lockfileManager->write($lockPath, $newLockfile);
        }

        $this->console->line();
        $this->console->ok('Update complete.');

        return 0;
    }

    // -------------------------------------------------------------------------
    // verify
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php verify` per spec §4.3.
     *
     * Compares system state ↔ lockfile ↔ manifest without any mutation.
     * Exits 0 when aligned, 1 when discrepancies are found.
     */
    private function runVerify(): int
    {
        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $manifestPath = $root . '/' . self::MANIFEST_PATH;

        $manifest = (new ManifestParser())->parseFile($manifestPath);
        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);
        $inspector = new StateInspector(new SystemCommandRunner());
        $vc = new VersionConstraint();

        /**
         * @var array<string, Dependency> $depMap
         */
        $depMap = [];
        foreach ($manifest->dependencies as $dep) {
            $depMap[$dep->key] = $dep;
        }

        /**
         * @var list<string> $gaps
         */
        $gaps = [];

        // Check lockfile entries against system state and manifest
        foreach ($lockfile->all() as $entry) {
            $dep = $depMap[$entry->key] ?? null;

            if ($dep === null) {
                $gaps[] = sprintf('[orphan]     %s: in lockfile but not in manifest', $entry->key);
            } elseif (!$vc->satisfies($entry->version, $dep->constraint)) {
                $gaps[] = sprintf(
                    '[constraint] %s: locked v%s does not satisfy %s',
                    $entry->key,
                    $entry->version,
                    $dep->constraint,
                );
            }

            // Detect installed version (use probe dep for orphaned entries)
            if ($dep !== null) {
                $installed = $inspector->getInstalledVersion($dep);
            } else {
                $probeDep = new Dependency($entry->key, $entry->section, '>=0', $entry->installer, $entry->package, []);
                $installed = $inspector->getInstalledVersion($probeDep);
            }

            if ($installed === null) {
                $gaps[] = sprintf('[missing]    %s: in lockfile (v%s) but not installed', $entry->key, $entry->version);
            } elseif (version_compare($vc->normalize($installed), $vc->normalize($entry->version), '<')) {
                $gaps[] = sprintf(
                    '[outdated]   %s: installed v%s < locked v%s',
                    $entry->key,
                    $installed,
                    $entry->version,
                );
            }
        }

        // Check manifest deps not in lockfile
        foreach ($manifest->dependencies as $dep) {
            if ($lockfile->get($dep->key) === null) {
                $gaps[] = sprintf('[unlocked]   %s: in manifest but not in lockfile — run setup.php update', $dep->key);
            }
        }

        $this->console->step('verify');

        if ($gaps === []) {
            $this->console->ok('All dependencies are aligned.');

            return 0;
        }

        $this->console->line('  Discrepancies found:');
        $this->console->line();
        foreach ($gaps as $gap) {
            $this->console->line('    ' . $gap);
        }
        $this->console->line();

        return 1;
    }

    // -------------------------------------------------------------------------
    // uninstall
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php uninstall` per spec §4.4.
     *
     * Removes installed deps from the system while respecting pre_existing flags
     * and the configured on_uninstall_pre_existing policy chain.
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runUninstall(array $options): int
    {
        $this->previewOnly = isset($options[self::OPT_PREVIEW_ONLY]);
        $this->dryRun = isset($options[self::OPT_DRY_RUN]);
        $this->force = isset($options['force']);
        $restoreFlag = isset($options['restore']);
        $keepFlag = isset($options['keep']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }
        if ($restoreFlag && $keepFlag) {
            throw new \RuntimeException('--restore and --keep are mutually exclusive.');
        }

        $cliOverride = $restoreFlag ? 'restore' : ($keepFlag ? 'keep' : null);

        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $manifestPath = $root . '/' . self::MANIFEST_PATH;

        $manifest = (new ManifestParser())->parseFile($manifestPath);
        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);

        if ($lockfile->all() === []) {
            $this->console->info('Lockfile is empty — nothing to uninstall.');

            return 0;
        }

        /**
         * @var array<string, Dependency> $depMap
         */
        $depMap = [];
        foreach ($manifest->dependencies as $dep) {
            $depMap[$dep->key] = $dep;
        }

        $plan = $this->buildUninstallPlan($lockfile, $manifest, $depMap, $cliOverride);

        $this->console->step('uninstall');
        $this->renderUninstallPreview($plan);

        if ($this->previewOnly) {
            return 0;
        }

        if ($this->dryRun) {
            $this->renderUninstallDryRun($plan);

            return 0;
        }

        if (!$plan->hasActions()) {
            $this->console->info('Nothing to uninstall (all pre-existing deps are kept by policy).');

            return 0;
        }

        if (!$this->force && !$this->confirmApply()) {
            return 0;
        }

        $this->applyUninstall($plan, $lockfileManager, $lockPath, $lockfile);

        $this->console->line();
        $this->console->ok('Uninstall complete.');

        return 0;
    }

    /**
     * Builds the complete uninstall plan from the lockfile and manifest.
     *
     * @param array<string, Dependency> $depMap
     */
    private function buildUninstallPlan(
        Lockfile $lockfile,
        Manifest $manifest,
        array $depMap,
        ?string $cliOverride,
    ): UninstallPlan {
        $items = [];
        foreach ($lockfile->all() as $entry) {
            $dep = $depMap[$entry->key] ?? null;
            $action = $this->resolveUninstallAction($entry, $manifest, $dep, $cliOverride);
            $source = $this->resolveUninstallPolicySource($entry, $dep, $cliOverride);
            $items[] = new PlannedUninstall($entry, $action, $source);
        }

        return new UninstallPlan($items);
    }

    /**
     * Resolves the uninstall action for one lockfile entry (spec §4.4 priority chain).
     */
    private function resolveUninstallAction(
        LockEntry $entry,
        Manifest $manifest,
        ?Dependency $dep,
        ?string $cliOverride,
    ): string {
        if (!$entry->preExisting) {
            return PlannedUninstall::ACTION_REMOVE;
        }

        // Priority 1: CLI global one-shot flag
        if ($cliOverride === 'restore') {
            return PlannedUninstall::ACTION_RESTORE;
        }
        if ($cliOverride === 'keep') {
            return PlannedUninstall::ACTION_KEEP;
        }

        // Priority 2: Lockfile per-dep override
        $lockfileOverride = $entry->overrides['on_uninstall_pre_existing'] ?? null;
        if ($lockfileOverride === 'restore') {
            return PlannedUninstall::ACTION_RESTORE;
        }
        if ($lockfileOverride === 'keep') {
            return PlannedUninstall::ACTION_KEEP;
        }

        // Priority 3 (dep-level) + 4 (manifest default) + 5 (framework default)
        $policy = $dep !== null
            ? $manifest->resolveOnUninstallPreExisting($dep)
            : $manifest->onUninstallPreExisting;

        return $policy === 'restore' ? PlannedUninstall::ACTION_RESTORE : PlannedUninstall::ACTION_KEEP;
    }

    /**
     * Returns a human-readable label indicating which policy level determined the action.
     */
    private function resolveUninstallPolicySource(
        LockEntry $entry,
        ?Dependency $dep,
        ?string $cliOverride,
    ): string {
        if (!$entry->preExisting) {
            return 'not pre-existing';
        }
        if ($cliOverride !== null) {
            return 'cli flag';
        }
        if (isset($entry->overrides['on_uninstall_pre_existing'])) {
            return 'lockfile override';
        }
        if ($dep !== null && $dep->onUninstallPreExisting !== null) {
            return 'manifest dep';
        }

        return 'manifest default';
    }

    /**
     * Renders the uninstall preview table and side-effects summary.
     */
    private function renderUninstallPreview(UninstallPlan $plan): void
    {
        $this->console->line();
        $this->console->line('  Uninstall plan:');
        $this->console->line();

        $removes = 0;
        $restores = 0;
        $keeps = 0;

        foreach ($plan->items as $item) {
            $label = match ($item->action) {
                PlannedUninstall::ACTION_REMOVE  => 'REMOVE  ',
                PlannedUninstall::ACTION_RESTORE => 'RESTORE ',
                PlannedUninstall::ACTION_KEEP    => 'KEEP    ',
                default                          => strtoupper($item->action),
            };

            $detail = match ($item->action) {
                PlannedUninstall::ACTION_REMOVE  => sprintf('(v%s)', $item->entry->version),
                PlannedUninstall::ACTION_RESTORE => $item->entry->previousVersion !== null
                    ? sprintf('(v%s → restore v%s)', $item->entry->version, $item->entry->previousVersion)
                    : sprintf('(v%s → remove, no previous version recorded)', $item->entry->version),
                PlannedUninstall::ACTION_KEEP    => sprintf('(v%s, kept — %s)', $item->entry->version, $item->policySource),
                default                          => '',
            };

            $this->console->line(sprintf('    %-10s  %-25s  %s', $label, $item->entry->key, $detail));

            match ($item->action) {
                PlannedUninstall::ACTION_REMOVE  => $removes++,
                PlannedUninstall::ACTION_RESTORE => $restores++,
                PlannedUninstall::ACTION_KEEP    => $keeps++,
                default                          => null,
            };
        }

        $sideEffects = $plan->sideEffectsToRemove();
        if ($sideEffects !== []) {
            $this->console->line();
            $this->console->line('  Side effects to remove:');
            foreach ($sideEffects as $path) {
                $this->console->line('    - ' . $path);
            }
        }

        $this->console->line();

        $summaryParts = [];
        if ($removes > 0) {
            $summaryParts[] = sprintf('%d to remove', $removes);
        }
        if ($restores > 0) {
            $summaryParts[] = sprintf('%d to restore', $restores);
        }
        if ($keeps > 0) {
            $summaryParts[] = sprintf('%d kept', $keeps);
        }

        if ($summaryParts !== []) {
            $this->console->line('  Summary: ' . implode(', ', $summaryParts) . '.');
        }

        $this->console->line();
    }

    /**
     * Renders the simulated uninstall commands in dry-run mode.
     */
    private function renderUninstallDryRun(UninstallPlan $plan): void
    {
        $toApply = $plan->toApply();
        $sideEffects = $plan->sideEffectsToRemove();

        if ($toApply === [] && $sideEffects === []) {
            $this->console->line('  [dry-run] No commands to simulate.');
            $this->console->line();

            return;
        }

        $this->console->line('  [dry-run] Simulated commands:');
        $this->console->line();

        foreach ($toApply as $planned) {
            foreach ($this->buildUninstallCommands($planned) as $cmd) {
                $this->console->line('    ' . $cmd);
            }
        }

        foreach ($sideEffects as $path) {
            $this->console->line(sprintf('    sudo rm -f %s', escapeshellarg($path)));
        }

        $this->console->line();
        $this->console->line('  [dry-run] No changes applied.');
        $this->console->line();
    }

    /**
     * Applies the uninstall plan and updates the lockfile.
     */
    private function applyUninstall(
        UninstallPlan $plan,
        LockfileManager $lockfileManager,
        string $lockPath,
        Lockfile $lockfile,
    ): void {
        $now = new \DateTimeImmutable();

        foreach ($plan->toApply() as $planned) {
            $verb = $planned->action === PlannedUninstall::ACTION_RESTORE ? 'Restoring' : 'Removing';
            $this->console->info(sprintf('%s %s (v%s)', $verb, $planned->entry->key, $planned->entry->version));

            foreach ($this->buildUninstallCommands($planned) as $cmd) {
                $code = $this->app->runCommand($cmd);
                if ($code !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Failed to %s %s (exit %d)',
                        $planned->action,
                        $planned->entry->key,
                        $code,
                    ));
                }
            }

            if ($planned->action === PlannedUninstall::ACTION_RESTORE && $planned->entry->previousVersion !== null) {
                // Update entry to reflect the restored version
                $restored = $planned->entry->withResolution(
                    $planned->entry->previousVersion,
                    $planned->entry->source,
                    null,
                    $planned->entry->sideEffects,
                    $now,
                );
                $lockfile = $lockfile->withEntry($restored);
            } else {
                $lockfile = $lockfile->withoutEntry($planned->entry->key);
            }
        }

        // Remove shared side effects not needed by any remaining entry
        foreach ($plan->sideEffectsToRemove() as $path) {
            $this->console->info(sprintf('Removing side effect: %s', $path));
            $code = $this->app->runCommand(sprintf('sudo rm -f %s', escapeshellarg($path)));
            if ($code !== 0) {
                $this->console->warn(sprintf('Failed to remove side effect %s (exit %d)', $path, $code));
            }
        }

        $lockfileManager->write($lockPath, $lockfile);
    }

    /**
     * Returns the shell commands needed to remove or restore one dependency.
     *
     * Side-effect files are handled separately by applyUninstall() via sideEffectsToRemove().
     *
     * @return list<string>
     */
    private function buildUninstallCommands(PlannedUninstall $planned): array
    {
        $entry = $planned->entry;
        $isRestore = $planned->action === PlannedUninstall::ACTION_RESTORE;

        return match ($entry->installer) {
            'apt' => $isRestore && $entry->previousVersion !== null
                ? [sprintf(
                    'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y %s',
                    escapeshellarg($entry->package . '=' . $entry->previousVersion),
                )]
                : [sprintf(
                    'sudo DEBIAN_FRONTEND=noninteractive apt-get remove -y %s',
                    escapeshellarg($entry->package),
                )],

            'npm-global' => $isRestore && $entry->previousVersion !== null
                ? [sprintf('npm install -g %s', escapeshellarg($entry->package . '@' . $entry->previousVersion))]
                : [sprintf('npm uninstall -g %s', escapeshellarg($entry->package))],

            // For github-release, restore of a specific previous version requires
            // re-downloading from GitHub — not supported in v1; falls back to removal.
            'github-release' => [sprintf('sudo rm -f %s', escapeshellarg('/usr/local/bin/' . $entry->package))],

            default => throw new \RuntimeException(
                sprintf('Unsupported installer for uninstall: %s', $entry->installer),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // reset
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php reset` per spec §4.5.
     *
     * Drops the database and removes Docker volumes. Does NOT touch host dependencies.
     * Requires explicit confirmation (destructive operation).
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runReset(array $options): int
    {
        $this->previewOnly = isset($options[self::OPT_PREVIEW_ONLY]);
        $this->dryRun = isset($options[self::OPT_DRY_RUN]);
        $this->force = isset($options['force']);
        $keepVolumes = isset($options['keep-volumes']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }

        $this->console->step('reset');
        $this->console->line();
        $this->console->line('  Reset plan:');
        $this->console->line('    - Drop database (php scripts/db.php reset --force)');
        if (!$keepVolumes) {
            $this->console->line('    - Remove Docker volumes (docker compose down -v)');
        } else {
            $this->console->line('    - Stop Docker containers (docker compose down, volumes kept)');
        }
        $this->console->line('    - Host dependencies, code, and client binaries are NOT touched');
        $this->console->line();

        if ($this->previewOnly) {
            return 0;
        }

        if ($this->dryRun) {
            $this->console->line('  [dry-run] Simulated commands:');
            $this->console->line('    php scripts/db.php reset --force');
            $this->console->line($keepVolumes ? '    docker compose down' : '    docker compose down -v');
            $this->console->line();
            $this->console->line('  [dry-run] No changes applied.');
            $this->console->line();

            return 0;
        }

        if (!$this->force && !$this->confirmReset()) {
            return 0;
        }

        $this->console->info('Dropping database...');
        $dbCmd = sprintf('php %s/scripts/db.php reset --force', escapeshellarg($this->projectRoot));
        $code = $this->app->runCommand($dbCmd);
        if ($code !== 0) {
            $this->console->warn(sprintf('Database drop failed (exit %d) — continuing with volume removal.', $code));
        }

        $dockerCmd = $keepVolumes ? 'docker compose down' : 'docker compose down -v';
        $this->console->info(sprintf('Running: %s', $dockerCmd));
        $code = $this->app->runCommand($dockerCmd);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('Docker compose down failed (exit %d).', $code));
        }

        $this->console->line();
        $this->console->ok('Reset complete. Run setup.php install to restore project dependencies.');

        return 0;
    }

    // -------------------------------------------------------------------------
    // status
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php status` per spec §4.6.
     *
     * Displays manifest constraints, lockfile state (with overrides), installed
     * versions, Docker service state, and last applied migration.
     * No mutation.
     */
    private function runStatus(): int
    {
        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $manifestPath = $root . '/' . self::MANIFEST_PATH;

        $manifest = (new ManifestParser())->parseFile($manifestPath);
        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);
        $inspector = new StateInspector(new SystemCommandRunner());
        $vc = new VersionConstraint();

        /**
         * @var array<string, Dependency> $depMap
         */
        $depMap = [];
        foreach ($manifest->dependencies as $dep) {
            $depMap[$dep->key] = $dep;
        }

        $this->console->step('status');

        // --- Manifest ---
        $this->console->line('  Manifest (scripts/resources/dependencies.yaml):');
        $this->console->line();
        if ($manifest->dependencies === []) {
            $this->console->line('    (no dependencies declared)');
        } else {
            foreach ($manifest->dependencies as $dep) {
                $this->console->line(sprintf(
                    '    %-25s  %-12s  %s (%s)',
                    $dep->key,
                    $dep->constraint,
                    $dep->package,
                    $dep->installer,
                ));
            }
        }

        // --- Lockfile ---
        $this->console->line();
        $this->console->line('  Lockfile (scripts/resources/dependencies.lock):');
        $this->console->line();
        if ($lockfile->generatedAt !== null) {
            $this->console->line(sprintf('    Generated: %s', $lockfile->generatedAt->format(\DateTimeInterface::ATOM)));
        }
        $this->console->line();
        if ($lockfile->all() === []) {
            $this->console->line('    (empty — run setup.php update first)');
        } else {
            foreach ($lockfile->all() as $entry) {
                $flags = [];
                if ($entry->preExisting) {
                    $flags[] = 'pre-existing';
                }
                foreach ($entry->overrides as $k => $v) {
                    $flags[] = sprintf('%s=%s', $k, $v);
                }
                $flagStr = $flags !== [] ? ' [' . implode(', ', $flags) . ']' : '';

                $this->console->line(sprintf(
                    '    %-25s  v%-20s  %s%s',
                    $entry->key,
                    $entry->version,
                    $entry->source,
                    $flagStr,
                ));
            }
        }

        // --- System state ---
        $this->console->line();
        $this->console->line('  System state:');
        $this->console->line();
        if ($lockfile->all() === []) {
            $this->console->line('    (no lockfile entries to compare)');
        } else {
            foreach ($lockfile->all() as $entry) {
                $dep = $depMap[$entry->key] ?? null;
                if ($dep !== null) {
                    $installed = $inspector->getInstalledVersion($dep);
                } else {
                    $probeDep = new Dependency(
                        $entry->key,
                        $entry->section,
                        '>=0',
                        $entry->installer,
                        $entry->package,
                        [],
                    );
                    $installed = $inspector->getInstalledVersion($probeDep);
                }

                if ($installed === null) {
                    $stateStr = '(not installed)';
                } elseif (version_compare($vc->normalize($installed), $vc->normalize($entry->version), '>=')) {
                    $stateStr = sprintf('v%s ✓', $installed);
                } else {
                    $stateStr = sprintf('v%s  (locked: v%s, outdated)', $installed, $entry->version);
                }

                $this->console->line(sprintf('    %-25s  %s', $entry->key, $stateStr));
            }
        }

        // --- Docker services ---
        $this->console->line();
        $this->console->line('  Docker services:');
        $this->console->line();

        /**
         * @var array<int, string> $dockerLines
         */
        $dockerLines = [];
        $dockerExit = 0;
        exec('docker compose ps 2>/dev/null', $dockerLines, $dockerExit);

        if ($dockerExit !== 0 || $dockerLines === []) {
            $this->console->line('    (docker compose not available or no containers running)');
        } else {
            foreach ($dockerLines as $line) {
                $this->console->line('    ' . $line);
            }
        }

        // --- Last migration ---
        $this->console->line();
        $this->console->line('  Last migration:');
        $this->console->line('    (requires database connection — run with containers up for live status)');
        $this->console->line();

        return 0;
    }

    // -------------------------------------------------------------------------
    // dep-config
    // -------------------------------------------------------------------------

    /**
     * Implements `setup.php dep-config` per spec §4.7.
     *
     * Reads and writes per-dep overrides stored in the lockfile.
     * V1 exposes only on_uninstall_pre_existing (values: keep | restore).
     *
     * @param list<string> $parsedArgs Remaining positional arguments after the subcommand
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runDepConfig(array $parsedArgs, array $options): int
    {
        $action = array_shift($parsedArgs);
        if ($action === null || !in_array($action, ['get', 'set', 'unset'], true)) {
            throw new \RuntimeException(
                "dep-config requires an action: get, set, or unset.\n"
                . "Run 'php scripts/setup.php help dep-config' for usage.",
            );
        }

        $depKey = array_shift($parsedArgs);
        if ($depKey === null || $depKey === '') {
            throw new \RuntimeException(
                sprintf("dep-config %s requires a dependency key argument.", $action),
            );
        }

        $root = $this->resolveRoot();
        $lockPath = $root . '/' . self::LOCK_PATH;
        $lockfileManager = new LockfileManager();
        $lockfile = $lockfileManager->read($lockPath);

        $entry = $lockfile->get($depKey);
        if ($entry === null) {
            $available = implode(', ', array_map(
                static fn(LockEntry $e): string => $e->key,
                $lockfile->all(),
            ));
            throw new \RuntimeException(sprintf(
                "Unknown dependency '%s' — not found in lockfile.%s",
                $depKey,
                $available !== '' ? sprintf(' Available: %s', $available) : ' Lockfile is empty.',
            ));
        }

        return match ($action) {
            'get'   => $this->runDepConfigGet($entry, $parsedArgs),
            'set'   => $this->runDepConfigSet($lockfile, $entry, $parsedArgs, $lockfileManager, $lockPath),
            'unset' => $this->runDepConfigUnset($lockfile, $entry, $parsedArgs, $lockfileManager, $lockPath),
        };
    }

    /**
     * Displays per-dep overrides.
     *
     * Without property: all overrides for the dep.
     * With property: the effective value and its source.
     *
     * @param list<string> $args
     */
    private function runDepConfigGet(LockEntry $entry, array $args): int
    {
        $property = $args[0] ?? null;

        if ($property === null) {
            $this->console->step('dep-config get');
            if ($entry->overrides === []) {
                $this->console->line(sprintf('  %s: no overrides set.', $entry->key));
            } else {
                $this->console->line(sprintf('  %s overrides:', $entry->key));
                foreach ($entry->overrides as $prop => $value) {
                    $this->console->line(sprintf('    %s = %s', $prop, $value));
                }
            }

            return 0;
        }

        if (!in_array($property, self::ALLOWED_DEP_CONFIG_PROPERTIES, true)) {
            throw new \RuntimeException(sprintf(
                "Unknown property '%s'. Allowed: %s",
                $property,
                implode(', ', self::ALLOWED_DEP_CONFIG_PROPERTIES),
            ));
        }

        $this->console->step('dep-config get');
        $overrideValue = $entry->overrides[$property] ?? null;
        if ($overrideValue !== null) {
            $this->console->line(sprintf(
                '  %s.%s = %s  (lockfile override)',
                $entry->key,
                $property,
                $overrideValue,
            ));
        } else {
            $this->console->line(sprintf(
                '  %s.%s: no override set — resolved from manifest / framework default.',
                $entry->key,
                $property,
            ));
        }

        return 0;
    }

    /**
     * Sets a per-dep override property in the lockfile (idempotent).
     *
     * @param list<string> $args
     */
    private function runDepConfigSet(
        Lockfile $lockfile,
        LockEntry $entry,
        array $args,
        LockfileManager $manager,
        string $lockPath,
    ): int {
        $property = array_shift($args);
        $value = array_shift($args);

        if ($property === null || $property === '') {
            throw new \RuntimeException('dep-config set requires: <dep> <property> <value>');
        }
        if ($value === null || $value === '') {
            throw new \RuntimeException('dep-config set requires: <dep> <property> <value>');
        }

        if (!in_array($property, self::ALLOWED_DEP_CONFIG_PROPERTIES, true)) {
            throw new \RuntimeException(sprintf(
                "Unknown property '%s'. Allowed: %s",
                $property,
                implode(', ', self::ALLOWED_DEP_CONFIG_PROPERTIES),
            ));
        }

        $allowedValues = match ($property) {
            'on_uninstall_pre_existing' => ['keep', 'restore'],
        };
        if (!in_array($value, $allowedValues, true)) {
            throw new \RuntimeException(sprintf(
                "Invalid value '%s' for %s. Allowed: %s",
                $value,
                $property,
                implode(', ', $allowedValues),
            ));
        }

        // Idempotent: no-op when already set to the same value
        if (($entry->overrides[$property] ?? null) === $value) {
            $this->console->info(sprintf(
                '%s.%s is already set to %s — no change.',
                $entry->key,
                $property,
                $value,
            ));

            return 0;
        }

        $updatedLockfile = $lockfile->withEntry($entry->withOverride($property, $value));
        $manager->write($lockPath, $updatedLockfile);

        $this->console->ok(sprintf('Set %s.%s = %s', $entry->key, $property, $value));

        return 0;
    }

    /**
     * Removes a per-dep override property from the lockfile (idempotent).
     *
     * @param list<string> $args
     */
    private function runDepConfigUnset(
        Lockfile $lockfile,
        LockEntry $entry,
        array $args,
        LockfileManager $manager,
        string $lockPath,
    ): int {
        $property = array_shift($args);

        if ($property === null || $property === '') {
            throw new \RuntimeException('dep-config unset requires: <dep> <property>');
        }

        if (!in_array($property, self::ALLOWED_DEP_CONFIG_PROPERTIES, true)) {
            throw new \RuntimeException(sprintf(
                "Unknown property '%s'. Allowed: %s",
                $property,
                implode(', ', self::ALLOWED_DEP_CONFIG_PROPERTIES),
            ));
        }

        // Idempotent: no-op when the property is not set
        if (!isset($entry->overrides[$property])) {
            $this->console->info(sprintf('%s.%s is not set — no change.', $entry->key, $property));

            return 0;
        }

        $updatedLockfile = $lockfile->withEntry($entry->withoutOverride($property));
        $manager->write($lockPath, $updatedLockfile);

        $this->console->ok(sprintf('Unset %s.%s', $entry->key, $property));

        return 0;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves the project root to use for lockfile and manifest paths.
     *
     * SOMANAGER_PROJECT_ROOT overrides the default project root so that tests
     * can point to a temporary directory without touching real files.
     */
    private function resolveRoot(): string
    {
        $override = getenv('SOMANAGER_PROJECT_ROOT');

        return ($override !== false && $override !== '') ? $override : $this->projectRoot;
    }

    /**
     * Applies all install actions and persists the updated lockfile after each installer.
     *
     * @param list<InstallerInterface> $installers
     * @param Lockfile $lockfile
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
        $commands = (new ProjectDepsInstaller(SoManAgentApplication::getInstance(), $this->console, $this->projectRoot . '/backend'))->getSimulatedCommands();
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
     * Builds the ordered list of installer modules used by the install and update commands.
     *
     * @return list<InstallerInterface>
     */
    private function buildInstallers(): array
    {
        return [
            new DockerInstaller(SoManAgentApplication::getInstance(), $this->console),
            new SystemDepsInstaller(SoManAgentApplication::getInstance(), $this->console),
            new ClientsInstaller(SoManAgentApplication::getInstance(), $this->console),
        ];
    }

    /**
     * Prompts the user to confirm a standard (non-destructive) apply operation.
     *
     * Throws when stdin is not a tty and --force was not passed.
     */
    private function confirmApply(): bool
    {
        if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
            throw new \RuntimeException(
                'Non-interactive: use --force to apply without confirmation, '
                . 'or --preview-only / --dry-run to show the plan without applying.',
            );
        }

        $this->console->line('  Apply? [y/N]');
        $answer = trim((string) (fgets(STDIN) ?: ''));
        if (!preg_match('/^[yY]/', $answer)) {
            $this->console->info('Aborted.');

            return false;
        }

        return true;
    }

    /**
     * Prompts the user to confirm the destructive reset operation.
     *
     * Uses a stronger warning message to make the destructiveness explicit.
     *
     * Throws when stdin is not a tty and --force was not passed.
     */
    private function confirmReset(): bool
    {
        if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
            throw new \RuntimeException(
                'Non-interactive reset requires --force (destructive operation: DB drop + volume removal).',
            );
        }

        $this->console->warn('This will permanently delete all database data and Docker volumes.');
        $this->console->line('  Confirm reset? [y/N]');
        $answer = trim((string) (fgets(STDIN) ?: ''));
        if (!preg_match('/^[yY]/', $answer)) {
            $this->console->info('Aborted.');

            return false;
        }

        return true;
    }
}
