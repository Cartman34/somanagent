<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

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
        $approveBody = $driver->createBodyFile('test-feature-review-approve.md', ['1. Approve feature review for workflow coverage.']);
        $mergeBody = $driver->createBodyFile('test-feature-merge.md', ['1. Merge feature for workflow coverage.']);

        $driver->requestFeatureReview($context->agentPrimary, $context->fixFeature);
        if (!str_contains($driver->reviewNext(), $context->fixFeature)) {
            throw new \RuntimeException('Expected review-next to return the active feature review.');
        }
        $driver->checkFeatureReview($context->fixFeature);
        $driver->rejectFeatureReview($context->fixFeature, $rejectBody);
        $driver->assertReviewContains($context->fixFeature);
        $driver->reworkFeature($context->agentPrimary, $context->fixFeature);
        $driver->requestFeatureReview($context->agentPrimary, $context->fixFeature);
        $driver->approveFeature($context->fixFeature, $approveBody);
        $driver->blockFeature($context->agentPrimary, $context->fixFeature);
        $driver->assertStatusContains($context->fixFeature, 'Blocker: blocked');
        $driver->unblockFeature($context->agentPrimary, $context->fixFeature);
        $driver->mergeFeature($context->fixFeature, $mergeBody);
        $driver->assertActiveFeatureMissing($context->fixFeature);
    }
}
