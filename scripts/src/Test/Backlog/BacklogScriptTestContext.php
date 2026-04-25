<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

final class BacklogScriptTestContext
{
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $boardPath,
        public readonly string $reviewPath,
        public readonly string $tmpDir,
        public readonly bool $allowRemote,
        public readonly bool $keepArtifacts,
        public readonly bool $dryRun,
        public readonly bool $verbose,
        public readonly string $agentPrimary = 'test-d01',
        public readonly string $agentSecondary = 'test-d02',
        public readonly string $plainFeature = 'test-plain-feature-alpha',
        public readonly string $assignFeature = 'test-assign-feature',
        public readonly string $fixFeature = 'test-fix-feature-beta',
        public readonly string $scopedFeature = 'test-scoped-feature',
        public readonly string $childA = 'test-child-a',
        public readonly string $childB = 'test-child-b',
    ) {
    }
}
