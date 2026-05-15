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
 * Scoped task lifecycle campaign
 *
 * Tests the workflow of scoped tasks within a feature, including child task review and approval.
 */
final class ScopedTaskLifecycleCampaign implements CampaignInterface
{
    private const MANAGER_AGENT = 'test-m01';

    public function getName(): string
    {
        return 'scoped-task-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $taskARef = $context->scopedFeature . '/' . $context->childA;
        $taskBRef = $context->scopedFeature . '/' . $context->childB;

        $driver->createTodoTask(sprintf('[%s][%s] Implement test child task A', $context->scopedFeature, $context->childA));
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertFeatureStartOutputContains($startOutput, '[Task]');
        $driver->assertFeatureStartOutputContains($startOutput, 'Task: ' . $context->childA);
        $driver->assertFeatureStartOutputContains($startOutput, '[Feature]');
        $driver->assertFeatureStartOutputContains($startOutput, 'Feature: ' . $context->scopedFeature);
        $driver->assertFeatureStartOutputContains($startOutput, '[Worktree]');
        $driver->assertFeatureStartOutputContains($startOutput, $context->agentPrimary);
        $driver->assertActiveFeatureExists($context->scopedFeature);
        $driver->assertStatusContains($context->scopedFeature, $context->childA);

        $renamedTaskText = 'Renamed child task A after start';
        $driver->renameEntry($context->agentPrimary, $renamedTaskText);
        $driver->assertStatusContains($context->agentPrimary, $renamedTaskText, true);
        $driver->assertStatusContains($context->scopedFeature, $renamedTaskText);

        $rejectBody = $driver->createBodyFile('test-task-review-reject.md', ['1. Reject child task for test workflow.']);
        $invalidRejectBody = $driver->createBodyFile('test-task-review-invalid.md', ['### Task review']);

        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_PROGRESS);
        $driver->assertReworkFails($context->agentPrimary, $taskARef, 'rework only accepts');
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_PROGRESS);

        $driver->requestTaskReview($context->agentPrimary);
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_REVIEW);
        $driver->assertReworkFails($context->agentPrimary, $taskARef, 'rework only accepts');
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_REVIEW);

        if (!str_contains($driver->reviewNext($context->agentSecondary), $taskARef)) {
            throw new \RuntimeException('Expected review-next to return the active task review.');
        }
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_REVIEWING);
        // legacy command names must fall through to the standard unknown-command error
        $driver->assertCommandIsUnknown('task-review-check');
        $driver->assertCommandIsUnknown('task-review-reject');
        $driver->assertCommandIsUnknown('task-review-approve');
        $driver->reviewCheck($context->agentSecondary, $taskARef);
        $driver->assertReviewRejectFails($context->agentSecondary, $taskARef, $invalidRejectBody, 'Review body items must be plain findings');
        $driver->rejectReviewViaUnifiedCommand($context->agentSecondary, $taskARef, $rejectBody);
        $driver->assertReviewContains($taskARef);
        $this->assertReviewNotesForTask($driver, $context, $taskARef, '1. Reject child task for test workflow.');

        // review-amend nominal on a task entry: replace notes, stage stays rejected
        $amendedTaskBody = $driver->createBodyFile('test-task-review-amend.md', ['1. Amended finding for task review coverage.']);
        $driver->reviewAmend($context->agentSecondary, $taskARef, $amendedTaskBody);
        $driver->assertReviewContains('Amended finding for task review coverage.');
        $driver->assertReviewMissing('Reject child task for test workflow.');
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_REJECTED);

        $driver->rework($context->agentPrimary, $taskARef);
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_PROGRESS);
        $driver->requestTaskReview($context->agentPrimary);

        // unified review-check: short task slug (childA without feature prefix) must be refused
        $driver->assertReviewCheckFails($context->agentSecondary, $context->childA, 'refuses short task reference');
        // unified commands: approve task via review-check + review-approve
        $driver->reviewCheck($context->agentSecondary, $taskARef);
        $driver->approveTaskViaUnifiedCommand($context->agentSecondary, $taskARef);
        $driver->assertReviewMissing($taskARef);
        $this->assertReviewNotesAbsentForTask($driver, $taskARef);

        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_APPROVED);
        $this->assertReviewReopenForTask($driver, $context, $taskARef);
        $driver->assertEntryMergeRequiresReviewer($taskARef);
        $driver->assertEntryMergeWithoutReferenceFails($context->agentPrimary);
        $driver->assertEntryMergeShortTaskReferenceFails($context->childA);
        $taskMergeBody = $driver->createBodyFile('test-entry-merge-task-body.md', ['Task merges do not accept PR body files.']);
        $driver->assertEntryMergeTaskBodyFileFails($taskARef, $taskMergeBody);
        // Auto-resolve path: SOMANAGER_AGENT without explicit reference must pick the single approved entry.
        $reworkApprovedOutput = $driver->runBacklog(['rework'], ['SOMANAGER_AGENT' => $context->agentPrimary]);
        $driver->assertContains($reworkApprovedOutput, 'moved back to In development from Approved');
        $driver->assertTaskStage($taskARef, BacklogBoard::STAGE_IN_PROGRESS);
        $driver->requestTaskReview($context->agentPrimary);
        $driver->approveTaskViaUnifiedCommand($context->agentSecondary, $taskARef);

        // status <entry-ref> must resolve the task entry directly.
        $driver->assertStatusContains($taskARef, 'Kind: task');
        $driver->assertStatusContains($taskARef, 'Ref: ' . $taskARef);
        // feature-list must show kind=task and full <entry-ref> for task entries.
        $featureListOutput = $driver->runBacklog(['feature-list']);
        $driver->assertContains($featureListOutput, $taskARef . ' kind=task');
        $driver->assertContains($featureListOutput, $context->scopedFeature . ' kind=feature');

        $driver->mergeTask($taskARef);

        // After task merge, parent must keep no agent (no auto-assignment).
        $driver->assertStatusContains($context->scopedFeature, 'Agent: none');

        $driver->createTodoTask(sprintf('[%s][%s] Implement test child task B', $context->scopedFeature, $context->childB));
        $driver->startNextFeature($context->agentPrimary);

        $rejectFeatureTaskB = $driver->createBodyFile('test-task-review-reject-b.md', ['3. Reject second child task for coverage.']);
        $approveFeatureWithActiveTask = $driver->createBodyFile('test-feature-review-approve-active-task.md', ['Approve parent feature should be blocked by active child task.']);
        // unified review-approve still validates the active-task guard on a parent feature
        $driver->assertReviewApproveFails(
            $context->agentSecondary,
            $context->scopedFeature,
            $approveFeatureWithActiveTask,
            sprintf('cannot continue while feature %s still has active task branches.', $context->scopedFeature),
        );
        $driver->requestTaskReview($context->agentPrimary);
        // unified review-reject for task covers the task delegation path
        $driver->rejectReviewViaUnifiedCommand($context->agentSecondary, $taskBRef, $rejectFeatureTaskB);
        $driver->assertReviewContains('3. Reject second child task for coverage.');
        $driver->rework($context->agentPrimary, $taskBRef);
        $driver->requestTaskReview($context->agentPrimary);
        $driver->approveTaskViaUnifiedCommand($context->agentSecondary, $taskBRef);
        $driver->mergeTaskWithLegacyCommand($taskBRef);
        $driver->mergeTask($taskBRef);

        $driver->closeFeature($context->scopedFeature);
        $driver->assertActiveFeatureMissing($context->scopedFeature);

        $this->assertParentAssignmentPreservedOnTaskMerge($driver, $context);
        $this->assertEntryUnassignForTaskAndAmbiguity($driver, $context);
        $this->assertReviewNotesAmbiguityAndMissingReference($driver, $context);
    }

    /**
     * Exercises review-reopen for a task entry.
     *
     * Entry must be in APPROVED when called; leaves it in APPROVED on exit so the
     * surrounding campaign can continue with the standard rework-from-approved path.
     */
    private function assertReviewReopenForTask(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
        string $taskRef,
    ): void {
        $manager = self::MANAGER_AGENT;
        $reviewer = $context->agentSecondary;

        // Refusal: missing entry-ref
        $driver->assertReviewReopenFails('manager', $manager, '', 'review-reopen requires an explicit <entry-ref>');

        // Refusal: valid format but non-existent reference
        $driver->assertReviewReopenFails('manager', $manager, 'no-such-feature', 'No active entry found for reference');

        // Refusal: wrong SOMANAGER_ROLE (developer is not allowed)
        $driver->assertReviewReopenFails('developer', $context->agentPrimary, $taskRef, 'review-reopen requires SOMANAGER_ROLE=manager or SOMANAGER_ROLE=reviewer');

        // Manager path: approved → review, meta.reviewer cleared
        $driver->reviewReopen('manager', $manager, $taskRef);
        $driver->assertTaskStage($taskRef, BacklogBoard::STAGE_IN_REVIEW);

        // Refusal: stage is now review, not approved
        $driver->assertReviewReopenFails('manager', $manager, $taskRef, 'review-reopen only accepts Approved');

        // Re-approve to restore approved state for the reviewer path
        $driver->approveTaskViaUnifiedCommand($reviewer, $taskRef);
        $driver->assertTaskStage($taskRef, BacklogBoard::STAGE_APPROVED);

        // Reviewer path: approved → reviewing, meta.reviewer = reviewer agent
        $driver->reviewReopen('reviewer', $reviewer, $taskRef);
        $driver->assertTaskStage($taskRef, BacklogBoard::STAGE_REVIEWING);

        // Verify meta.reviewer is set to reviewer by confirming review-cancel succeeds for that reviewer
        $driver->reviewCancel($reviewer, $taskRef);
        $driver->assertTaskStage($taskRef, BacklogBoard::STAGE_IN_REVIEW);

        // Re-approve: leaves the entry in APPROVED for the rest of the campaign
        $driver->approveTaskViaUnifiedCommand($reviewer, $taskRef);
        $driver->assertTaskStage($taskRef, BacklogBoard::STAGE_APPROVED);
    }

    /**
     * Create a scoped task whose parent feature is manually pre-assigned to a non-task-developer agent,
     * merge the task, and verify that the pre-existing assignment is preserved (no overwrite on merge).
     */
    private function assertParentAssignmentPreservedOnTaskMerge(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
    ): void {
        $feature = 'test-preserve-parent-assign';
        $task = 'test-preserve-task';
        $taskRef = $feature . '/' . $task;

        $driver->createTodoTask(sprintf('[%s][%s] Task for parent-assignment-preservation test', $feature, $task));
        $driver->startNextFeature($context->agentPrimary);

        // Simulate a feature container that was explicitly assigned to a different agent
        // before the task merge. The parent has no `agent:` line when unset, so we insert
        // one between `feature:` and `branch:` (which is unique to the feature meta block).
        $driver->replaceBoardText(
            sprintf("    feature: %s\n    branch:", $feature),
            sprintf("    feature: %s\n    agent: %s\n    branch:", $feature, $context->agentSecondary),
        );

        $driver->requestTaskReview($context->agentPrimary);
        $driver->approveTaskViaUnifiedCommand($context->agentSecondary, $taskRef);
        $driver->mergeTask($taskRef);

        // The pre-existing assignment on the parent must survive the task merge.
        $driver->assertStatusContains($feature, 'Agent: ' . $context->agentSecondary);

        $driver->closeFeature($feature);
    }

    /**
     * Assert review-notes prints the documented protected, read-only block for a rejected task,
     * resolves both via positional reference and via --agent, and respects the exact format.
     */
    private function assertReviewNotesForTask(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
        string $taskRef,
        string $expectedNoteLine,
    ): void {
        $byRef = $driver->reviewNotes(null, $taskRef);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_TITLE);
        $driver->assertContains($byRef, 'Target: ' . $taskRef);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_WARNING);
        $driver->assertContains($byRef, BacklogReviewNotesCommand::BLOCK_FENCE_OPEN);
        $driver->assertContains($byRef, $expectedNoteLine);

        $titlePos = strpos($byRef, BacklogReviewNotesCommand::BLOCK_TITLE);
        $warningPos = strpos($byRef, BacklogReviewNotesCommand::BLOCK_WARNING);
        $fencePos = strpos($byRef, BacklogReviewNotesCommand::BLOCK_FENCE_OPEN);
        $notePos = strpos($byRef, $expectedNoteLine);
        $endPos = strrpos($byRef, BacklogReviewNotesCommand::BLOCK_END_MARKER);

        if ($titlePos === false || $warningPos === false || $fencePos === false || $notePos === false || $endPos === false) {
            throw new \RuntimeException('Protected block markers missing in review-notes output:' . "\n" . $byRef);
        }
        if (!($titlePos < $warningPos && $warningPos < $fencePos && $fencePos < $notePos && $notePos < $endPos)) {
            throw new \RuntimeException('Protected block markers in unexpected order in review-notes output:' . "\n" . $byRef);
        }
        if (!str_ends_with(rtrim($byRef), BacklogReviewNotesCommand::BLOCK_END_MARKER)) {
            throw new \RuntimeException('Protected block must end with the read-only marker on its own line:' . "\n" . $byRef);
        }

        $byAgent = $driver->reviewNotes($context->agentPrimary, null);
        $driver->assertContains($byAgent, 'Target: ' . $taskRef);
        $driver->assertContains($byAgent, $expectedNoteLine);

        $driver->assertStatusContains($context->agentPrimary, 'Review notes: stored', true);
        $driver->assertStatusContains($context->agentPrimary, 'review-notes ' . $taskRef, true);
        $statusOutput = $driver->status($context->agentPrimary, true);
        if (str_contains($statusOutput, $expectedNoteLine)) {
            throw new \RuntimeException('Status output unexpectedly contains the review notes themselves: ' . $statusOutput);
        }
    }

    /**
     * Assert review-notes still prints the protected block but with the absent-notes line for an approved task.
     */
    private function assertReviewNotesAbsentForTask(BacklogScriptTestDriver $driver, string $taskRef): void
    {
        $output = $driver->reviewNotes(null, $taskRef);
        $driver->assertContains($output, BacklogReviewNotesCommand::BLOCK_TITLE);
        $driver->assertContains($output, 'Target: ' . $taskRef);
        $driver->assertContains($output, 'No review notes stored for ' . $taskRef);
        $driver->assertContains($output, BacklogReviewNotesCommand::BLOCK_END_MARKER);
    }

    /**
     * After the primary scoped feature is closed, set up an ambiguous slug pair (active feature + active task
     * sharing the same slug) and verify review-notes refuses to resolve it. Also check the missing-reference
     * and missing-target failure paths. Cleans up created entries to avoid leaking state to other campaigns.
     */
    private function assertReviewNotesAmbiguityAndMissingReference(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
    ): void {
        $driver->assertReviewNotesFails(null, 'rn-does-not-exist', 'No active entry found for reference: rn-does-not-exist');
        $driver->assertReviewNotesFails(null, null, 'review-notes requires either --agent=<code> or a reference');

        $ambSlug = 'test-rn-ambiguous';
        $ambFeatureContainer = 'test-rn-amb-container';

        $driver->createTodoTask(sprintf('[%s][%s] Scoped task for ambiguity coverage', $ambFeatureContainer, $ambSlug));
        $driver->startNextFeature($context->agentPrimary);

        $driver->createTodoTask(sprintf('[%s] Plain feature whose slug collides with the scoped task', $ambSlug));
        $driver->startNextFeature($context->agentSecondary);

        $driver->assertReviewNotesFails(
            null,
            $ambSlug,
            sprintf('Ambiguous reference %s: matches both a feature and a task.', $ambSlug),
        );

        // Cleanup so the next campaign starts from a clean board.
        $driver->releaseFeature($context->agentSecondary, $ambSlug);
        $driver->removeFirstTodoTask();
    }

    /**
     * Verify entry-unassign on a child task across reference forms (`<entry-ref>`,
     * `<task>` simple slug, and ambiguity rejection on a slug colliding with a feature).
     *
     * Runs first so it leaves agentPrimary and agentSecondary free for the review-notes
     * ambiguity coverage that follows.
     */
    private function assertEntryUnassignForTaskAndAmbiguity(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
    ): void {
        $featureSlug = 'test-eu-task-feature';
        $taskSlug = 'test-eu-task';
        $taskRef = $featureSlug . '/' . $taskSlug;

        $driver->createTodoTask(sprintf('[%s][%s] Task for entry-unassign coverage', $featureSlug, $taskSlug));
        $driver->startNextFeature($context->agentPrimary);
        $driver->replaceBoardText(
            '    agent: ' . $context->agentPrimary . "\n",
            "    agent: none\n",
        );
        $driver->removeManagedWorktree($context->agentPrimary);
        $driver->assignFeatureAsManager($taskRef, $context->agentSecondary);
        $driver->assertStatusContains($context->agentSecondary, $taskSlug, true);
        $driver->unassignEntryAsManager($taskRef, self::MANAGER_AGENT);

        $taskOnlyFeature = 'test-eu-task-only-feature';
        $taskOnlySlug = 'test-eu-task-only';
        $driver->createTodoTask(sprintf('[%s][%s] Task for entry-unassign by task slug only', $taskOnlyFeature, $taskOnlySlug));
        $driver->startNextFeature($context->agentPrimary);
        $driver->unassignEntryAsManager($taskOnlySlug, self::MANAGER_AGENT);

        $driver->createTodoTask(sprintf('[%s] Plain feature colliding with the orphaned task slug', $taskSlug));
        $driver->startNextFeature($context->agentSecondary);

        $driver->assertUnassignEntryFails(
            $taskSlug,
            self::MANAGER_AGENT,
            ['SOMANAGER_ROLE' => 'manager'],
            sprintf('Ambiguous reference %s: matches both a feature and a task.', $taskSlug),
        );

        $driver->releaseFeature($context->agentSecondary, $taskSlug);
        $driver->removeFirstTodoTask();
    }
}
