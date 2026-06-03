<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogScopeService;

/**
 * Unit tests for BacklogScopeService.
 *
 * Covers: (a) effective scope resolution with inheritance, (b) file-level scope checking,
 * (c) task-within-feature-scope validation, including add, modify, delete, rename, and mode-change cases.
 */
final class BacklogScopeServiceTest
{
    private BacklogScopeService $service;

    /**
     * Initializes the service under test.
     */
    public function __construct()
    {
        $this->service = new BacklogScopeService();
    }

    /**
     * Runs all tests and returns the number of failures.
     *
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testResolveEffectiveScopeNameTaskInheritsFeature();
        $failed += $this->testResolveEffectiveScopeNameTaskOwn();
        $failed += $this->testResolveEffectiveScopeNameBothNull();
        $failed += $this->testResolveEffectiveScopeNameFeatureNoTask();
        $failed += $this->testResolveScopeDirsKnown();
        $failed += $this->testResolveScopeDirsNull();
        $failed += $this->testResolveScopeDirsUnknown();
        $failed += $this->testCollectScopeViolationsNone();
        $failed += $this->testCollectScopeViolationsAdd();
        $failed += $this->testCollectScopeViolationsDelete();
        $failed += $this->testCollectScopeViolationsRenameSourceOut();
        $failed += $this->testCollectScopeViolationsRenameDestOut();
        $failed += $this->testCollectScopeViolationsRenameSourceAndDestIn();
        $failed += $this->testCollectScopeViolationsChmod();
        $failed += $this->testCollectScopeViolationsMultipleDirs();
        $failed += $this->testIsTaskScopeWithinFeatureScopeValid();
        $failed += $this->testIsTaskScopeWithinFeatureScopeInvalid();
        $failed += $this->testIsTaskScopeWithinFeatureScopeSubdir();

        return $failed;
    }

    // ─── resolveEffectiveScopeName ────────────────────────────────────────────

    private function testResolveEffectiveScopeNameTaskInheritsFeature(): int
    {
        $result = $this->service->resolveEffectiveScopeName(null, 'backend');
        if ($result !== 'backend') {
            echo "FAIL testResolveEffectiveScopeNameTaskInheritsFeature: expected 'backend', got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveEffectiveScopeNameTaskInheritsFeature\n";
        return 0;
    }

    private function testResolveEffectiveScopeNameTaskOwn(): int
    {
        $result = $this->service->resolveEffectiveScopeName('scripts', 'backend');
        if ($result !== 'scripts') {
            echo "FAIL testResolveEffectiveScopeNameTaskOwn: expected 'scripts', got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveEffectiveScopeNameTaskOwn\n";
        return 0;
    }

    private function testResolveEffectiveScopeNameBothNull(): int
    {
        $result = $this->service->resolveEffectiveScopeName(null, null);
        if ($result !== null) {
            echo "FAIL testResolveEffectiveScopeNameBothNull: expected null, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveEffectiveScopeNameBothNull\n";
        return 0;
    }

    private function testResolveEffectiveScopeNameFeatureNoTask(): int
    {
        $result = $this->service->resolveEffectiveScopeName('frontend', null);
        if ($result !== 'frontend') {
            echo "FAIL testResolveEffectiveScopeNameFeatureNoTask: expected 'frontend', got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveEffectiveScopeNameFeatureNoTask\n";
        return 0;
    }

    // ─── resolveScopeDirs ─────────────────────────────────────────────────────

    private function testResolveScopeDirsKnown(): int
    {
        $scopes = ['scripts' => ['scripts/'], 'backend' => ['backend/']];
        $result = $this->service->resolveScopeDirs('scripts', $scopes);
        if ($result !== ['scripts/']) {
            echo "FAIL testResolveScopeDirsKnown: expected ['scripts/'], got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveScopeDirsKnown\n";
        return 0;
    }

    private function testResolveScopeDirsNull(): int
    {
        $scopes = ['scripts' => ['scripts/']];
        $result = $this->service->resolveScopeDirs(null, $scopes);
        if ($result !== null) {
            echo "FAIL testResolveScopeDirsNull: expected null (ALL), got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveScopeDirsNull\n";
        return 0;
    }

    private function testResolveScopeDirsUnknown(): int
    {
        $scopes = ['scripts' => ['scripts/']];
        $result = $this->service->resolveScopeDirs('unknown', $scopes);
        if ($result !== []) {
            echo "FAIL testResolveScopeDirsUnknown: expected [], got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testResolveScopeDirsUnknown\n";
        return 0;
    }

    // ─── collectScopeViolations ───────────────────────────────────────────────

    private function testCollectScopeViolationsNone(): int
    {
        $files = ['scripts/src/Foo.php', 'scripts/resources/bar.yaml'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== []) {
            echo "FAIL testCollectScopeViolationsNone: expected no violations, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsNone\n";
        return 0;
    }

    private function testCollectScopeViolationsAdd(): int
    {
        // Simulate: a new file outside scope was added
        $files = ['scripts/src/Foo.php', 'backend/src/Bar.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== ['backend/src/Bar.php']) {
            echo "FAIL testCollectScopeViolationsAdd: expected ['backend/src/Bar.php'], got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsAdd\n";
        return 0;
    }

    private function testCollectScopeViolationsDelete(): int
    {
        // Simulate: a deleted file outside scope
        $files = ['backend/src/OldFile.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== ['backend/src/OldFile.php']) {
            echo "FAIL testCollectScopeViolationsDelete: expected violation on deleted file, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsDelete\n";
        return 0;
    }

    private function testCollectScopeViolationsRenameSourceOut(): int
    {
        // Source of rename is outside scope → violation
        $files = ['backend/src/OldFile.php', 'scripts/src/NewFile.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== ['backend/src/OldFile.php']) {
            echo "FAIL testCollectScopeViolationsRenameSourceOut: expected violation on rename source, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsRenameSourceOut\n";
        return 0;
    }

    private function testCollectScopeViolationsRenameDestOut(): int
    {
        // Destination of rename is outside scope → violation
        $files = ['scripts/src/OldFile.php', 'backend/src/NewFile.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== ['backend/src/NewFile.php']) {
            echo "FAIL testCollectScopeViolationsRenameDestOut: expected violation on rename destination, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsRenameDestOut\n";
        return 0;
    }

    private function testCollectScopeViolationsRenameSourceAndDestIn(): int
    {
        // Both source and destination inside scope → no violation
        $files = ['scripts/src/OldFile.php', 'scripts/src/NewFile.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== []) {
            echo "FAIL testCollectScopeViolationsRenameSourceAndDestIn: expected no violations, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsRenameSourceAndDestIn\n";
        return 0;
    }

    private function testCollectScopeViolationsChmod(): int
    {
        // Mode change (chmod +x) on a file outside scope → violation
        $files = ['backend/src/SomeScript.php'];
        $result = $this->service->collectScopeViolations($files, ['scripts/']);
        if ($result !== ['backend/src/SomeScript.php']) {
            echo "FAIL testCollectScopeViolationsChmod: expected violation on chmod outside scope, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsChmod\n";
        return 0;
    }

    private function testCollectScopeViolationsMultipleDirs(): int
    {
        // File is in scope via one of multiple scope directories
        $files = ['scripts/src/Foo.php', 'doc/development/guide.md'];
        $result = $this->service->collectScopeViolations($files, ['scripts/', 'doc/']);
        if ($result !== []) {
            echo "FAIL testCollectScopeViolationsMultipleDirs: expected no violations with multi-dir scope, got " . var_export($result, true) . "\n";
            return 1;
        }
        echo "OK testCollectScopeViolationsMultipleDirs\n";
        return 0;
    }

    // ─── isTaskScopeWithinFeatureScope ────────────────────────────────────────

    private function testIsTaskScopeWithinFeatureScopeValid(): int
    {
        $taskDirs = ['scripts/'];
        $featureDirs = ['scripts/'];
        if (!$this->service->isTaskScopeWithinFeatureScope($taskDirs, $featureDirs)) {
            echo "FAIL testIsTaskScopeWithinFeatureScopeValid: identical dirs should be valid\n";
            return 1;
        }
        echo "OK testIsTaskScopeWithinFeatureScopeValid\n";
        return 0;
    }

    private function testIsTaskScopeWithinFeatureScopeInvalid(): int
    {
        $taskDirs = ['frontend/'];
        $featureDirs = ['scripts/'];
        if ($this->service->isTaskScopeWithinFeatureScope($taskDirs, $featureDirs)) {
            echo "FAIL testIsTaskScopeWithinFeatureScopeInvalid: unrelated dirs should be invalid\n";
            return 1;
        }
        echo "OK testIsTaskScopeWithinFeatureScopeInvalid\n";
        return 0;
    }

    private function testIsTaskScopeWithinFeatureScopeSubdir(): int
    {
        // Task restricted to a subdirectory of the feature scope → valid
        $taskDirs = ['scripts/src/Backlog/'];
        $featureDirs = ['scripts/'];
        if (!$this->service->isTaskScopeWithinFeatureScope($taskDirs, $featureDirs)) {
            echo "FAIL testIsTaskScopeWithinFeatureScopeSubdir: subdir of feature scope should be valid\n";
            return 1;
        }
        echo "OK testIsTaskScopeWithinFeatureScopeSubdir\n";
        return 0;
    }
}
