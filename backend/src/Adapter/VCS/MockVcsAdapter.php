<?php

declare(strict_types=1);

namespace App\Adapter\VCS;

use App\Port\VCSPort;

/**
 * Local mock adapter used to simulate VCS operations before real provider wiring exists.
 */
final class MockVcsAdapter implements VCSPort
{
    /**
     * Returns a mock repository payload for the requested repository coordinates.
     */
    public function getRepository(string $owner, string $repo): array
    {
        return [
            'owner' => $owner,
            'repo' => $repo,
            'provider' => $this->getProviderName(),
            'mock' => true,
        ];
    }

    /**
     * Simulates branch creation without performing any remote VCS call.
     */
    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void
    {
        // Intentionally a no-op: the first VCS slice only simulates branch creation.
    }

    /**
     * Returns a mock pull request payload for future integration flows.
     */
    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array
    {
        return [
            'owner' => $owner,
            'repo' => $repo,
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
            'provider' => $this->getProviderName(),
            'mock' => true,
        ];
    }

    /**
     * Returns a placeholder diff string for the mocked pull request.
     */
    public function getDiff(string $owner, string $repo, string $pullRequestId): string
    {
        return sprintf(
            "Mock diff unavailable for %s/%s pull request %s.\n",
            $owner,
            $repo,
            $pullRequestId,
        );
    }

    /**
     * Always reports healthy because the mock adapter has no external dependency.
     */
    public function healthCheck(): bool
    {
        return true;
    }

    /**
     * Returns the provider identifier exposed by this adapter.
     */
    public function getProviderName(): string
    {
        return 'mock';
    }
}
