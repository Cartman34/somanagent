<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Todo and plain feature lifecycle campaign
 *
 * Tests basic todo operations and plain feature lifecycle (create, start, release, assign).
 */
final class TodoAndPlainFeatureLifecycleCampaign implements CampaignInterface
{
    private const MANAGER_AGENT = 'test-m01';
    private const AMBIGUOUS_SLUG = 'ambiguous-plain-ref';
    private const AMBIGUOUS_SLUG_2 = 'ambiguous-plain-ref-2';

    public function getName(): string
    {
        return 'todo-and-plain-feature-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->createTodoTask('[test-remove-task] test-remove-task');
        $driver->assertTodoContains('test-remove-task kind=feature');
        $driver->assertTodoContains('title=test-remove-task');
        $driver->assertTaskRemoveFails('', 'requires a queued task reference');
        $driver->assertTaskRemoveFails('does-not-exist-slug', 'No queued task found for reference: does-not-exist-slug');
        $driver->removeTodoTask('test-remove-task');

        $driver->createTodoTask('[feat][stable-feature-ref][stable-task-ref] Scoped task for stable reference coverage');
        $driver->createTodoTask('[feat][stable-feature-ref] Plain feature with same feature slug as the scoped child');
        $driver->removeTodoTask('stable-feature-ref/stable-task-ref');
        $driver->removeTodoTask('stable-feature-ref');

        $driver->createTodoTask('[' . self::AMBIGUOUS_SLUG . '] First plain instance');
        $driver->createTodoTask('[' . self::AMBIGUOUS_SLUG . '] Second plain instance with same feature slug');
        $driver->assertTaskRemoveFails(self::AMBIGUOUS_SLUG, 'Ambiguous queued reference ' . self::AMBIGUOUS_SLUG);
        $driver->renameTodoEntry(
            self::AMBIGUOUS_SLUG,
            self::AMBIGUOUS_SLUG_2,
            'Second plain instance with same feature slug',
            'Second plain instance with renamed feature slug',
        );
        $driver->removeTodoTask(self::AMBIGUOUS_SLUG);
        $driver->removeTodoTask(self::AMBIGUOUS_SLUG_2);

        // review-cancel guard runs before loadBoard, so we can exercise the explicit-reference
        // contract directly here without bringing up a full review-stage flow.
        $driver->assertReviewCancelFails(
            $context->agentSecondary,
            '',
            'review-cancel requires an explicit <entry-ref> reference.',
        );
        $driver->assertReviewCancelFails(
            $context->agentSecondary,
            '   ',
            'review-cancel requires an explicit <entry-ref> reference.',
        );

        $driver->createTodoTask(sprintf('[%s] %s', $context->plainFeature, $context->plainFeature));
        $driver->assertTodoContains($context->plainFeature);
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertFeatureStartOutputContains($startOutput, '[Feature]');
        $driver->assertFeatureStartOutputContains($startOutput, 'Feature: ' . $context->plainFeature);
        $driver->assertFeatureStartOutputContains($startOutput, '[Worktree]');
        $driver->assertFeatureStartOutputContains($startOutput, $context->agentPrimary);
        $driver->assertStatusContains($context->agentPrimary, $context->plainFeature, true);
        $driver->assertWorktreeListContains($context->agentPrimary);
        $driver->removeManagedWorktree($context->agentPrimary);
        $driver->restoreWorktree($context->agentPrimary);
        $driver->assertWorktreeListContains($context->agentPrimary);

        // ── entry-set-meta: set, overwrite, clear, rejection cases ───────────
        $driver->setEntryMeta($context->agentPrimary, $context->plainFeature, 'database=test_db_v1');
        $driver->assertBoardContains('    database: test_db_v1');
        $driver->setEntryMeta($context->agentPrimary, $context->plainFeature, 'database=test_db_v2');
        $driver->assertBoardContains('    database: test_db_v2');
        $driver->assertBoardLacksText('    database: test_db_v1');
        $driver->setEntryMeta($context->agentPrimary, $context->plainFeature, 'database=');
        $driver->assertBoardLacksText('    database:');
        $driver->assertSetEntryMetaFails($context->agentPrimary, $context->plainFeature, 'unknown-key=value', 'does not support key "unknown-key"');
        $driver->assertSetEntryMetaFails($context->agentPrimary, $context->plainFeature, 'database', 'key=value argument');
        $driver->assertSetEntryMetaFails($context->agentPrimary, '', 'database=some_db', '<entry-ref> argument');
        $driver->assertSetEntryMetaFails($context->agentPrimary, 'does-not-exist', 'database=some_db', 'No active entry found for entry-ref');

        // ── migrate --generate: end-to-end isolated DB lifecycle (requires Docker) ──
        $driver->runMigrateGenerate($context->agentPrimary);

        $driver->releaseEntry($context->agentPrimary, $context->plainFeature);
        $driver->assertTodoContains($context->plainFeature);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask(sprintf('[%s] %s', $context->assignFeature, $context->assignFeature));
        $driver->startNextFeature($context->agentPrimary);
        $driver->assignEntryAsManager($context->assignFeature, $context->agentPrimary);
        $driver->assertStatusContains($context->agentPrimary, $context->assignFeature, true);
        $driver->assertAssignEntryFails(
            $context->assignFeature,
            $context->agentSecondary,
            ['SOMANAGER_ROLE' => 'manager'],
            sprintf('Entry %s is already assigned to %s.', $context->assignFeature, $context->agentPrimary),
        );
        $driver->unassignEntryAsManager($context->assignFeature, self::MANAGER_AGENT);
        $driver->assignEntryAsManager($context->assignFeature, $context->agentSecondary);
        $driver->assertStatusContains($context->agentSecondary, $context->assignFeature, true);

        $driver->unassignEntryAsManager($context->assignFeature, self::MANAGER_AGENT);
        $driver->assignEntryAsManager($context->assignFeature, $context->agentSecondary);

        $driver->unassignEntryAsManager(null, $context->agentSecondary);
        $driver->assignEntryAsManager($context->assignFeature, $context->agentSecondary);

        $driver->assertUnassignEntryFails(
            $context->assignFeature,
            $context->agentSecondary,
            ['SOMANAGER_ROLE' => 'developer', 'SOMANAGER_AGENT' => $context->agentPrimary],
            'Developer role can only unassign itself',
        );
        $driver->assertUnassignEntryFails(
            $context->assignFeature,
            $context->agentPrimary,
            ['SOMANAGER_ROLE' => 'developer', 'SOMANAGER_AGENT' => $context->agentPrimary],
            sprintf('Entry %s is assigned to %s. Developer role can only unassign its own entry.', $context->assignFeature, $context->agentSecondary),
        );

        $driver->releaseEntry($context->agentSecondary, $context->assignFeature);
        $driver->assertTodoContains($context->assignFeature);
        $driver->removeFirstTodoTask();

        $singlePrefixSlug = 'test-single-prefix';
        $driver->createTodoTask(sprintf('[%s] Single prefix feature description', $singlePrefixSlug));
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertFeatureStartOutputContains($startOutput, $singlePrefixSlug);
        $driver->renameEntry($context->agentPrimary, 'Renamed single prefix description');
        $driver->assertStatusContains($singlePrefixSlug, 'Renamed single prefix description');
        $driver->releaseEntry($context->agentPrimary, $singlePrefixSlug);
        $driver->removeFirstTodoTask();

        $this->assertWorkStartWithExplicitTarget($driver, $context);

        $committedFeature = $context->plainFeature . '-committed-release';
        $driver->createTodoTask(sprintf('[%s] %s', $committedFeature, $committedFeature));
        $driver->startNextFeature($context->agentPrimary);
        $driver->trackFeatureBranch($committedFeature);
        $driver->commitAndRevertFeatureChange($context->agentPrimary, $committedFeature, 'test-release-commit-history.txt');
        $driver->assertReleaseEntryFails(
            $context->agentPrimary,
            $committedFeature,
            'Active entry already has development work and cannot be released back to todo.',
        );
    }

    /**
     * Validates that `work-start <reference>` consumes the explicit target instead of
     * the head, and refuses with a clear error when the target does not match any
     * queued entry.
     */
    private function assertWorkStartWithExplicitTarget(
        BacklogScriptTestDriver $driver,
        BacklogScriptTestContext $context,
    ): void {
        $headSlug = 'work-start-head';
        $targetSlug = 'work-start-target';

        $driver->createTodoTask(sprintf('[%s] Head entry that should stay queued', $headSlug));
        $driver->createTodoTask(sprintf('[%s] Explicit target entry', $targetSlug));

        $driver->assertWorkStartFails(
            $context->agentPrimary,
            'No queued task found for reference: unknown-slug-that-does-not-exist',
            ['unknown-slug-that-does-not-exist'],
        );
        if ($driver->checkManagedWorktreeExists($context->agentPrimary)) {
            throw new \RuntimeException('A refused work-start must not create a managed worktree.');
        }
        $driver->assertTodoContains($headSlug);
        $driver->assertTodoContains($targetSlug);

        $output = $driver->startNextFeature($context->agentPrimary, $targetSlug);
        $driver->assertFeatureStartOutputContains($output, $targetSlug);
        $driver->assertActiveFeatureExists($targetSlug);
        $driver->assertTodoContains($headSlug);

        $driver->releaseEntry($context->agentPrimary, $targetSlug);
        $driver->removeFirstTodoTask();
        $driver->removeFirstTodoTask();
    }
}
