<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client\Test;

use SoManAgent\Script\Client\GitHubClientInterface;

/**
 * Fake GitHub client for unit testing PullRequestService.
 *
 * Configure PR states before exercising the service under test.
 */
final class FakeGitHubClient implements GitHubClientInterface
{
    /** @var array<int, string> prNumber → state ("merged"|"open"|"closed") */
    private array $prStates = [];

    private int $mergeCallCount = 0;

    public function setPrState(int $prNumber, string $state): void
    {
        $this->prStates[$prNumber] = $state;
    }

    public function getMergeCallCount(): int
    {
        return $this->mergeCallCount;
    }

    public function getPrState(int $prNumber): string
    {
        if (!isset($this->prStates[$prNumber])) {
            throw new \RuntimeException(sprintf('FakeGitHubClient: no state configured for PR #%d.', $prNumber));
        }

        return $this->prStates[$prNumber];
    }

    public function mergePr(int $prNumber): void
    {
        $this->mergeCallCount++;
    }

    public function listPrs(): string
    {
        return '';
    }

    public function listAllPrs(): string
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

    public function editPr(int $prNumber, ?string $title = null, ?string $bodyFilePath = null): void
    {
    }

    public function closePr(int $prNumber): void
    {
    }
}
