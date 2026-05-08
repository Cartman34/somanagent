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
    public function getName(): string
    {
        return 'todo-and-plain-feature-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->createTodoTask('test-remove-task');
        $driver->assertTodoContains('test-remove-task');
        $driver->removeFirstTodoTask();

        $driver->createTodoTask($context->plainFeature);
        $driver->assertTodoContains($context->plainFeature);
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertFeatureStartOutputContains($startOutput, '[Feature]');
        $driver->assertFeatureStartOutputContains($startOutput, 'Summary: ' . $context->plainFeature);
        $driver->assertFeatureStartOutputContains($startOutput, '[Worktree]');
        $driver->assertFeatureStartOutputContains($startOutput, $context->agentPrimary);
        $driver->assertStatusContains($context->agentPrimary, $context->plainFeature, true);
        $driver->assertWorktreeListContains($context->agentPrimary);
        $driver->removeManagedWorktree($context->agentPrimary);
        $driver->restoreWorktree($context->agentPrimary);
        $driver->assertWorktreeListContains($context->agentPrimary);
        $driver->releaseFeature($context->agentPrimary, $context->plainFeature);
        $driver->assertTodoContains($context->plainFeature);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask($context->assignFeature);
        $driver->startNextFeature($context->agentPrimary);
        $driver->assignFeatureAsManager($context->assignFeature, $context->agentSecondary);
        $driver->assertStatusContains($context->agentSecondary, $context->assignFeature, true);

        $driver->unassignEntryAsManager($context->assignFeature, $context->agentSecondary);
        $driver->assignFeatureAsManager($context->assignFeature, $context->agentSecondary);

        $driver->unassignEntryAsManager(null, $context->agentSecondary);
        $driver->assignFeatureAsManager($context->assignFeature, $context->agentSecondary);

        $driver->assertUnassignEntryFails(
            $context->assignFeature,
            $context->agentSecondary,
            ['SOMANAGER_ROLE' => 'developer', 'SOMANAGER_AGENT' => $context->agentPrimary],
            'Developer role can only unassign itself',
        );

        $driver->releaseFeature($context->agentSecondary, $context->assignFeature);
        $driver->assertTodoContains($context->assignFeature);
        $driver->removeFirstTodoTask();

        $singlePrefixSlug = 'test-single-prefix';
        $driver->createTodoTask(sprintf('[%s] Single prefix feature description', $singlePrefixSlug));
        $startOutput = $driver->startNextFeature($context->agentPrimary);
        $driver->assertFeatureStartOutputContains($startOutput, $singlePrefixSlug);
        $driver->renameEntry($context->agentPrimary, 'Renamed single prefix description');
        $driver->assertStatusContains($singlePrefixSlug, 'Renamed single prefix description');
        $driver->releaseFeature($context->agentPrimary, $singlePrefixSlug);

        $committedFeature = $context->plainFeature . '-committed-release';
        $driver->createTodoTask($committedFeature);
        $driver->startNextFeature($context->agentPrimary);
        $driver->trackFeatureBranch($committedFeature);
        $driver->commitAndRevertFeatureChange($context->agentPrimary, $committedFeature, 'test-release-commit-history.txt');
        $driver->assertReleaseFeatureFails(
            $context->agentPrimary,
            $committedFeature,
            'Active entry already has development work and cannot be released back to todo.',
        );
    }
}
