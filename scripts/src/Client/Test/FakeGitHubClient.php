<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Test;

use Sowapps\SoManAgent\Script\Client\GitHub\GitHubClientInterface;

/**
 * Fake GitHub client for unit testing PullRequestService.
 *
 * Configure PR states before exercising the service under test.
 */
final class FakeGitHubClient implements GitHubClientInterface
{
    /**
     * @var array<int, string> prNumber → state ("merged"|"open"|"closed")
     */
    private array $prStates = [];

    private int $mergeCallCount = 0;

    /**
     * Configure the fake state for a given PR number.
     */
    public function setPrState(int $prNumber, string $state): void
    {
        $this->prStates[$prNumber] = $state;
    }

    /**
     * Returns the number of times mergePr was called.
     */
    public function getMergeCallCount(): int
    {
        return $this->mergeCallCount;
    }

    /**
     * Returns the configured state for the given PR number.
     */
    public function getPrState(int $prNumber): string
    {
        if (!isset($this->prStates[$prNumber])) {
            throw new \RuntimeException(sprintf('FakeGitHubClient: no state configured for PR #%d.', $prNumber));
        }

        return $this->prStates[$prNumber];
    }

    /**
     * Records the merge call; does not perform any real merge.
     */
    public function mergePr(int $prNumber): void
    {
        $this->mergeCallCount++;
    }

    /**
     * Returns an empty string (no real API calls in tests).
     */
    public function listPrs(): string
    {
        return '';
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function createPr(string $title, string $headBranch, string $baseBranch, string $bodyFilePath): array
    {
        return [0, ''];
    }

    /**
     * No-op in test context.
     *
     * @param string|null $title
     * @param string|null $bodyFilePath
     */
    public function editPr(int $prNumber, ?string $title = null, ?string $bodyFilePath = null): void
    {
    }

    /**
     * No-op in test context.
     */
    public function closePr(int $prNumber): void
    {
    }
}
