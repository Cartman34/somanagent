<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Backlog\Command\BacklogReviewNotesCommand;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
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

        // review-list exposes the stable reference for entries waiting in review
        $reviewListOutput = $driver->reviewList();
        $driver->assertOutputContainsAll($reviewListOutput, [
            '- ' . $context->fixFeature . ' ',
            'kind=feature',
            'agent=' . $context->agentPrimary,
        ]);

        // review-next with an unknown explicit target refuses without claiming anything
        $driver->assertReviewNextFails(
            $context->agentSecondary,
            'No active entry matches reference "unknown-feature-target"',
            'unknown-feature-target',
        );

        // review-next claims the entry by explicit reference and transitions it to reviewing
        $reviewNextOutput = $driver->reviewNext($context->agentSecondary, $context->fixFeature);
        if (!str_contains($reviewNextOutput, $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next to return the active feature review.');
        }
        if (!str_contains($reviewNextOutput, 'Stage: Reviewing')) {
            throw new \RuntimeException('Expected review-next output to show Stage: Reviewing.');
        }
        if (!str_contains($reviewNextOutput, 'Reviewer: ' . $context->agentSecondary)) {
            throw new \RuntimeException('Expected review-next output to show Reviewer: ' . $context->agentSecondary);
        }

        // reviewer already has a reviewing entry — refusing a second claim is expected
        $driver->assertReviewNextFails($context->agentSecondary, 'already has an entry in Reviewing');

        // another reviewer targeting the already-claimed entry is refused with a clear message
        $driver->assertReviewNextFails(
            'test-r-other',
            'is already in Reviewing',
            $context->fixFeature,
        );

        // feature-list should show the reviewing entry with reviewer field
        $featureListOutput = $driver->runBacklog(['feature-list']);
        if (!str_contains($featureListOutput, 'reviewer=' . $context->agentSecondary)) {
            throw new \RuntimeException('Expected feature-list to show reviewer=' . $context->agentSecondary);
        }

        // review-cancel requires an explicit reference; never auto-resolves by agent
        $driver->assertReviewCancelFails($context->agentSecondary, '', 'review-cancel requires an explicit <feature> or <feature/task> reference.');
        // review-cancel releases the entry back to review
        $driver->reviewCancel($context->agentSecondary, $context->fixFeature);
        $driver->assertReviewCancelFails($context->agentSecondary, $context->fixFeature, 'Reviewing');

        // re-claim and do the full reject cycle via unified commands
        $driver->reviewNext($context->agentSecondary);
        // legacy command names must fall through to the standard unknown-command error
        $driver->assertCommandIsUnknown('feature-review-check');
        $driver->assertCommandIsUnknown('feature-review-reject');
        $driver->assertCommandIsUnknown('feature-review-approve');
        $driver->reviewCheck($context->agentSecondary, $context->fixFeature);
        $driver->assertReviewRejectFails($context->agentSecondary, $context->fixFeature, $invalidRejectBody, 'Review body items must be plain findings');
        $driver->rejectReviewViaUnifiedCommand($context->agentSecondary, $context->fixFeature, $rejectBody);
        $driver->assertReviewContains($context->fixFeature);
        $this->assertReviewNotesForFeature($driver, $context, '1. Reject feature review for workflow coverage.');
        $driver->rework($context->agentPrimary, $context->fixFeature);
        $driver->requestFeatureReview($context->agentPrimary);

        // unified commands: reviewer required and body-file required guards
        $driver->assertReviewCheckFails('', $context->fixFeature, 'review-check requires --agent=<reviewer>.');
        $driver->assertReviewRejectFails($context->agentSecondary, $context->fixFeature, null, 'review-reject requires --body-file=<path>.');
        $driver->assertReviewApproveFails($context->agentSecondary, $context->fixFeature, null, 'Option --body-file is required.');

        // approve path via unified commands (without review-next, entry stays in review — commands accept both stages)
        $driver->reviewCheck($context->agentSecondary, $context->fixFeature);
        $driver->approveFeatureViaUnifiedCommand($context->agentSecondary, $context->fixFeature, $approveBody);
        $driver->blockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertStatusContains($context->fixFeature, 'Blocker: blocked');
        $driver->unblockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertFeatureMergeIsDeprecated($context->fixFeature);
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
