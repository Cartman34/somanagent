<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Backlog\Command\BacklogReviewNotesCommand;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Feature review lifecycle campaign
 *
 * Tests the complete workflow of feature reviews including reject, rework, approve, block, and merge.
 */
final class FeatureReviewLifecycleCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'feature-review-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->createTodoTask(sprintf('[fix] %s', $context->fixFeature));
        $driver->startNextFeature($context->agentPrimary);
        $driver->trackFeatureBranch($context->fixFeature);
        $driver->commitFeatureChange($context->agentPrimary, $context->fixFeature, 'test-feature-review-lifecycle.txt');
        $driver->createRemoteTestBaseBranch();

        $rejectBody = $driver->createBodyFile('test-feature-review-reject.md', ['1. Reject feature review for workflow coverage.']);
        $invalidRejectBody = $driver->createBodyFile('test-feature-review-invalid.md', ['1. ### Revue de la feature']);
        $approveBody = $driver->createBodyFile('test-feature-review-approve.md', ['1. Approve feature review for workflow coverage.']);

        $driver->requestFeatureReview($context->agentPrimary);
        if (!str_contains($driver->reviewNext(), $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next to return the active feature review.');
        }
        $driver->checkFeatureReview($context->fixFeature);
        $driver->assertFeatureReviewRejectFails($context->fixFeature, $invalidRejectBody, 'Review body items must be plain findings');
        $driver->rejectFeatureReview($context->fixFeature, $rejectBody);
        $driver->assertReviewContains($context->fixFeature);
        $this->assertReviewNotesForFeature($driver, $context, '1. Reject feature review for workflow coverage.');
        $driver->rework($context->agentPrimary, $context->fixFeature);
        $driver->requestFeatureReview($context->agentPrimary);
        $driver->approveFeature($context->fixFeature, $approveBody);
        $driver->blockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertStatusContains($context->fixFeature, 'Blocker: blocked');
        $driver->unblockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertFeatureMergeBodyFileWithoutValueFails($context->fixFeature);
        $driver->mergeFeature($context->fixFeature);
        $driver->assertActiveFeatureMissing($context->fixFeature);
    }

    /**
     * Assert review-notes prints the documented protected, read-only block for a rejected feature,
     * resolving via positional reference and via --agent. Also confirms the status hint is appended
     * for the active entry without printing the notes themselves.
     */
    private function assertReviewNotesForFeature(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
        string $expectedNoteLine,
    ): void {
        $byRef = $driver->reviewNotes(null, $context->fixFeature);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_TITLE);
        $driver->assertContains($byRef, 'Target: ' . $context->fixFeature);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_WARNING);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_FENCE_OPEN);
        $driver->assertContains($byRef, $expectedNoteLine);
        if (!str_ends_with(rtrim($byRef), BacklogReviewNotesCommand::BLOCK_END_MARKER)) {
            throw new \RuntimeException('Protected block must end with the read-only marker on its own line:' . "\n" . $byRef);
        }

        $byAgent = $driver->reviewNotes($context->agentPrimary, null);
        $driver->assertContains($byAgent, 'Target: ' . $context->fixFeature);
        $driver->assertContains($byAgent, $expectedNoteLine);

        $driver->assertStatusContains($context->agentPrimary, 'Review notes: stored', true);
        $driver->assertStatusContains($context->agentPrimary, 'review-notes ' . $context->fixFeature, true);
        $statusOutput = $driver->status($context->agentPrimary, true);
        if (str_contains($statusOutput, $expectedNoteLine)) {
            throw new \RuntimeException('Status output unexpectedly contains the review notes themselves: ' . $statusOutput);
        }
    }
}
