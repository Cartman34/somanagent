<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\RetryHelper;

/**
 * Service for orchestrating Pull Request lifecycles and formatting.
 */
final class PullRequestService
{
    private bool $dryRun;
    private string $headInvalidNeedle;
    private GitClient $git;
    private GitHubClient $github;
    private BacklogEntryService $entryService;
    private int $retryCount;
    private int $retryBaseDelay;
    private int $retryFactor;

    public function __construct(
        bool $dryRun,
        string $headInvalidNeedle,
        GitClient $git,
        GitHubClient $github,
        BacklogEntryService $entryService,
        int $retryCount = 0,
        int $retryBaseDelay = 0,
        int $retryFactor = 0,
    ) {
        $this->dryRun = $dryRun;
        $this->headInvalidNeedle = $headInvalidNeedle;
        $this->git = $git;
        $this->github = $github;
        $this->entryService = $entryService;
        $this->retryCount = $retryCount;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->retryFactor = $retryFactor;
    }

    public function createOrUpdatePr(string $branch, string $title, string $bodyFile, string $baseBranch = BacklogGitWorkflow::MAIN_BRANCH): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);

        if ($prNumber === null) {
            $this->createPrWithRetry($branch, $title, $bodyFile, $baseBranch);

            return;
        }

        $this->github->editPr($prNumber, $title, $bodyFile);
    }

    public function pushBranchAndWaitForRemoteVisibility(string $branch, ?string $worktree = null): void
    {
        $this->git->pushUpstream($branch, 'origin', $worktree);
        $this->git->fetchRemoteBranch($branch, 'origin', $worktree);
        $this->waitForRemoteBranchVisibility($branch);
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

    public function determinePrType(BoardEntry $entry, BacklogGitWorkflow $gitWorkflow): string
    {
        $base = $entry->getBase();
        $branch = $entry->getBranch();
        if ($base === null || $branch === null) {
            throw new \RuntimeException('Cannot determine PR type without base and branch metadata.');
        }

        $files = $gitWorkflow->changedFiles($base, $branch);

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
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
            return PullRequestTag::DOC->value;
        }

        if ($techOnly) {
            return PullRequestTag::TECH->value;
        }

        return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
    }

    public function buildPrTitle(string $type, BoardEntry $entry): string
    {
        $title = sprintf('[%s] %s', $type, $entry->getText());

        return $entry->isBlocked()
            ? $this->ensureBlockedTitle($title)
            : $title;
    }

    public function buildCurrentTitle(BoardEntry $entry, BacklogGitWorkflow $gitWorkflow): string
    {
        $type = $this->entryService->featureStage($entry) === BacklogBoard::STAGE_APPROVED
            ? $this->determinePrType($entry, $gitWorkflow)
            : PullRequestTag::WIP->value;

        return $this->buildPrTitle($type, $entry);
    }

    public function ensureBlockedTitle(string $title): string
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
                    $this->waitForRemoteBranchVisibility($branch);
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

    private function waitForRemoteBranchVisibility(string $branch): void
    {
        if ($this->dryRun) {
            return;
        }

        $isVisible = $this->networkRetryHelper()->run(
            fn(): bool => $this->git->isRemoteBranchVisible($branch),
            fn(bool $result): bool => !$result,
        );

        if ($isVisible) {
            return;
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    private function isHeadInvalidCreateError(string $output): bool
    {
        return str_contains($output, $this->headInvalidNeedle);
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
