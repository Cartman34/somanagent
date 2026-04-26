<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

final class ScopedTaskLifecycleCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'scoped-task-lifecycle';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $taskARef = $context->scopedFeature . '/' . $context->childA;
        $taskBRef = $context->scopedFeature . '/' . $context->childB;

        $driver->createTodoTask(sprintf('[%s][%s] Implement test child task A', $context->scopedFeature, $context->childA));
        $driver->startNextFeature($context->agentPrimary);
        $driver->assertActiveFeatureExists($context->scopedFeature);
        $driver->assertStatusContains($context->scopedFeature, $context->childA);

        $rejectBody = $driver->createBodyFile('test-task-review-reject.md', ['1. Reject child task for test workflow.']);
        $driver->requestTaskReview($context->agentPrimary, $taskARef);
        if (!str_contains($driver->reviewNext(), $taskARef)) {
            throw new \RuntimeException('Expected review-next to return the active task review.');
        }
        $driver->checkTaskReview($taskARef);
        $driver->rejectTaskReview($taskARef, $rejectBody);
        $driver->assertReviewContains($taskARef);
        $driver->reworkTask($context->agentPrimary, $taskARef);
        $driver->requestTaskReview($context->agentPrimary, $taskARef);
        $driver->approveTask($taskARef);
        $driver->assertReviewMissing($taskARef);
        $driver->mergeTask($taskARef);

        $driver->createTodoTask(sprintf('[%s][%s] Implement test child task B', $context->scopedFeature, $context->childB));
        $driver->addQueuedTaskToCurrentFeature($context->agentPrimary, 'Updated test scoped feature summary');
        $driver->assertStatusContains($context->scopedFeature, 'Updated test scoped feature summary');

        $rejectFeatureTaskB = $driver->createBodyFile('test-task-review-reject-b.md', ['1. Reject second child task for coverage.']);
        $driver->requestTaskReview($context->agentPrimary, $taskBRef);
        $driver->rejectTaskReview($taskBRef, $rejectFeatureTaskB);
        $driver->reworkTask($context->agentPrimary, $taskBRef);
        $driver->requestTaskReview($context->agentPrimary, $taskBRef);
        $driver->approveTask($taskBRef);
        $driver->mergeTask($taskBRef);

        $driver->closeFeature($context->scopedFeature);
        $driver->assertActiveFeatureMissing($context->scopedFeature);
    }
}
