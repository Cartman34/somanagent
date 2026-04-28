<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Console;

/**
 * High-level service for git operations.
 */
final class GitService
{
    public const MAIN_BRANCH = 'main';
    public const ORIGIN_REMOTE = 'origin';

    private bool $dryRun;

    private Console $console;

    private GitClient $git;

    /** @var callable(string): void */
    private $verboseLogger;

    public function __construct(
        bool $dryRun,
        Console $console,
        GitClient $git,
        callable $verboseLogger
    ) {
        $this->dryRun = $dryRun;
        $this->console = $console;
        $this->git = $git;
        $this->verboseLogger = $verboseLogger;
    }

    public function getBranchHead(string $branch): string
    {
        return $this->git->branchHead($branch);
    }

    public function checkLocalBranchExists(string $branch): bool
    {
        return $this->git->localBranchExists($branch);
    }

    public function checkRemoteBranchExists(string $branch, string $remote = self::ORIGIN_REMOTE): bool
    {
        return $this->git->remoteBranchExists($remote, $branch);
    }

    public function deleteLocalBranch(string $branch, bool $force = true): void
    {
        if ($force) {
            $this->git->deleteLocalBranch($branch);
        } else {
            // Simplified for now, we only use force in the project
            $this->git->deleteLocalBranch($branch);
        }
    }

    public function deleteRemoteBranch(string $branch, string $remote = self::ORIGIN_REMOTE): void
    {
        $this->git->deleteRemoteBranch($remote, $branch);
    }

    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->git->mergeBranchInPath($path, $branch, $message);
    }

    public function checkBranchHasNoDevelopment(string $base, string $branch): bool
    {
        return $this->git->countCommitsAhead($base, $branch) === 0;
    }

    public function pushBranchAndAwaitVisibility(string $branch, string $remote = self::ORIGIN_REMOTE, ?string $worktree = null): void
    {
        $this->git->pushUpstream($branch, $remote, $worktree);
        $this->git->fetchRemoteBranch($branch, $remote, $worktree);
        $this->waitForRemoteBranchVisibility($branch, $remote);
    }

    public function checkWorkspaceHasLocalChanges(): bool
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace changes');

            return $this->git->hasLocalChanges();
        }

        return $this->git->hasLocalChanges();
    }

    public function getWorkspaceCurrentBranch(): string
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace branch');

            return $this->git->currentBranch();
        }

        return $this->git->currentBranch();
    }

    public function updateMainBranch(): void
    {
        if ($this->getWorkspaceCurrentBranch() !== self::MAIN_BRANCH) {
            $this->git->runNetwork(sprintf(
                'git fetch %s %s:%s',
                self::ORIGIN_REMOTE,
                self::MAIN_BRANCH,
                self::MAIN_BRANCH,
            ));

            return;
        }

        $this->updateLocalMainBranchWithWarning();
    }

    /**
     * @return array<string>
     */
    public function getChangedFiles(string $base, string $branch): array
    {
        return $this->git->getChangedFiles($base, $branch);
    }

    public function checkoutAndPull(string $branch): void
    {
        $this->git->checkoutAndPull($branch);
    }

    public function fetchBranch(string $branch, string $remote = self::ORIGIN_REMOTE): void
    {
        $this->git->runNetwork(sprintf(
            'git fetch %s %s:%s',
            $remote,
            escapeshellarg($branch),
            escapeshellarg($branch),
        ));
    }

    private function updateLocalMainBranchWithWarning(): void
    {
        try {
            $this->git->pullFastForwardOnly();
        } catch (\RuntimeException $exception) {
            $this->console->warn('Unable to update local main in WP; continuing with the current local main.');
            $this->logVerbose('Main update warning detail: ' . $exception->getMessage());
        }
    }

    private function waitForRemoteBranchVisibility(string $branch, string $remote): void
    {
        if ($this->dryRun) {
            return;
        }

        // We use the git client's visibility check which is already a retry loop
        if ($this->git->isRemoteBranchVisible($branch, $remote)) {
            return;
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    private function logVerbose(string $message): void
    {
        ($this->verboseLogger)($message);
    }
}
