<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

use SoManAgent\Script\Backlog\Enum\PullRequestTag;
use SoManAgent\Script\Client\GitHubClientInterface;
use SoManAgent\Script\RetryHelper;
use SoManAgent\Script\RetryPolicy;

/**
 * Service for orchestrating Pull Request lifecycles.
 */
final class PullRequestService
{
    private GitHubClientInterface $github;

    private GitService $gitService;

    private RetryPolicy $retryPolicy;

    /**
     * Constructor.
     *
     * @param GitHubClientInterface $github GitHub client for PR API calls
     * @param GitService $gitService Git service for local operations and pushes
     * @param RetryPolicy $retryPolicy Retry policy for network-dependent calls
     */
    public function __construct(
        GitHubClientInterface $github,
        GitService $gitService,
        RetryPolicy $retryPolicy
    ) {
        $this->github = $github;
        $this->gitService = $gitService;
        $this->retryPolicy = $retryPolicy;
    }

    /**
     * Create the PR for a branch if it does not exist, otherwise update the existing one.
     *
     * @param string $branch Source branch of the PR
     * @param string $title PR title to set
     * @param string $bodyFile Local file containing the PR body
     * @param string $baseBranch Target base branch (default: main)
     */
    public function createOrUpdatePr(string $branch, string $title, string $bodyFile, string $baseBranch = GitService::MAIN_BRANCH): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);

        if ($prNumber === null) {
            $this->createPrWithRetry($branch, $title, $bodyFile, $baseBranch);

            return;
        }

        $this->github->editPr($prNumber, $title, $bodyFile);
    }

    /**
     * Close a PR by number.
     *
     * @param int $prNumber Pull request number on GitHub
     */
    public function closePr(int $prNumber): void
    {
        $this->github->closePr($prNumber);
    }

    /**
     * Merge a PR by number. Idempotent: if the PR is already merged, this is a no-op.
     *
     * - PR state "merged": no-op, returns without error.
     * - PR state "open": merges the PR.
     * - PR state "closed" (not merged): throws a RuntimeException.
     *
     * @param int $prNumber Pull request number on GitHub
     * @throws \RuntimeException When the PR is closed but not merged
     */
    public function mergePr(int $prNumber): void
    {
        $state = $this->github->getPrState($prNumber);
        if ($state === 'merged') {
            return;
        }
        if ($state === 'closed') {
            throw new \RuntimeException(sprintf(
                'PR #%d is closed (not merged) and cannot be merged.',
                $prNumber,
            ));
        }
        $this->github->mergePr($prNumber);
    }

    /**
     * Update only the title of a PR by number.
     *
     * @param int $prNumber Pull request number on GitHub
     * @param string $title New PR title
     */
    public function editPrTitle(int $prNumber, string $title): void
    {
        $this->github->editPr($prNumber, $title);
    }

    /**
     * Find the open PR number associated with a source branch.
     *
     * @param string $branch Source branch to look up
     * @return int|null PR number when found, null when no open PR matches the branch
     */
    public function findPrNumberByBranch(string $branch): ?int
    {
        if ($branch === '') {
            return null;
        }

        $output = $this->github->listPrs();
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^\s*#(\d+)\s+.*\[(.+?) → (.+?)\]$/u', $line, $matches) === 1) {
                if ($matches[2] === $branch) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Derive the dominant PR tag from the file changes between a base and a branch.
     *
     * Returns DOC when every change is under `doc/` or in `AGENTS.md`, TECH when
     * every change is under `scripts/` / `.github/` or matches a tooling
     * manifest, FIX when the branch name starts with `fix/`, otherwise FEAT.
     *
     * @param string $base Base commit to compare against
     * @param string $branch Branch to compare from
     * @return PullRequestTag Resolved dominant tag for the PR title
     */
    public function getPrTypeFromChanges(string $base, string $branch): PullRequestTag
    {
        $files = $this->gitService->getChangedFiles($base, $branch);

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX : PullRequestTag::FEAT;
        }

        $docOnly = true;
        $techOnly = true;

        foreach ($files as $file) {
            if (!str_starts_with($file, 'doc/') && $file !== 'AGENTS.md') {
                $docOnly = false;
            }

            if (
                !str_starts_with($file, 'scripts/')
                && !str_starts_with($file, '.github/')
                && !in_array($file, ['AGENTS.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml'], true)
            ) {
                $techOnly = false;
            }
        }

        if ($docOnly) {
            return PullRequestTag::DOC;
        }

        if ($techOnly) {
            return PullRequestTag::TECH;
        }

        return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX : PullRequestTag::FEAT;
    }

    /**
     * Build the canonical PR title `[<tag>] <text>`, optionally with the blocked marker.
     *
     * @param PullRequestTag $tag Dominant tag for the title
     * @param string $text Feature text to include in the title
     * @param bool $blocked When true, prefix the title with the blocked tag
     * @return string Formatted PR title
     */
    public function buildPrTitle(PullRequestTag $tag, string $text, bool $blocked = false): string
    {
        $title = sprintf('[%s] %s', $tag->value, $text);

        return $blocked ? $this->getFormattedBlockedTitle($title) : $title;
    }

    /**
     * Ensure a PR title carries the blocked marker exactly once.
     *
     * @param string $title PR title to inspect
     * @return string Same title when the blocked marker is already present, otherwise the marker prepended
     */
    public function getFormattedBlockedTitle(string $title): string
    {
        $tag = '[' . PullRequestTag::BLOCKED->value . ']';

        return str_contains($title, $tag)
            ? $title
            : $tag . ' ' . $title;
    }

    private function createPrWithRetry(string $branch, string $title, string $bodyFile, string $baseBranch): void
    {
        [$code, $output] = $this->networkRetryHelper()->run(
            function () use ($title, $branch, $baseBranch, $bodyFile): array {
                $result = $this->github->createPr($title, $branch, $baseBranch, $bodyFile);
                if ($result[0] !== 0 && $this->isHeadInvalidCreateError($result[1])) {
                    $this->gitService->pushBranchSafely($branch);
                }

                return $result;
            },
            fn(array $result): bool => $result[0] !== 0 && $this->isHeadInvalidCreateError($result[1]),
        );

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d:\n%s",
                $code,
                $output,
            ));
        }
    }

    private function isHeadInvalidCreateError(string $output): bool
    {
        return str_contains($output, 'Head branch is invalid');
    }

    private function networkRetryHelper(): RetryHelper
    {
        return $this->retryPolicy->createHelper();
    }
}
