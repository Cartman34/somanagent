<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Work-start type prefix campaign.
 *
 * Validates that work-start parses task type prefixes ([feat], [fix], [tech]) at any
 * position in the leading bracket sequence, maps each type to the matching branch
 * prefix 1:1, rejects unknown --branch-type values, refuses without leaving any
 * worktree behind, and supports --dry-run with full plan output and no mutation.
 */
final class WorkStartTypePrefixCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'work-start-type-prefix';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $primary = $context->agentPrimary;

        $driver->createTodoTask('[tech][backlog-entry-types-test] Tech-leading single feature');
        $output = $driver->dryRunStartNextFeature($primary);
        $driver->assertOutputContainsAll($output, [
            'Kind:           feature',
            'Type:           tech',
            'Feature:        backlog-entry-types-test',
            'Feature branch: tech/backlog-entry-types-test',
            'No mutation performed (--dry-run).',
        ]);
        if ($driver->checkManagedWorktreeExists($primary)) {
            throw new \RuntimeException('Dry-run work-start must not create a managed worktree.');
        }
        $driver->assertTodoContains('Tech-leading single feature');
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[fix-target][child-fix][fix] Trailing-fix child task');
        $output = $driver->dryRunStartNextFeature($primary);
        $driver->assertOutputContainsAll($output, [
            'Kind:           task',
            'Type:           fix',
            'Feature:        fix-target',
            'Task:           child-fix',
            'Feature branch: fix/fix-target',
            'Task branch:    fix/fix-target--child-fix',
            'Feature parent: will be created',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[tech-target][tech] Trailing-tech single feature');
        $output = $driver->dryRunStartNextFeature($primary);
        $driver->assertOutputContainsAll($output, [
            'Kind:           feature',
            'Type:           tech',
            'Feature:        tech-target',
            'Feature branch: tech/tech-target',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[feat][nested-feature][nested-task] Type-leading scoped child task');
        $output = $driver->dryRunStartNextFeature($primary);
        $driver->assertOutputContainsAll($output, [
            'Kind:           task',
            'Type:           feat',
            'Feature:        nested-feature',
            'Task:           nested-task',
            'Feature branch: feat/nested-feature',
            'Task branch:    feat/nested-feature--nested-task',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[some-feature] Plain feature for unknown branch-type test');
        $driver->assertWorkStartFails(
            $primary,
            'Unknown --branch-type=invalid',
            ['--branch-type=invalid', '--dry-run'],
        );
        if ($driver->checkManagedWorktreeExists($primary)) {
            throw new \RuntimeException('A refused work-start must not create a managed worktree.');
        }
        $driver->assertTodoContains('Plain feature for unknown branch-type test');
        $driver->removeFirstTodoTask();
    }
}
