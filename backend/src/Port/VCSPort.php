<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Port;

/**
 * Hexagonal port for version control system operations (branches, PRs, diffs).
 */
interface VCSPort
{
    /**
     * Fetches repository metadata from the VCS provider.
     */
    public function getRepository(string $owner, string $repo): array;

    /**
     * Creates a branch from the given source branch.
     */
    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void;

    /**
     * Opens a pull or merge request from the source branch into the target base branch.
     */
    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array;

    /**
     * Returns the diff associated with a pull or merge request.
     */
    public function getDiff(string $owner, string $repo, string $pullRequestId): string;

    /**
     * Checks whether the provider is reachable with the configured credentials.
     */
    public function healthCheck(): bool;

    /**
     * Returns the provider slug handled by this implementation.
     */
    public function getProviderName(): string;
}
