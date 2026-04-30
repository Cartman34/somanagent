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

    /**
     * Constructor for GitService.
     *
     * @param bool $dryRun Whether to run in dry-run mode
     * @param Console $console Console for output
     * @param GitClient $git Git client instance
     * @param callable $verboseLogger Verbose logger callback
     */
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

    /**
     * Get the commit hash of a branch head.
     *
     * @param string $branch Branch name
     * @return string Commit hash
     */
    public function getBranchHead(string $branch): string
    {
        return $this->git->branchHead($branch);
    }

    /**
     * Check if a reference exists.
     *
     * @param string $ref Reference (branch, tag, or commit)
     * @return bool True if reference exists
     */
    public function checkRefExists(string $ref): bool
    {
        return $this->git->refExists($ref);
    }

    /**
     * Get the merge base between two references.
     *
     * @param string $left First reference
     * @param string $right Second reference
     * @return string Commit hash of merge base
     */
    public function getMergeBase(string $left, string $right): string
    {
        return $this->git->mergeBase($left, $right);
    }

    /**
     * Check if one commit is an ancestor of another.
     *
     * @param string $ancestor Potential ancestor commit/branch
     * @param string $descendant Potential descendant commit/branch
     * @return bool True if ancestor is indeed an ancestor of descendant
     */
    public function checkIsAncestor(string $ancestor, string $descendant): bool
    {
        return $this->git->isAncestor($ancestor, $descendant);
    }

    /**
     * Check if a local branch exists.
     *
     * @param string $branch Branch name
     * @return bool True if local branch exists
     */
    public function checkLocalBranchExists(string $branch): bool
    {
        return $this->git->localBranchExists($branch);
    }

    /**
     * Check if a remote branch exists.
     *
     * @param string $branch Branch name
     * @param string $remote Remote name (default: origin)
     * @return bool True if remote branch exists
     */
    public function checkRemoteBranchExists(string $branch, string $remote = self::ORIGIN_REMOTE): bool
    {
        return $this->git->remoteBranchExists($remote, $branch);
    }

    /**
     * Delete a local branch.
     *
     * @param string $branch Branch name to delete
     * @param bool $force Force deletion (default: true)
     */
    public function deleteLocalBranch(string $branch, bool $force = true): void
    {
        if ($force) {
            $this->git->deleteLocalBranch($branch);
        } else {
            // Simplified for now, we only use force in the project
            $this->git->deleteLocalBranch($branch);
        }
    }

    /**
     * Delete a remote branch.
     *
     * @param string $branch Branch name to delete
     * @param string $remote Remote name (default: origin)
     */
    public function deleteRemoteBranch(string $branch, string $remote = self::ORIGIN_REMOTE): void
    {
        $this->git->deleteRemoteBranch($remote, $branch);
    }

    /**
     * Merge a branch into a path.
     *
     * @param string $path Repository path
     * @param string $branch Branch to merge
     * @param string $message Merge commit message
     */
    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->git->mergeBranchInPath($path, $branch, $message);
    }

    /**
     * Check if a branch has no development commits compared to base.
     *
     * @param string $base Base branch
     * @param string $branch Branch to check
     * @return bool True if branch has no commits since base
     */
    public function checkBranchHasNoDevelopment(string $base, string $branch): bool
    {
        return !$this->hasCommitsSince($base, $branch);
    }

    /**
     * Check if there are changed files since base.
     *
     * @param string $base Base commit/branch
     * @param string $branch Branch to check
     * @return bool True if there are changes
     */
    public function hasChangesSince(string $base, string $branch): bool
    {
        return $this->git->getChangedFiles($base, $branch) !== [];
    }

    /**
     * Check if there are commits since base.
     *
     * @param string $base Base commit/branch
     * @param string $branch Branch to check
     * @return bool True if branch has commits ahead of base
     */
    public function hasCommitsSince(string $base, string $branch): bool
    {
        return $this->git->countCommitsAhead($base, $branch) > 0;
    }

    /**
     * Push a branch and wait for it to become visible on remote.
     *
     * @param string $branch Branch to push
     * @param string $remote Remote name (default: origin)
     * @param string|null $worktree Optional worktree path
     */
    public function pushBranchAndAwaitVisibility(string $branch, string $remote = self::ORIGIN_REMOTE, ?string $worktree = null): void
    {
        $this->git->pushUpstream($branch, $remote, $worktree);
        $this->git->fetchRemoteBranch($branch, $remote, $worktree);
        $this->waitForRemoteBranchVisibility($branch, $remote);
    }

    /**
     * Push branch if it's ahead of remote.
     *
     * @param string $branch Branch to push
     * @param string $remote Remote name (default: origin)
     */
    public function pushBranchIfAhead(string $branch, string $remote = self::ORIGIN_REMOTE): void
    {
        if (!$this->git->localBranchExists($branch)) {
            return;
        }

        if (!$this->git->remoteBranchExists($remote, $branch)) {
            $this->pushBranchAndAwaitVisibility($branch, $remote);

            return;
        }

        if ($this->git->countCommitsAhead($remote . '/' . $branch, $branch) > 0) {
            $this->pushBranchAndAwaitVisibility($branch, $remote);
        }
    }

    /**
     * Check if workspace has local changes.
     *
     * @return bool True if there are uncommitted changes
     */
    public function checkWorkspaceHasLocalChanges(): bool
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace changes');

            return $this->git->hasLocalChanges();
        }

        return $this->git->hasLocalChanges();
    }

    /**
     * Get current branch of workspace.
     *
     * @return string Current branch name
     */
    public function getWorkspaceCurrentBranch(): string
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace branch');

            return $this->git->currentBranch();
        }

        return $this->git->currentBranch();
    }

    /**
     * Update main branch (fetch or pull depending on workspace state).
     */
    public function updateMainBranch(): void
    {
        if ($this->getWorkspaceCurrentBranch() !== self::MAIN_BRANCH) {
            $this->git->fetchRemoteBranch(self::MAIN_BRANCH, self::ORIGIN_REMOTE);

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

    /**
     * Checkout a branch and pull latest changes.
     *
     * @param string $branch Branch to checkout and pull
     */
    public function checkoutAndPull(string $branch): void
    {
        $this->git->checkoutAndPull($branch);
    }

    /**
     * Fetch a branch from remote.
     *
     * @param string $branch Branch to fetch
     * @param string $remote Remote name (default: origin)
     */
    public function fetchBranch(string $branch, string $remote = self::ORIGIN_REMOTE): void
    {
        $this->git->fetchLocalBranch($branch, $remote);
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

        // fetchRemoteBranch already succeeded before this call, so the branch is guaranteed to exist
        // on the remote at this point. This check is a defensive assertion, not a propagation wait.
        // No retry is needed: if ls-remote returns empty here it indicates a real error, not lag.
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
