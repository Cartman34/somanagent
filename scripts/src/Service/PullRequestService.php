<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Enum\PullRequestTag;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\RetryHelper;

/**
 * Service for orchestrating Pull Request lifecycles.
 */
final class PullRequestService
{
    private GitHubClient $github;

    private GitService $gitService;

    private int $retryCount;

    private int $retryBaseDelay;

    private int $retryFactor;

    public function __construct(
        GitHubClient $github,
        GitService $gitService,
        int $retryCount = 0,
        int $retryBaseDelay = 0,
        int $retryFactor = 0
    ) {
        $this->github = $github;
        $this->gitService = $gitService;
        $this->retryCount = $retryCount;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->retryFactor = $retryFactor;
    }

    public function createOrUpdatePr(string $branch, string $title, string $bodyFile, string $baseBranch = GitService::MAIN_BRANCH): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);

        if ($prNumber === null) {
            $this->createPrWithRetry($branch, $title, $bodyFile, $baseBranch);

            return;
        }

        $this->github->editPr($prNumber, $title, $bodyFile);
    }

    public function updatePrBody(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $this->github->editPr($prNumber, null, $bodyFile);
    }

    public function updatePrBodyIfExists(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            return;
        }

        $this->github->editPr($prNumber, null, $bodyFile);
    }

    public function closePr(int $prNumber): void
    {
        $this->github->closePr($prNumber);
    }

    public function mergePr(int $prNumber): void
    {
        $this->github->mergePr($prNumber);
    }

    public function editPrTitle(int $prNumber, string $title): void
    {
        $this->github->editPr($prNumber, $title);
    }

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

    public function buildPrTitle(PullRequestTag $tag, string $text, bool $blocked = false): string
    {
        $title = sprintf('[%s] %s', $tag->value, $text);

        return $blocked ? $this->getFormattedBlockedTitle($title) : $title;
    }

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
                    $this->gitService->pushBranchAndAwaitVisibility($branch);
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
        return new RetryHelper(
            $this->retryCount,
            $this->retryBaseDelay,
            $this->retryFactor,
        );
    }
}
