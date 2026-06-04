<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\GitHub;

/**
 * Contract for GitHub pull request operations used by PullRequestService.
 */
interface GitHubClientInterface
{
    /**
     * @return array{0: int, 1: string}
     */
    public function createPr(string $title, string $headBranch, string $baseBranch, string $bodyFilePath): array;

    /**
     * @param string|null $title
     * @param string|null $bodyFilePath
     */
    public function editPr(int $prNumber, ?string $title = null, ?string $bodyFilePath = null): void;

    /**
     * Close a PR without merging.
     */
    public function closePr(int $prNumber): void;

    /**
     * Merge a PR.
     */
    public function mergePr(int $prNumber): void;

    /**
     * Returns the PR state: "merged", "open", or "closed".
     */
    public function getPrState(int $prNumber): string;

    /**
     * Returns open PRs in the same text format as pr-list.
     */
    public function listPrs(): string;

}
