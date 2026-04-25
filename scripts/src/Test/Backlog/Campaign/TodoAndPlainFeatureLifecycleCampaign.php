<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

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
        $driver->startNextFeature($context->agentPrimary);
        $driver->assertStatusContains($context->agentPrimary, $context->plainFeature, true);
        $driver->assertWorktreeListContains($context->agentPrimary);
        $driver->releaseFeature($context->agentPrimary, $context->plainFeature);
        $driver->assertTodoContains($context->plainFeature);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask($context->assignFeature);
        $driver->startNextFeature($context->agentPrimary);
        $driver->assignFeatureAsManager($context->assignFeature, $context->agentSecondary);
        $driver->assertStatusContains($context->agentSecondary, $context->assignFeature, true);
        $driver->unassignFeatureAsManager($context->assignFeature, $context->agentSecondary);
        $driver->assignFeatureAsManager($context->assignFeature, $context->agentSecondary);
        $driver->releaseFeature($context->agentSecondary, $context->assignFeature);
        $driver->assertTodoContains($context->assignFeature);
    }
}
