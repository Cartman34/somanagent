<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Mutation lock campaign.
 *
 * Verifies that two concurrent backlog mutations are serialised by the lock:
 * both complete successfully and neither corrupts the board.
 */
final class MutationLockCampaign implements CampaignInterface
{
    private const FEATURE_ALPHA = 'lock-feat-alpha';
    private const FEATURE_BETA = 'lock-feat-beta';

    public function getName(): string
    {
        return 'mutation-lock';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $agentAlpha = $context->agentPrimary;
        $agentBeta = $context->agentSecondary;

        $driver->createTodoTask(sprintf('[feat][%s] Implement lock test alpha', self::FEATURE_ALPHA));
        $driver->createTodoTask(sprintf('[feat][%s] Implement lock test beta', self::FEATURE_BETA));

        // Record worktrees and branches before launching so cleanup works even on failure.
        $worktreeAlpha = $driver->managedWorktreePath($agentAlpha);
        $worktreeBeta = $driver->managedWorktreePath($agentBeta);
        $context->recordWorktree($worktreeAlpha);
        $context->recordWorktree($worktreeBeta);
        $context->recordLocalBranch('feat/' . self::FEATURE_ALPHA);
        $context->recordLocalBranch('feat/' . self::FEATURE_BETA);

        // Launch both work-start commands concurrently. With the lock both must complete
        // without corrupting the board. Without the lock they would race on the queued tasks
        // and both might consume the first task, leaving the board inconsistent.
        [[$codeAlpha, $outAlpha], [$codeBeta, $outBeta]] = $driver->runTwoConcurrentBacklog(
            ['work-start', '--agent', $agentAlpha],
            ['work-start', '--agent', $agentBeta],
        );

        if ($codeAlpha !== 0) {
            throw new \RuntimeException(sprintf(
                'Concurrent work-start for %s failed (exit %d): %s',
                $agentAlpha,
                $codeAlpha,
                $outAlpha,
            ));
        }

        if ($codeBeta !== 0) {
            throw new \RuntimeException(sprintf(
                'Concurrent work-start for %s failed (exit %d): %s',
                $agentBeta,
                $codeBeta,
                $outBeta,
            ));
        }

        // Each agent must have started a different feature: one got alpha, the other got beta.
        $this->assertOnePerAgent($outAlpha, $outBeta);

        // Both features must be in the board's active section.
        $driver->assertActiveFeatureExists(self::FEATURE_ALPHA);
        $driver->assertActiveFeatureExists(self::FEATURE_BETA);

        // Cleanup: release both features (no commits, so feature-release is allowed).
        $driver->releaseFeature($agentAlpha, self::FEATURE_ALPHA);
        $driver->releaseFeature($agentBeta, self::FEATURE_BETA);

        $driver->assertActiveFeatureMissing(self::FEATURE_ALPHA);
        $driver->assertActiveFeatureMissing(self::FEATURE_BETA);
    }

    private function assertOnePerAgent(string $outAlpha, string $outBeta): void
    {
        $alphaGotAlpha = str_contains($outAlpha, self::FEATURE_ALPHA);
        $betaGotBeta = str_contains($outBeta, self::FEATURE_BETA);
        $alphaGotBeta = str_contains($outAlpha, self::FEATURE_BETA);
        $betaGotAlpha = str_contains($outBeta, self::FEATURE_ALPHA);

        $crossedOk = ($alphaGotBeta && $betaGotAlpha);
        $straightOk = ($alphaGotAlpha && $betaGotBeta);

        if (!$straightOk && !$crossedOk) {
            throw new \RuntimeException(sprintf(
                "Concurrent work-start produced unexpected feature distribution.\nAgent alpha output: %s\nAgent beta output: %s",
                $outAlpha,
                $outBeta,
            ));
        }

        // Verify there is no overlap: each agent must reference exactly one of the two features,
        // and they must not reference the same feature.
        $alphaFeature = $alphaGotAlpha ? self::FEATURE_ALPHA : self::FEATURE_BETA;
        $betaFeature = $betaGotAlpha ? self::FEATURE_ALPHA : self::FEATURE_BETA;

        if ($alphaFeature === $betaFeature) {
            throw new \RuntimeException(sprintf(
                "Both agents started the same feature '%s': board corruption detected.\nAgent alpha output: %s\nAgent beta output: %s",
                $alphaFeature,
                $outAlpha,
                $outBeta,
            ));
        }
    }
}
