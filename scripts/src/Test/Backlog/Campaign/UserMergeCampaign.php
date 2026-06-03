<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Test\Backlog\Campaign;

use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;
use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;

/**
 * User-merge command campaign.
 *
 * Tests the interactive `user-merge` command that lets the user merge approved entries
 * without going through an agent reviewer session.
 */
final class UserMergeCampaign implements CampaignInterface
{
    /**
     * Feature used in Phase C (stage-manipulated approved entry, no commit).
     */
    private const FEATURE_C = 'test-um-feature-c';

    /**
     * Feature and task used in Phase D (full lifecycle, real commits).
     */
    private const FEATURE_D = 'test-um-feature-d';

    private const TASK_D = 'test-um-task-d';

    private const TASK_D_REF = self::FEATURE_D . '/' . self::TASK_D;

    private const TASK_D_BRANCH = 'feat/' . self::FEATURE_D . '--' . self::TASK_D;

    private const REVIEWER = 'test-r-um';

    private const COMMAND = 'user-merge';

    public function getName(): string
    {
        return self::COMMAND;
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $this->testZeroApproved($driver, $context);
        // Non-TTY refusal runs inside Phase C (requires at least one approved entry to pass
        // the early-exit guard before the TTY check fires).
        $this->setupPhaseCAndRunSimpleTests($driver, $context);

        if (!$context->dryRun) {
            $this->setupPhaseDAndRunMergeTests($driver, $context);
            $driver->closeFeature(self::FEATURE_D);
        }

        // feature-c was set to approved via board manipulation and skipped by user-merge; close it to leave a clean board.
        $driver->closeFeature(self::FEATURE_C);
    }

    /**
     * Verifies behavior when no entries are in approved stage.
     */
    private function testZeroApproved(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        // user-merge → "No approved entries waiting."
        [$exitCode, $output] = $driver->runBacklogWithPipedStdin([self::COMMAND], '');
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "user-merge with zero approved entries must exit 0, got %d.\n--- output ---\n%s",
                $exitCode,
                $output,
            ));
        }
        $driver->assertContains($output, 'No approved entries waiting.');

        // list must not show the footer when N=0
        $listOutput = $driver->runBacklog(['list']);
        if (str_contains($listOutput, 'Approved entries waiting')) {
            throw new \RuntimeException("list must not show approved footer when N=0.\n--- output ---\n{$listOutput}");
        }
    }

    /**
     * Sets up Phase C (approved feature via board manipulation) and runs non-merge tests.
     *
     * Phase C: createTodoTask + startNextFeature → set stage to approved via replaceBoardText.
     * No real commits are needed since these tests do not perform actual merges.
     * The non-TTY refusal test runs here because it requires at least one approved entry
     * to bypass the early zero-entries exit before the TTY check fires.
     */
    private function setupPhaseCAndRunSimpleTests(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $featureC = self::FEATURE_C;
        $driver->createTodoTask(sprintf('[tech][%s] Test user-merge feature C', $featureC));
        $driver->startNextFeature($context->agentPrimary);
        $driver->trackFeatureBranch($featureC);

        $driver->replaceBoardText(
            "    stage: development\n    feature: {$featureC}",
            "    stage: approved\n    feature: {$featureC}",
        );
        $driver->assertFeatureStage($featureC, BacklogBoard::STAGE_APPROVED);

        // Non-TTY refusal: piped stdin (not a TTY), no BACKLOG_TEST_FORCE_INTERACTIVE → refusal
        $driver->assertUserMergeWithPipedStdinFails('', 'requires an interactive terminal');

        // status footer present (N=1)
        $statusOutput = $driver->runBacklog(['status', $featureC]);
        $driver->assertContains($statusOutput, 'Approved entries waiting: 1');

        // --dry-run: preview shown, no merge, exit 0
        [$exitCode, $dryOutput] = $driver->runBacklogWithPipedStdin([self::COMMAND, '--dry-run'], '');
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "user-merge --dry-run must exit 0, got %d.\n--- output ---\n%s",
                $exitCode,
                $dryOutput,
            ));
        }
        $driver->assertContains($dryOutput, $featureC);

        if (!$context->dryRun) {
            // input n → skip, entry stays approved
            $interactiveEnv = ['BACKLOG_TEST_FORCE_INTERACTIVE' => '1'];
            [$exitCode, $nOutput] = $driver->runBacklogWithPipedStdin([self::COMMAND], "n\n", $interactiveEnv);
            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    "user-merge with input 'n' must exit 0, got %d.\n--- output ---\n%s",
                    $exitCode,
                    $nOutput,
                ));
            }
            $driver->assertContains($nOutput, 'Skipped: ' . $featureC);
            $driver->assertFeatureStage($featureC, BacklogBoard::STAGE_APPROVED);

            // input q → quit immediately, entry stays approved
            [$exitCode, $qOutput] = $driver->runBacklogWithPipedStdin([self::COMMAND], "q\n", $interactiveEnv);
            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    "user-merge with input 'q' must exit 0, got %d.\n--- output ---\n%s",
                    $exitCode,
                    $qOutput,
                ));
            }
            $driver->assertFeatureStage($featureC, BacklogBoard::STAGE_APPROVED);
        }
    }

    /**
     * Full lifecycle for Phase D: task created, committed, reviewed, approved.
     * Tests merge error, then successful merge with d→y input on the second entry.
     */
    private function setupPhaseDAndRunMergeTests(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $featureD = self::FEATURE_D;
        $taskD = self::TASK_D;
        $taskDRef = self::TASK_D_REF;
        $interactiveEnv = ['BACKLOG_TEST_FORCE_INTERACTIVE' => '1'];

        $driver->createTodoTask(sprintf('[%s][%s] Test user-merge task merge', $featureD, $taskD));
        $driver->startNextFeature($context->agentSecondary);
        $driver->commitFeatureChange($context->agentSecondary, $featureD, 'test-um-merge-artifact.txt');
        $driver->requestTaskReview($context->agentSecondary);
        $driver->reviewNext(self::REVIEWER, $taskDRef);
        $driver->approveTaskViaUnifiedCommand(self::REVIEWER, $taskDRef);
        $driver->assertTaskStage($taskDRef, BacklogBoard::STAGE_APPROVED);
        $driver->trackFeatureBranch($featureD);

        // Merge error: corrupt task branch → user-merge + 'n\ny\n' → fail on Phase D
        $driver->replaceBoardText(
            'branch: ' . self::TASK_D_BRANCH,
            'branch: feat/nonexistent-xyz-branch-um',
        );
        $driver->assertUserMergeWithPipedStdinFails(
            "n\ny\n",
            'feat/nonexistent-xyz-branch-um',
            $interactiveEnv,
        );
        // Restore the correct branch
        $driver->replaceBoardText(
            'branch: feat/nonexistent-xyz-branch-um',
            'branch: ' . self::TASK_D_BRANCH,
        );
        $driver->assertTaskStage($taskDRef, BacklogBoard::STAGE_APPROVED);

        // Successful merge: input 'n\nd\ny\n'
        //   → n for Phase C feature (skip)
        //   → d for Phase D task (show full diff)
        //   → y for Phase D task (merge)
        [$exitCode, $mergeOutput] = $driver->runBacklogWithPipedStdin(
            [self::COMMAND],
            "n\nd\ny\n",
            $interactiveEnv,
        );
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "user-merge with input 'n\\nd\\ny\\n' must exit 0, got %d.\n--- output ---\n%s",
                $exitCode,
                $mergeOutput,
            ));
        }
        $driver->assertContains($mergeOutput, 'Merging ' . $taskDRef);
        // The feature body retains the task reference as description; only check the active task entry field.
        $driver->assertBoardLacksText('task: ' . $taskD);
        $driver->assertBoardContains(self::FEATURE_C);
    }
}
