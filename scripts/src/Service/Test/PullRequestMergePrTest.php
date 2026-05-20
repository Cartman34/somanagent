<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service\Test;

use SoManAgent\Script\Client\Test\FakeGitHubClient;
use SoManAgent\Script\RetryPolicy;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Unit tests for PullRequestService::mergePr idempotence.
 *
 * Verifies that:
 * - A PR that is already merged produces a no-op (no second merge call).
 * - An open PR triggers the actual merge call.
 * - A closed (not merged) PR raises a clear RuntimeException.
 */
final class PullRequestMergePrTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     *
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testAlreadyMergedPrIsNoOp();
        $failed += $this->testOpenPrIsMerged();
        $failed += $this->testClosedNotMergedPrThrows();

        return $failed;
    }

    private function testAlreadyMergedPrIsNoOp(): int
    {
        $client = new FakeGitHubClient();
        $client->setPrState(42, 'merged');
        $service = $this->makeService($client);

        $service->mergePr(42);

        if ($client->getMergeCallCount() !== 0) {
            echo "FAIL testAlreadyMergedPrIsNoOp: mergePr on GitHub should not have been called for an already-merged PR\n";

            return 1;
        }

        return 0;
    }

    private function testOpenPrIsMerged(): int
    {
        $client = new FakeGitHubClient();
        $client->setPrState(99, 'open');
        $service = $this->makeService($client);

        $service->mergePr(99);

        if ($client->getMergeCallCount() !== 1) {
            echo sprintf(
                "FAIL testOpenPrIsMerged: expected 1 merge call, got %d\n",
                $client->getMergeCallCount(),
            );

            return 1;
        }

        return 0;
    }

    private function testClosedNotMergedPrThrows(): int
    {
        $client = new FakeGitHubClient();
        $client->setPrState(7, 'closed');
        $service = $this->makeService($client);

        try {
            $service->mergePr(7);
            echo "FAIL testClosedNotMergedPrThrows: expected RuntimeException for closed PR\n";

            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), '#7')) {
                echo sprintf(
                    "FAIL testClosedNotMergedPrThrows: expected PR number in exception message, got: %s\n",
                    $e->getMessage(),
                );

                return 1;
            }
        }

        return 0;
    }

    private function makeService(FakeGitHubClient $client): PullRequestService
    {
        // GitService is not exercised by mergePr; bypass its constructor to avoid real dependencies.
        $gitService = (new \ReflectionClass(GitService::class))->newInstanceWithoutConstructor();

        return new PullRequestService($client, $gitService, new RetryPolicy());
    }
}
