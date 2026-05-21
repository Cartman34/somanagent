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
    private const UNKNOWN_ENTRY_REF = 'unknown-feature-target';

    public function getName(): string
    {
        return 'feature-review-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->createTodoTask(sprintf('[fix][%s] %s', $context->fixFeature, $context->fixFeature));
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertContains($startOutput, 'Entry-ref: ' . $context->fixFeature);
        $driver->assertContains($startOutput, 'Branch: fix/' . $context->fixFeature);
        $driver->trackFeatureBranch($context->fixFeature);
        $driver->commitFeatureChange($context->agentPrimary, $context->fixFeature, 'test-feature-review-lifecycle.txt');
        $driver->createRemoteTestBaseBranch();

        $rejectBody = $driver->createBodyFile('test-feature-review-reject.md', ['1. Reject feature review for workflow coverage.']);
        $invalidRejectBody = $driver->createBodyFile('test-feature-review-invalid.md', ['### Revue de la feature']);
        $approveBody = $driver->createBodyFile('test-feature-review-approve.md', ['1. Approve feature review for workflow coverage.']);

        $driver->requestFeatureReview($context->agentPrimary);

        // list --stage=review exposes the stable reference for entries waiting in review
        $reviewListOutput = $driver->reviewList();
        $driver->assertOutputContainsAll($reviewListOutput, [
            '- ' . $context->fixFeature . ' ',
            'kind=feature',
            'developer=' . $context->agentPrimary,
        ]);

        // review-next with an unknown explicit target refuses without claiming anything
        $driver->assertReviewNextFails(
            $context->agentSecondary,
            'No active entry matches reference "' . self::UNKNOWN_ENTRY_REF . '"',
            self::UNKNOWN_ENTRY_REF,
        );

        // review-next claims the entry by explicit reference and transitions it to reviewing
        $reviewNextOutput = $driver->reviewNext($context->agentSecondary, $context->fixFeature);
        if (!str_contains($reviewNextOutput, $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next to return the active feature review.');
        }
        if (!str_contains($reviewNextOutput, 'Entry-ref: ' . $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next output to show Entry-ref: ' . $context->fixFeature);
        }
        if (!str_contains($reviewNextOutput, 'Branch: fix/' . $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next output to keep Branch: fix/' . $context->fixFeature);
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

        // list should show the reviewing entry with reviewer field
        $featureListOutput = $driver->runBacklog(['list']);
        if (!str_contains($featureListOutput, 'reviewer=' . $context->agentSecondary)) {
            throw new \RuntimeException('Expected list to show reviewer=' . $context->agentSecondary);
        }

        // review-cancel requires an explicit reference; never auto-resolves by agent
        $driver->assertReviewCancelFails($context->agentSecondary, '', 'review-cancel requires an explicit <entry-ref> reference.');
        // review-cancel releases the entry back to review
        $driver->reviewCancel($context->agentSecondary, $context->fixFeature);
        $driver->assertReviewCancelFails($context->agentSecondary, $context->fixFeature, 'Reviewing');

        // re-claim and do the full reject cycle via unified commands
        $driver->reviewNext($context->agentSecondary);
        // legacy command names must fall through to the standard unknown-command error
        $driver->assertCommandIsUnknown('feature-review-check');
        $driver->assertCommandIsUnknown('feature-review-reject');
        $driver->assertCommandIsUnknown('feature-review-approve');
        $reviewCheckOutput = $driver->reviewCheck($context->agentSecondary, $context->fixFeature);
        $driver->assertContains($reviewCheckOutput, 'Entry-ref: ' . $context->fixFeature);
        $driver->assertContains($reviewCheckOutput, 'Branch: fix/' . $context->fixFeature);
        $driver->assertContains($reviewCheckOutput, 'Mechanical review status: PASS');
        $driver->assertContains($reviewCheckOutput, 'Review report saved to local/backlog-review-result.txt');
        $driver->assertReviewRejectFails($context->agentSecondary, $context->fixFeature, $invalidRejectBody, 'Review body items must be plain findings');
        $driver->rejectReviewViaUnifiedCommand($context->agentSecondary, $context->fixFeature, $rejectBody);
        $driver->assertReviewContains($context->fixFeature);
        $this->assertReviewNotesForFeature($driver, $context, '1. Reject feature review for workflow coverage.');

        // review-amend: replace notes on a rejected feature (absolute body-file path in WP local/tmp)
        $amendedBody = $driver->createBodyFile('test-feature-review-amend.md', ['1. Amended finding for workflow coverage.']);
        $driver->reviewAmend($context->agentSecondary, $context->fixFeature, $amendedBody);
        $driver->assertReviewContains('Amended finding for workflow coverage.');
        $driver->assertReviewMissing('Reject feature review for workflow coverage.');
        $this->assertReviewNotesForFeature($driver, $context, '1. Amended finding for workflow coverage.');
        // stage must still be rejected after amend
        $driver->assertStatusContains($context->fixFeature, 'Stage: Rejected');

        // review-amend with WA body file: resolver derives WA from entry meta.agent and finds the file
        $waLocalTmp = $driver->managedWorktreePath($context->agentPrimary) . '/local/tmp';
        @mkdir($waLocalTmp, 0777, true);
        $waBodyRelPath = 'local/tmp/test-feature-review-amend-wa.md';
        file_put_contents($waLocalTmp . '/test-feature-review-amend-wa.md', "1. Amended from WA body file.\n");
        $driver->reviewAmend($context->agentSecondary, $context->fixFeature, $waBodyRelPath);
        $driver->assertReviewContains('Amended from WA body file.');
        $driver->assertReviewMissing('Amended finding for workflow coverage.');

        // review-amend: wrong role is refused
        $driver->assertReviewAmendFails($context->agentSecondary, $context->fixFeature, $amendedBody, 'review-amend is restricted to the reviewer role', ['SOMANAGER_ROLE' => 'developer']);
        // review-amend: missing body-file is refused
        $driver->assertReviewAmendFails($context->agentSecondary, $context->fixFeature, null, 'review-amend requires --body-file=<path>.');
        // review-amend: missing entry-ref is refused
        $driver->assertReviewAmendFails($context->agentSecondary, '', $amendedBody, 'review-amend requires <entry-ref>.');
        // review-amend: unknown entry-ref is refused
        $driver->assertReviewAmendFails($context->agentSecondary, 'unknown-feature-slug', $amendedBody, 'Feature not found:');

        $driver->rework($context->agentPrimary, $context->fixFeature);
        $driver->requestFeatureReview($context->agentPrimary);

        // review-amend: non-rejected entry (now in review stage) is refused
        $driver->assertReviewAmendFails($context->agentSecondary, $context->fixFeature, $amendedBody, 'must be in');

        // unified commands: reviewer required and body-file required guards
        $driver->assertReviewCheckFails('', $context->fixFeature, 'Command requires SOMANAGER_AGENT=<code>.');
        $driver->assertReviewRejectFails($context->agentSecondary, $context->fixFeature, null, 'review-reject requires --body-file=<path>.');
        $driver->assertReviewApproveFails($context->agentSecondary, $context->fixFeature, null, 'review-approve requires --body-file=<path> for feature approvals.');

        // approve path via unified commands (without review-next, entry stays in review — commands accept both stages)
        $driver->reviewCheck($context->agentSecondary, $context->fixFeature);
        $driver->approveFeatureViaUnifiedCommand($context->agentSecondary, $context->fixFeature, $approveBody);
        $driver->blockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertStatusContains($context->fixFeature, 'Blocker: blocked');
        $driver->unblockFeature($context->agentPrimary, $context->fixFeature);

        // Save the board state at approved stage to simulate a retry after a partial failure.
        $boardSnapshotBeforeMerge = $driver->getBoardText();

        $driver->mergeFeature($context->fixFeature);
        $driver->assertActiveFeatureMissing($context->fixFeature);

        // Idempotent retry scenario: restore the board to approved stage (simulates a crash
        // that happened after the GitHub PR was merged but before the board was saved).
        // The retry must complete without error and clear the board entry.
        $driver->setBoardText($boardSnapshotBeforeMerge);
        $driver->assertActiveFeatureExists($context->fixFeature);
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
