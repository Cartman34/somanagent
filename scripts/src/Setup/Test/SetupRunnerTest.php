<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Setup\Test;

/**
 * Subprocess integration tests for scripts/setup.php.
 *
 * Spawns the setup.php script as a child process to verify CLI behaviour without
 * requiring Docker or actual package installation. Covers:
 *   - Help display (runner-level and command-level)
 *   - install: --preview-only, --dry-run, mutual exclusion, missing lockfile, sentinel lockfile, unknown subcommand
 *   - update: --preview-only, --dry-run, mutual exclusion (empty manifest → no network queries)
 *   - verify: aligned (empty manifest + empty lockfile), and unlocked gap detection
 *   - uninstall: --preview-only with empty lockfile, --restore/--keep mutual exclusion
 *   - reset: --preview-only, --dry-run, mutual exclusion
 *   - status: runs with empty lockfile (graceful Docker fallback)
 *   - dep-config: get/set/unset on a temp lockfile with a real entry
 */
final class SetupRunnerTest
{
    private const OPT_DRY_RUN = 'dry-run';
    private const CMD_DEP_CONFIG = 'dep-config';
    private const TEST_DEP_KEY = 'test-dep';
    private const TEST_DEP_NONEXISTENT = 'nonexistent-dep';

    private string $projectRoot;

    /**
     * Resolves the project root from the test file location.
     */
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 4);
    }

    /**
     * Runs all tests and returns the number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        // install
        $failed += $this->testHelpDisplay();
        $failed += $this->testInstallCommandHelp();
        $failed += $this->testInstallPreviewOnlyWithEmptyLockfile();
        $failed += $this->testInstallCreatesLocalWorkingDirectories();
        $failed += $this->testInstallCreatesBacklogConfig();
        $failed += $this->testInstallDoesNotOverwriteExistingBacklogConfig();
        $failed += $this->testInstallDryRunWithEmptyLockfile();
        $failed += $this->testInstallDryRunAnnouncesBacklogConfig();
        $failed += $this->testMutualExclusionFlags();
        $failed += $this->testUnknownSubcommandError();
        $failed += $this->testMissingLockfileError();
        $failed += $this->testSentinelLockfileError();

        // update
        $failed += $this->testUpdateCommandHelp();
        $failed += $this->testUpdatePreviewOnlyWithEmptyManifest();
        $failed += $this->testUpdateDryRunWithEmptyManifest();
        $failed += $this->testUpdateDryRunAnnouncesBacklogConfig();
        $failed += $this->testUpdateMutualExclusionFlags();

        // verify
        $failed += $this->testVerifyCommandHelp();
        $failed += $this->testVerifyAlignedEmptyManifest();
        $failed += $this->testVerifyReportsUnlockedDep();

        // uninstall
        $failed += $this->testUninstallCommandHelp();
        $failed += $this->testUninstallPreviewOnlyEmptyLockfile();
        $failed += $this->testUninstallRestoreKeepMutualExclusion();
        $failed += $this->testUninstallPreviewDryRunMutualExclusion();

        // reset
        $failed += $this->testResetCommandHelp();
        $failed += $this->testResetPreviewOnly();
        $failed += $this->testResetDryRun();
        $failed += $this->testResetMutualExclusionFlags();

        // status
        $failed += $this->testStatusCommandHelp();
        $failed += $this->testStatusRunsWithEmptyLockfile();

        // dep-config
        $failed += $this->testDepConfigCommandHelp();
        $failed += $this->testDepConfigInvalidAction();
        $failed += $this->testDepConfigUnknownDep();
        $failed += $this->testDepConfigInvalidProperty();
        $failed += $this->testDepConfigSetGetUnset();
        $failed += $this->testDepConfigSetIdempotent();
        $failed += $this->testDepConfigUnsetNoOp();

        return $failed;
    }

    // -------------------------------------------------------------------------
    // install
    // -------------------------------------------------------------------------

    private function testHelpDisplay(): int
    {
        [$output, $exit] = $this->run_(['help']);

        if ($exit !== 0) {
            echo "FAIL testHelpDisplay: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, 'install')) {
            echo "FAIL testHelpDisplay: expected 'install' in output\n";
            return 1;
        }

        echo "OK testHelpDisplay\n";
        return 0;
    }

    private function testInstallCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'install']);

        if ($exit !== 0) {
            echo "FAIL testInstallCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, '--preview-only')) {
            echo "FAIL testInstallCommandHelp: expected '--preview-only' in output\n";
            return 1;
        }

        if (!str_contains($output, '--dry-run')) {
            echo "FAIL testInstallCommandHelp: expected '--dry-run' in output\n";
            return 1;
        }

        echo "OK testInstallCommandHelp\n";
        return 0;
    }

    private function testInstallPreviewOnlyWithEmptyLockfile(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();

        try {
            [$output, $exit] = $this->run_(
                ['install', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testInstallPreviewOnlyWithEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'Installation plan')) {
                echo "FAIL testInstallPreviewOnlyWithEmptyLockfile: expected 'Installation plan' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallPreviewOnlyWithEmptyLockfile\n";
        return 0;
    }

    private function testInstallDryRunWithEmptyLockfile(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();

        try {
            [$output, $exit] = $this->run_(
                ['install', '--dry-run'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testInstallDryRunWithEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, self::OPT_DRY_RUN)) {
                echo "FAIL testInstallDryRunWithEmptyLockfile: expected dry-run marker in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallDryRunWithEmptyLockfile\n";
        return 0;
    }

    private function testInstallCreatesLocalWorkingDirectories(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();

        try {
            [, $exit] = $this->run_(
                ['install', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testInstallCreatesLocalWorkingDirectories: expected exit 0, got {$exit}\n";
                return 1;
            }

            foreach (['local/tmp', 'local/tests'] as $relativePath) {
                if (!is_dir($tmpDir . '/' . $relativePath)) {
                    echo "FAIL testInstallCreatesLocalWorkingDirectories: missing directory {$relativePath}\n";
                    return 1;
                }
                if (!is_file($tmpDir . '/' . $relativePath . '/.gitkeep')) {
                    echo "FAIL testInstallCreatesLocalWorkingDirectories: missing keep file {$relativePath}/.gitkeep\n";
                    return 1;
                }
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallCreatesLocalWorkingDirectories\n";
        return 0;
    }

    private function testInstallCreatesBacklogConfig(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();

        try {
            // Exit may be non-zero when Docker is unavailable (ProjectDepsInstaller);
            // LocalConfigBootstrap runs before that step so the file must still be created.
            $this->run_(
                ['install', '--force'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if (!is_file($tmpDir . '/local/backlog/config.yaml')) {
                echo "FAIL testInstallCreatesBacklogConfig: missing local/backlog/config.yaml\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallCreatesBacklogConfig\n";
        return 0;
    }

    private function testInstallDoesNotOverwriteExistingBacklogConfig(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();
        $localConfigPath = $tmpDir . '/local/backlog/config.yaml';

        try {
            mkdir($tmpDir . '/local/backlog', 0o755, true);
            file_put_contents($localConfigPath, "# existing\n");

            // Exit may be non-zero when Docker is unavailable; we only check idempotency.
            $this->run_(
                ['install', '--force'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            $contents = (string) file_get_contents($localConfigPath);
            if ($contents !== "# existing\n") {
                echo "FAIL testInstallDoesNotOverwriteExistingBacklogConfig: local config was overwritten\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallDoesNotOverwriteExistingBacklogConfig\n";
        return 0;
    }

    private function testInstallDryRunAnnouncesBacklogConfig(): int
    {
        $tmpDir = $this->setUpTempProjectWithInitializedLockfile();

        try {
            [$output, $exit] = $this->run_(
                ['install', '--dry-run'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testInstallDryRunAnnouncesBacklogConfig: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'local/backlog/config.yaml')) {
                echo "FAIL testInstallDryRunAnnouncesBacklogConfig: expected 'local/backlog/config.yaml' in dry-run output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testInstallDryRunAnnouncesBacklogConfig\n";
        return 0;
    }

    private function testMutualExclusionFlags(): int
    {
        [$output, $exit] = $this->run_(['install', '--preview-only', '--dry-run']);

        if ($exit === 0) {
            echo "FAIL testMutualExclusionFlags: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testMutualExclusionFlags: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testMutualExclusionFlags\n";
        return 0;
    }

    private function testUnknownSubcommandError(): int
    {
        [$output, $exit] = $this->run_(['nonexistent-cmd']);

        if ($exit === 0) {
            echo "FAIL testUnknownSubcommandError: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'Unknown subcommand')) {
            echo "FAIL testUnknownSubcommandError: expected 'Unknown subcommand' in output\n";
            return 1;
        }

        echo "OK testUnknownSubcommandError\n";
        return 0;
    }

    private function testMissingLockfileError(): int
    {
        $tmpDir = $this->testOutputRoot() . '/setup_test_no_lock_' . uniqid();
        mkdir($tmpDir, 0o755, true);

        try {
            [$output, $exit] = $this->run_(
                ['install', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testMissingLockfileError: expected non-zero exit\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'Lockfile not initialized')) {
                echo "FAIL testMissingLockfileError: expected 'Lockfile not initialized' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeLocalWorkingDirectories($tmpDir);
            @rmdir($tmpDir);
        }

        echo "OK testMissingLockfileError\n";
        return 0;
    }

    private function testSentinelLockfileError(): int
    {
        $tmpDir = $this->testOutputRoot() . '/setup_test_sentinel_' . uniqid();
        $resourcesDir = $tmpDir . '/scripts/resources';
        mkdir($resourcesDir, 0o755, true);

        try {
            // Write a sentinel lockfile (generated_at: ~ and empty sections — same as the one that was committed)
            file_put_contents($resourcesDir . '/dependencies.lock', "generated_at: ~\nmanifest_hash: ~\nhost:\n  system: {}\n  docker: {}\n  clients: {}\n");
            file_put_contents($resourcesDir . '/dependencies.yaml', "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost: {}\n");

            [$output, $exit] = $this->run_(
                ['install', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testSentinelLockfileError: expected non-zero exit\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'Lockfile not initialized')) {
                echo "FAIL testSentinelLockfileError: expected 'Lockfile not initialized' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeLocalWorkingDirectories($tmpDir);
            @unlink($resourcesDir . '/dependencies.lock');
            @unlink($resourcesDir . '/dependencies.yaml');
            @rmdir($resourcesDir);
            @rmdir($tmpDir . '/scripts');
            @rmdir($tmpDir);
        }

        echo "OK testSentinelLockfileError\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    private function testUpdateCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'update']);

        if ($exit !== 0) {
            echo "FAIL testUpdateCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, '--preview-only')) {
            echo "FAIL testUpdateCommandHelp: expected '--preview-only' in output\n";
            return 1;
        }

        echo "OK testUpdateCommandHelp\n";
        return 0;
    }

    private function testUpdatePreviewOnlyWithEmptyManifest(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['update', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testUpdatePreviewOnlyWithEmptyManifest: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'update')) {
                echo "FAIL testUpdatePreviewOnlyWithEmptyManifest: expected 'update' step in output\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testUpdatePreviewOnlyWithEmptyManifest\n";
        return 0;
    }

    private function testUpdateDryRunWithEmptyManifest(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['update', '--dry-run'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testUpdateDryRunWithEmptyManifest: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, self::OPT_DRY_RUN)) {
                echo "FAIL testUpdateDryRunWithEmptyManifest: expected dry-run marker in output\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testUpdateDryRunWithEmptyManifest\n";
        return 0;
    }

    private function testUpdateDryRunAnnouncesBacklogConfig(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['update', '--dry-run'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testUpdateDryRunAnnouncesBacklogConfig: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'local/backlog/config.yaml')) {
                echo "FAIL testUpdateDryRunAnnouncesBacklogConfig: expected 'local/backlog/config.yaml' in dry-run output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testUpdateDryRunAnnouncesBacklogConfig\n";
        return 0;
    }

    private function testUpdateMutualExclusionFlags(): int
    {
        [$output, $exit] = $this->run_(['update', '--preview-only', '--dry-run']);

        if ($exit === 0) {
            echo "FAIL testUpdateMutualExclusionFlags: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testUpdateMutualExclusionFlags: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testUpdateMutualExclusionFlags\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // verify
    // -------------------------------------------------------------------------

    private function testVerifyCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'verify']);

        if ($exit !== 0) {
            echo "FAIL testVerifyCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, 'verify')) {
            echo "FAIL testVerifyCommandHelp: expected 'verify' in output\n";
            return 1;
        }

        echo "OK testVerifyCommandHelp\n";
        return 0;
    }

    private function testVerifyAlignedEmptyManifest(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['verify'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testVerifyAlignedEmptyManifest: expected exit 0 (aligned), got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'aligned')) {
                echo "FAIL testVerifyAlignedEmptyManifest: expected 'aligned' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testVerifyAlignedEmptyManifest\n";
        return 0;
    }

    private function testVerifyReportsUnlockedDep(): int
    {
        // Manifest has one dep, lockfile is empty → dep is unlocked → exit 1
        $tmpDir = $this->setUpTempProject(null); // minimal manifest with one dep, empty lockfile

        try {
            [$output, $exit] = $this->run_(
                ['verify'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testVerifyReportsUnlockedDep: expected exit 1 (discrepancy), got 0\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'unlocked')) {
                echo "FAIL testVerifyReportsUnlockedDep: expected 'unlocked' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testVerifyReportsUnlockedDep\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // uninstall
    // -------------------------------------------------------------------------

    private function testUninstallCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'uninstall']);

        if ($exit !== 0) {
            echo "FAIL testUninstallCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, '--restore')) {
            echo "FAIL testUninstallCommandHelp: expected '--restore' in output\n";
            return 1;
        }

        echo "OK testUninstallCommandHelp\n";
        return 0;
    }

    private function testUninstallPreviewOnlyEmptyLockfile(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['uninstall', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testUninstallPreviewOnlyEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            // Empty lockfile → "nothing to uninstall"
            if (!str_contains($output, 'nothing') && !str_contains($output, 'empty')) {
                echo "FAIL testUninstallPreviewOnlyEmptyLockfile: expected 'nothing' or 'empty' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testUninstallPreviewOnlyEmptyLockfile\n";
        return 0;
    }

    private function testUninstallRestoreKeepMutualExclusion(): int
    {
        [$output, $exit] = $this->run_(['uninstall', '--restore', '--keep']);

        if ($exit === 0) {
            echo "FAIL testUninstallRestoreKeepMutualExclusion: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testUninstallRestoreKeepMutualExclusion: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testUninstallRestoreKeepMutualExclusion\n";
        return 0;
    }

    private function testUninstallPreviewDryRunMutualExclusion(): int
    {
        [$output, $exit] = $this->run_(['uninstall', '--preview-only', '--dry-run']);

        if ($exit === 0) {
            echo "FAIL testUninstallPreviewDryRunMutualExclusion: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testUninstallPreviewDryRunMutualExclusion: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testUninstallPreviewDryRunMutualExclusion\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // reset
    // -------------------------------------------------------------------------

    private function testResetCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'reset']);

        if ($exit !== 0) {
            echo "FAIL testResetCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, '--keep-volumes')) {
            echo "FAIL testResetCommandHelp: expected '--keep-volumes' in output\n";
            return 1;
        }

        echo "OK testResetCommandHelp\n";
        return 0;
    }

    private function testResetPreviewOnly(): int
    {
        [$output, $exit] = $this->run_(['reset', '--preview-only']);

        if ($exit !== 0) {
            echo "FAIL testResetPreviewOnly: expected exit 0, got {$exit}\nOutput: {$output}\n";
            return 1;
        }

        if (!str_contains($output, 'Reset plan')) {
            echo "FAIL testResetPreviewOnly: expected 'Reset plan' in output\nOutput: {$output}\n";
            return 1;
        }

        echo "OK testResetPreviewOnly\n";
        return 0;
    }

    private function testResetDryRun(): int
    {
        [$output, $exit] = $this->run_(['reset', '--dry-run']);

        if ($exit !== 0) {
            echo "FAIL testResetDryRun: expected exit 0, got {$exit}\nOutput: {$output}\n";
            return 1;
        }

        if (!str_contains($output, self::OPT_DRY_RUN)) {
            echo "FAIL testResetDryRun: expected dry-run marker in output\nOutput: {$output}\n";
            return 1;
        }

        echo "OK testResetDryRun\n";
        return 0;
    }

    private function testResetMutualExclusionFlags(): int
    {
        [$output, $exit] = $this->run_(['reset', '--preview-only', '--dry-run']);

        if ($exit === 0) {
            echo "FAIL testResetMutualExclusionFlags: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testResetMutualExclusionFlags: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testResetMutualExclusionFlags\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // status
    // -------------------------------------------------------------------------

    private function testStatusCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'status']);

        if ($exit !== 0) {
            echo "FAIL testStatusCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, 'status')) {
            echo "FAIL testStatusCommandHelp: expected 'status' in output\n";
            return 1;
        }

        echo "OK testStatusCommandHelp\n";
        return 0;
    }

    private function testStatusRunsWithEmptyLockfile(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                ['status'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testStatusRunsWithEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'Manifest') && !str_contains($output, 'Lockfile')) {
                echo "FAIL testStatusRunsWithEmptyLockfile: expected 'Manifest' or 'Lockfile' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testStatusRunsWithEmptyLockfile\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // dep-config
    // -------------------------------------------------------------------------

    private function testDepConfigCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', self::CMD_DEP_CONFIG]);

        if ($exit !== 0) {
            echo "FAIL testDepConfigCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, 'get') || !str_contains($output, 'set') || !str_contains($output, 'unset')) {
            echo "FAIL testDepConfigCommandHelp: expected get/set/unset in output\n";
            return 1;
        }

        echo "OK testDepConfigCommandHelp\n";
        return 0;
    }

    private function testDepConfigInvalidAction(): int
    {
        [$output, $exit] = $this->run_([self::CMD_DEP_CONFIG, 'bad-action', 'git']);

        if ($exit === 0) {
            echo "FAIL testDepConfigInvalidAction: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'get, set, or unset')) {
            echo "FAIL testDepConfigInvalidAction: expected action list in output\nOutput: {$output}\n";
            return 1;
        }

        echo "OK testDepConfigInvalidAction\n";
        return 0;
    }

    private function testDepConfigUnknownDep(): int
    {
        $tmpDir = $this->setUpTempProject([]);

        try {
            [$output, $exit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'get', self::TEST_DEP_NONEXISTENT],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testDepConfigUnknownDep: expected non-zero exit\n";
                return 1;
            }

            if (!str_contains($output, self::TEST_DEP_NONEXISTENT)) {
                echo "FAIL testDepConfigUnknownDep: expected dep key in error output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testDepConfigUnknownDep\n";
        return 0;
    }

    private function testDepConfigInvalidProperty(): int
    {
        $tmpDir = $this->setUpTempProjectWithLockfileEntry();

        try {
            [$output, $exit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'get', self::TEST_DEP_KEY, 'bad_property'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testDepConfigInvalidProperty: expected non-zero exit\n";
                return 1;
            }

            if (!str_contains($output, 'bad_property')) {
                echo "FAIL testDepConfigInvalidProperty: expected property name in error\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testDepConfigInvalidProperty\n";
        return 0;
    }

    private function testDepConfigSetGetUnset(): int
    {
        $tmpDir = $this->setUpTempProjectWithLockfileEntry();

        try {
            // set
            [, $setExit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'set', self::TEST_DEP_KEY, 'on_uninstall_pre_existing', 'restore'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );
            if ($setExit !== 0) {
                echo "FAIL testDepConfigSetGetUnset (set): expected exit 0\n";
                return 1;
            }

            // get — should show the value
            [$getOutput, $getExit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'get', self::TEST_DEP_KEY, 'on_uninstall_pre_existing'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );
            if ($getExit !== 0 || !str_contains($getOutput, 'restore')) {
                echo "FAIL testDepConfigSetGetUnset (get): expected 'restore' in output\nOutput: {$getOutput}\n";
                return 1;
            }

            // unset
            [, $unsetExit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'unset', self::TEST_DEP_KEY, 'on_uninstall_pre_existing'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );
            if ($unsetExit !== 0) {
                echo "FAIL testDepConfigSetGetUnset (unset): expected exit 0\n";
                return 1;
            }

            // get again — should show "no override set"
            [$get2Output, $get2Exit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'get', self::TEST_DEP_KEY, 'on_uninstall_pre_existing'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );
            if ($get2Exit !== 0 || !str_contains($get2Output, 'no override')) {
                echo "FAIL testDepConfigSetGetUnset (get after unset): expected 'no override' in output\nOutput: {$get2Output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testDepConfigSetGetUnset\n";
        return 0;
    }

    private function testDepConfigSetIdempotent(): int
    {
        $tmpDir = $this->setUpTempProjectWithLockfileEntry();

        try {
            // Set twice — second should be no-op
            $this->run_(
                [self::CMD_DEP_CONFIG, 'set', self::TEST_DEP_KEY, 'on_uninstall_pre_existing', 'keep'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );
            [$output, $exit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'set', self::TEST_DEP_KEY, 'on_uninstall_pre_existing', 'keep'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testDepConfigSetIdempotent: expected exit 0, got {$exit}\n";
                return 1;
            }

            if (!str_contains($output, 'no change')) {
                echo "FAIL testDepConfigSetIdempotent: expected 'no change' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testDepConfigSetIdempotent\n";
        return 0;
    }

    private function testDepConfigUnsetNoOp(): int
    {
        $tmpDir = $this->setUpTempProjectWithLockfileEntry();

        try {
            // Unset a property that isn't set — should be a no-op (exit 0)
            [$output, $exit] = $this->run_(
                [self::CMD_DEP_CONFIG, 'unset', self::TEST_DEP_KEY, 'on_uninstall_pre_existing'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit !== 0) {
                echo "FAIL testDepConfigUnsetNoOp: expected exit 0, got {$exit}\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'no change')) {
                echo "FAIL testDepConfigUnsetNoOp: expected 'no change' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testDepConfigUnsetNoOp\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a minimal temp project with an initialized (non-sentinel) empty lockfile.
     *
     * Use this for install tests that need a valid lockfile with no deps.
     *
     * @return string Temp project root path
     */
    private function setUpTempProjectWithInitializedLockfile(): string
    {
        $tmpDir = $this->testOutputRoot() . '/setup_test_init_' . uniqid();
        $resourcesDir = $tmpDir . '/scripts/resources';
        mkdir($resourcesDir . '/backlog', 0o755, true);

        $manifest = "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost: {}\n";
        file_put_contents($resourcesDir . '/dependencies.yaml', $manifest);
        file_put_contents(
            $resourcesDir . '/dependencies.lock',
            "generated_at: '2026-01-01T00:00:00+00:00'\nmanifest_hash: abc123\nhost: {}\n",
        );
        copy(
            $this->projectRoot . '/scripts/resources/backlog/config.yaml.dist',
            $resourcesDir . '/backlog/config.yaml.dist',
        );

        return $tmpDir;
    }

    /**
     * Creates a minimal temp project tree for testing.
     *
     * Writes an empty lockfile and a manifest.
     *
     * @param array<mixed>|null $manifestDeps Null = write a manifest with one dep; empty array = empty manifest
     * @return string Temp project root path
     */
    private function setUpTempProject(?array $manifestDeps): string
    {
        $tmpDir = $this->testOutputRoot() . '/setup_test_' . uniqid();
        $resourcesDir = $tmpDir . '/scripts/resources';
        mkdir($resourcesDir . '/backlog', 0o755, true);

        if ($manifestDeps === null) {
            // Minimal manifest with one dep (apt, to avoid network queries)
            $manifest = "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost:\n  system:\n    test-dep:\n      constraint: '>=1.0'\n      installer: apt\n      package: test-package\n      sources: [default]\n";
        } elseif ($manifestDeps === []) {
            $manifest = "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost: {}\n";
        } else {
            $manifest = "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost:\n  system:\n    test-dep:\n      constraint: '>=1.0'\n      installer: apt\n      package: test-package\n      sources: [default]\n";
        }

        file_put_contents($resourcesDir . '/dependencies.yaml', $manifest);
        file_put_contents($resourcesDir . '/dependencies.lock', "generated_at: ~\nmanifest_hash: ~\nhost: {}\n");
        copy(
            $this->projectRoot . '/scripts/resources/backlog/config.yaml.dist',
            $resourcesDir . '/backlog/config.yaml.dist',
        );

        return $tmpDir;
    }

    /**
     * Creates a temp project with a lockfile containing one entry for dep-config tests.
     *
     * @return string Temp project root path
     */
    private function setUpTempProjectWithLockfileEntry(): string
    {
        $tmpDir = $this->testOutputRoot() . '/setup_test_depconf_' . uniqid();
        $resourcesDir = $tmpDir . '/scripts/resources';
        mkdir($resourcesDir . '/backlog', 0o755, true);

        $manifest = "defaults:\n  on_existing_below_min: upgrade\n  on_uninstall_pre_existing: keep\nhost:\n  system:\n    test-dep:\n      constraint: '>=1.0'\n      installer: apt\n      package: test-package\n      sources: [default]\n";
        file_put_contents($resourcesDir . '/dependencies.yaml', $manifest);

        $lockfile = "generated_at: '2026-01-01T00:00:00+00:00'\nmanifest_hash: abc123\nhost:\n  system:\n    test-dep:\n      version: '1.0.0'\n      installer: apt\n      package: test-package\n      source: default\n      pre_existing: false\n      previous_version: ~\n      side_effects: ~\n      resolved_at: '2026-01-01T00:00:00+00:00'\n";
        file_put_contents($resourcesDir . '/dependencies.lock', $lockfile);
        copy(
            $this->projectRoot . '/scripts/resources/backlog/config.yaml.dist',
            $resourcesDir . '/backlog/config.yaml.dist',
        );

        return $tmpDir;
    }

    /**
     * Removes the temp project tree created by setUpTempProject or setUpTempProjectWithLockfileEntry.
     */
    private function removeTempProject(string $tmpDir): void
    {
        $this->removeLocalWorkingDirectories($tmpDir);
        $resourcesDir = $tmpDir . '/scripts/resources';
        @unlink($resourcesDir . '/dependencies.yaml');
        @unlink($resourcesDir . '/dependencies.lock');
        @unlink($resourcesDir . '/backlog/config.yaml.dist');
        @rmdir($resourcesDir . '/backlog');
        @rmdir($resourcesDir);
        @rmdir($tmpDir . '/scripts');
        @rmdir($tmpDir);
    }

    private function removeLocalWorkingDirectories(string $tmpDir): void
    {
        @unlink($tmpDir . '/local/tmp/.gitkeep');
        @unlink($tmpDir . '/local/tests/.gitkeep');
        @unlink($tmpDir . '/local/backlog/config.yaml');
        @rmdir($tmpDir . '/local/tmp');
        @rmdir($tmpDir . '/local/tests');
        @rmdir($tmpDir . '/local/backlog');
        @rmdir($tmpDir . '/local');
    }

    private function testOutputRoot(): string
    {
        $path = $this->projectRoot . '/local/tests/setup-runner';
        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create setup test output directory: {$path}");
        }

        return $path;
    }

    /**
     * Runs setup.php with the given arguments and returns [stdout+stderr, exit_code].
     *
     * @param list<string>         $args CLI arguments
     * @param array<string,string> $env  Optional env vars prepended to the command
     * @return array{0: string, 1: int}
     */
    private function run_(array $args, array $env = []): array
    {
        $envPrefix = '';
        if ($env !== []) {
            $parts = [];
            foreach ($env as $key => $value) {
                $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
            }
            $envPrefix = implode(' ', $parts) . ' ';
        }

        $cmd = sprintf(
            '%sphp %s %s 2>&1',
            $envPrefix,
            escapeshellarg($this->projectRoot . '/scripts/setup.php'),
            implode(' ', array_map('escapeshellarg', $args)),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            return ['Failed to open process', 1];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$output, $exit];
    }
}
