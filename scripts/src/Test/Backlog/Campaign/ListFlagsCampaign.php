<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Tests for the unified `list` command: --stage, --no-stage, --format, --flat,
 * removed commands, approved counter removal, and Pending review label.
 */
final class ListFlagsCampaign implements CampaignInterface
{
    private const REMOVED_TODO_LIST = 'todo-list';
    private const REMOVED_REVIEW_LIST = 'review-list';

    public function getName(): string
    {
        return 'list-flags';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $this->assertRemovedCommands($driver);
        $this->assertStageFilter($driver, $context);
        $this->assertNoStageFilter($driver, $context);
        $this->assertMutualExclusion($driver);
        $this->assertFormats($driver, $context);
        $this->assertFlatFlag($driver, $context);
        $this->assertNoApprovedCounter($driver, $context);
        $this->assertPendingReviewLabel($driver, $context);
    }

    private function assertRemovedCommands(BacklogScriptTestDriver $driver): void
    {
        $driver->assertBacklogFails([self::REMOVED_TODO_LIST], self::REMOVED_TODO_LIST . ' has been removed');
        $driver->assertBacklogFails([self::REMOVED_TODO_LIST], 'list --stage=todo');
        $driver->assertBacklogFails([self::REMOVED_REVIEW_LIST], self::REMOVED_REVIEW_LIST . ' has been removed');
        $driver->assertBacklogFails([self::REMOVED_REVIEW_LIST], 'list --stage=review');
    }

    private function assertStageFilter(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $feature = 'test-list-stage-dev';
        $featureExtra = 'test-list-stage-extra';
        // Two tasks: one will be started (active), one stays queued so [Todo] is non-empty.
        $driver->createTodoTask(sprintf('[%s] Feature for list --stage tests', $feature));
        $driver->createTodoTask(sprintf('[%s] Extra queued feature for Todo section', $featureExtra));
        $driver->startNextFeature($context->agentPrimary);

        // No filter: shows both todo and active stages
        $allOutput = $driver->runBacklog(['list']);
        $driver->assertContains($allOutput, '[Todo]');
        $driver->assertContains($allOutput, '[In development]');

        // Single stage: --stage=development
        $devOutput = $driver->runBacklog(['list', '--stage=development']);
        $driver->assertContains($devOutput, $feature . ' kind=feature');
        if (str_contains($devOutput, '[Todo]')) {
            throw new \RuntimeException('list --stage=development must not show the Todo section');
        }

        // Single stage: --stage=todo shows only queued, not active
        $todoOutput = $driver->runBacklog(['list', '--stage=todo']);
        if (str_contains($todoOutput, '[In development]')) {
            throw new \RuntimeException('list --stage=todo must not show the In development section');
        }

        // Multiple stages via repetition: --stage=todo --stage=development
        $multiOutput = $driver->runBacklog(['list', '--stage=todo', '--stage=development']);
        $driver->assertContains($multiOutput, '[In development]');

        // Multiple stages via CSV: --stage=todo,development
        $csvOutput = $driver->runBacklog(['list', '--stage=todo,development']);
        $driver->assertContains($csvOutput, '[In development]');

        // Unknown stage is rejected
        $driver->assertBacklogFails(['list', '--stage=invalid'], 'Unknown stage');

        // Cleanup: move to review to verify development is empty, then close the feature.
        $driver->requestTaskReview($context->agentPrimary);
        $driver->assertContains($driver->runBacklog(['list', '--stage=development']), 'No entry found');
        $driver->closeFeature($feature);
        $driver->removeFirstTodoTask();
    }

    private function assertNoStageFilter(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $featureA = 'test-list-nostage-a';
        $featureB = 'test-list-nostage-b';

        $driver->createTodoTask(sprintf('[%s] Feature A for no-stage tests', $featureA));
        $driver->createTodoTask(sprintf('[%s] Feature B for no-stage tests', $featureB));
        $driver->startNextFeature($context->agentPrimary);
        $driver->startNextFeature($context->agentSecondary);

        // --no-stage=development should not show development entries
        $noDevOutput = $driver->runBacklog(['list', '--no-stage=development']);
        if (str_contains($noDevOutput, '[In development]')) {
            throw new \RuntimeException('list --no-stage=development must not show the In development section');
        }

        // --no-stage=review,reviewing should exclude both review stages
        $noReviewOutput = $driver->runBacklog(['list', '--no-stage=review,reviewing']);
        if (str_contains($noReviewOutput, '[Pending review]') || str_contains($noReviewOutput, '[Reviewing]')) {
            throw new \RuntimeException('list --no-stage=review,reviewing must not show review or reviewing sections');
        }
        $driver->assertContains($noReviewOutput, '[In development]');

        // Unknown stage in --no-stage is rejected
        $driver->assertBacklogFails(['list', '--no-stage=invalid'], 'Unknown stage');

        // Cleanup
        $driver->releaseEntry($context->agentPrimary, $featureA);
        $driver->releaseEntry($context->agentSecondary, $featureB);
        $driver->removeFirstTodoTask();
        $driver->removeFirstTodoTask();
    }

    private function assertMutualExclusion(BacklogScriptTestDriver $driver): void
    {
        $driver->assertBacklogFails(
            ['list', '--stage=development', '--no-stage=review'],
            '--stage and --no-stage are mutually exclusive',
        );
    }

    private function assertFormats(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $feature = 'test-list-format-feat';
        $driver->createTodoTask(sprintf('[%s] Feature for format tests', $feature));
        $driver->startNextFeature($context->agentPrimary);

        // default format: - <ref> kind=... developer=... pr=... reviewer=... title=...
        $defaultOutput = $driver->runBacklog(['list', '--stage=development', '--flat']);
        $driver->assertContains($defaultOutput, '- ' . $feature . ' kind=feature');
        $driver->assertContains($defaultOutput, 'developer=');
        $driver->assertContains($defaultOutput, 'pr=');
        $driver->assertContains($defaultOutput, 'reviewer=');
        $driver->assertContains($defaultOutput, 'title=');

        // numbered format: N. <ref> kind=...
        $numberedOutput = $driver->runBacklog(['list', '--stage=development', '--flat', '--format=numbered']);
        $driver->assertContains($numberedOutput, '1. ' . $feature . ' kind=feature');

        // ref format: one ref per line
        $refOutput = $driver->runBacklog(['list', '--stage=development', '--flat', '--format=ref']);
        $driver->assertContains($refOutput, $feature);
        if (str_contains($refOutput, 'kind=')) {
            throw new \RuntimeException('list --format=ref must not show kind= field');
        }

        // json format: structured output
        $jsonOutput = $driver->runBacklog(['list', '--stage=development', '--flat', '--format=json']);
        $decoded = json_decode($jsonOutput, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException('list --format=json must return a non-empty JSON array');
        }
        $first = $decoded[0];
        foreach (['ref', 'kind', 'developer', 'pr', 'reviewer', 'title', 'stage'] as $key) {
            if (!isset($first[$key])) {
                throw new \RuntimeException("list --format=json item must contain key: {$key}");
            }
        }
        if ($first['ref'] !== $feature) {
            throw new \RuntimeException("list --format=json ref mismatch: expected {$feature}, got {$first['ref']}");
        }

        // Unknown format is rejected
        $driver->assertBacklogFails(['list', '--format=bad'], 'Unknown --format=bad');

        // pr=none is shown for all entries (not omitted for features without PR)
        $driver->assertContains($defaultOutput, 'pr=none');

        // Cleanup
        $driver->releaseEntry($context->agentPrimary, $feature);
        $driver->removeFirstTodoTask();
    }

    private function assertFlatFlag(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $feature = 'test-list-flat-feat';
        $driver->createTodoTask(sprintf('[%s] Feature for flat tests', $feature));
        $driver->startNextFeature($context->agentPrimary);

        // --flat with exactly one stage: no stage header
        $flatOutput = $driver->runBacklog(['list', '--stage=development', '--flat']);
        $driver->assertContains($flatOutput, $feature . ' kind=feature');
        if (str_contains($flatOutput, '[In development]')) {
            throw new \RuntimeException('list --flat must not show stage headers');
        }

        // --flat without --stage: error
        $driver->assertBacklogFails(['list', '--flat'], '--flat requires --stage with exactly one value');

        // --flat with multiple stages: error
        $driver->assertBacklogFails(
            ['list', '--flat', '--stage=development', '--stage=review'],
            '--flat requires --stage with exactly one value',
        );

        // --flat with CSV multiple stages: error
        $driver->assertBacklogFails(
            ['list', '--flat', '--stage=development,review'],
            '--flat requires --stage with exactly one value',
        );

        // --flat with --no-stage: error
        $driver->assertBacklogFails(
            ['list', '--flat', '--no-stage=review'],
            '--flat requires --stage with exactly one value',
        );

        // Cleanup
        $driver->releaseEntry($context->agentPrimary, $feature);
        $driver->removeFirstTodoTask();
    }

    private function assertNoApprovedCounter(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $feature = 'test-list-no-counter';
        $driver->createTodoTask(sprintf('[%s] Feature for no-counter check', $feature));
        $driver->startNextFeature($context->agentPrimary);
        $driver->replaceBoardText(
            "    stage: development\n    feature: {$feature}",
            "    stage: approved\n    feature: {$feature}",
        );

        $output = $driver->runBacklog(['list']);
        if (str_contains($output, 'Approved entries waiting')) {
            throw new \RuntimeException('list must not show the Approved entries waiting counter');
        }
        if (str_contains($output, 'user-merge')) {
            throw new \RuntimeException('list must not show user-merge recommendation footer');
        }

        $driver->closeFeature($feature);
    }

    private function assertPendingReviewLabel(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $feature = 'test-list-pending-review';
        $driver->createTodoTask(sprintf('[%s] Feature for pending review label check', $feature));
        $driver->startNextFeature($context->agentPrimary);
        $driver->replaceBoardText(
            "    stage: development\n    feature: {$feature}",
            "    stage: review\n    feature: {$feature}",
        );

        $output = $driver->runBacklog(['list']);
        $driver->assertContains($output, '[Pending review]');
        if (str_contains($output, '[In review]')) {
            throw new \RuntimeException('list must show [Pending review] not [In review]');
        }

        $driver->closeFeature($feature);
    }
}
