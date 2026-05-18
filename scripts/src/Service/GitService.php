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
     * Rebase the current branch of a worktree onto a target ref.
     *
     * On rebase failure (typically a conflict) the rebase is aborted to leave
     * the worktree in a clean state, and a RuntimeException is re-thrown with a
     * recovery hint pointing to the manual resolution flow.
     *
     * @param string $worktree Worktree path to rebase in
     * @param string $onto Target ref to rebase onto
     * @throws \RuntimeException When the rebase fails after the abort
     */
    public function rebaseBranchOnto(string $worktree, string $onto): void
    {
        try {
            $this->git->rebaseInPath($worktree, $onto);
        } catch (\RuntimeException $exception) {
            $this->git->rebaseAbortInPath($worktree);

            throw new \RuntimeException(sprintf(
                "Cannot rebase onto %s automatically. The rebase was aborted, leaving the worktree clean. Update the branch manually in the worktree (rebase or merge onto %s and resolve the conflicts), then rerun review-request.\nDetail: %s",
                $onto,
                $onto,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * Attempts a rebase without aborting on conflict.
     *
     * Returns an empty array when the rebase succeeds.
     * Returns a non-empty list of conflicting file paths when the rebase stops
     * on a conflict, leaving the worktree in "rebase in progress" state so the
     * caller (agent or operator) can resolve the conflicts and continue the rebase.
     *
     * @param string $worktree Worktree path to rebase in
     * @param string $onto Target ref to rebase onto
     * @return list<string> Empty on success; conflict file list on conflict
     */
    public function tryRebaseInPath(string $worktree, string $onto): array
    {
        try {
            $this->git->rebaseInPath($worktree, $onto);

            return [];
        } catch (\RuntimeException) {
            return $this->git->getUnmergedFilesInPath($worktree);
        }
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
        $this->git->fetch($remote, $branch, worktree: $worktree);
        $this->waitForRemoteBranchVisibility($branch, $remote);
    }

    /**
     * Push a branch to a remote with safe-force semantics.
     *
     * Refreshes `origin/<branch>` first to observe the current remote SHA,
     * then decides the push mode without ever using an unprotected `--force`:
     * - remote branch does not exist: normal upstream push (creates it);
     * - local is descendant of (or equal to) remote: normal upstream push (fast-forward);
     * - local has diverged from remote (typical of a rebased branch): push
     *   with `--force-with-lease=<branch>:<observed-sha>` so the push fails if
     *   the remote moved between the observation and the push;
     * - local is behind remote: refuse with a clear error.
     *
     * After a successful push, fetches and waits for the new remote ref to
     * become visible.
     *
     * @param string $branch Branch name to push
     * @param string $remote Remote name (default: origin)
     * @param string|null $worktree Optional worktree path
     * @throws \RuntimeException When the local branch is behind the remote or
     *                           the remote SHA cannot be observed
     */
    public function pushBranchSafely(string $branch, string $remote = self::ORIGIN_REMOTE, ?string $worktree = null): void
    {
        // ls-remote first so we never call `git fetch <remote> <missing-branch>`,
        // which would fail with "couldn't find remote ref" on the initial push.
        if (!$this->git->isRemoteBranchVisible($branch, $remote)) {
            $this->pushBranchAndAwaitVisibility($branch, $remote, $worktree);

            return;
        }

        $this->git->fetch($remote, $branch, worktree: $worktree);

        $remoteRef = $remote . '/' . $branch;
        $remoteSha = $this->git->branchHead($remoteRef);
        if ($remoteSha === '') {
            throw new \RuntimeException(sprintf(
                'Cannot push branch %s safely: unable to observe %s SHA.',
                $branch,
                $remoteRef,
            ));
        }

        if ($this->git->isAncestor($remoteRef, $branch)) {
            $this->pushBranchAndAwaitVisibility($branch, $remote, $worktree);

            return;
        }

        if ($this->git->isAncestor($branch, $remoteRef)) {
            throw new \RuntimeException(sprintf(
                'Cannot push branch %s safely: local branch is behind %s. Pull or rebase before retrying.',
                $branch,
                $remoteRef,
            ));
        }

        $this->git->pushForceWithLease($branch, $remote, $remoteSha, $worktree);
        $this->git->fetch($remote, $branch, worktree: $worktree);
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
     *
     * Uses origin/main as the authoritative source of truth. After fetching, attempts to advance
     * the local main branch in best-effort mode: warns and skips when main is checked out in
     * another worktree or has diverged from origin/main.
     */
    public function updateMainBranch(): void
    {
        if ($this->getWorkspaceCurrentBranch() === self::MAIN_BRANCH) {
            $this->updateLocalMainBranchWithWarning();

            return;
        }

        $this->git->fetch(self::ORIGIN_REMOTE, self::MAIN_BRANCH);
        $this->tryAdvanceLocalMainBranch();
    }

    /**
     * Sync the local main branch after a feature merge in best-effort mode.
     *
     * Fetches origin/main, then attempts to advance local main without blocking
     * the merge workflow. Any failure is reported as a warning and does not
     * interrupt the caller.
     */
    public function syncMainBranchAfterMerge(): void
    {
        try {
            $this->updateMainBranch();
        } catch (\RuntimeException $exception) {
            $this->console->warn('Unable to sync local main after merge; continuing with current state.');
            $this->logVerbose('Main sync warning: ' . $exception->getMessage());
        }
    }

    /**
     * @return array<string>
     */
    public function getChangedFiles(string $base, string $branch): array
    {
        return $this->git->getChangedFiles($base, $branch);
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

    private function tryAdvanceLocalMainBranch(): void
    {
        if (!$this->git->localBranchExists(self::MAIN_BRANCH)) {
            return;
        }

        if ($this->git->isBranchCheckedOutInWorktree(self::MAIN_BRANCH)) {
            $this->console->warn('Local main is checked out in another worktree; skipping best-effort sync.');

            return;
        }

        $originMain = self::ORIGIN_REMOTE . '/' . self::MAIN_BRANCH;
        if (!$this->git->isAncestor(self::MAIN_BRANCH, $originMain)) {
            $this->console->warn('Local main has diverged from origin/main; skipping best-effort sync.');

            return;
        }

        try {
            $this->git->advanceLocalBranch(self::MAIN_BRANCH, $originMain);
        } catch (\RuntimeException $exception) {
            $this->console->warn('Unable to advance local main to origin/main; continuing with current state.');
            $this->logVerbose('Main sync warning: ' . $exception->getMessage());
        }
    }

    private function waitForRemoteBranchVisibility(string $branch, string $remote): void
    {
        if ($this->dryRun) {
            return;
        }

        // fetch already succeeded before this call, so the branch is guaranteed to exist
        // on the remote at this point. This check is a defensive assertion, not a propagation wait.
        // No retry is needed: if ls-remote returns empty here it indicates a real error, not lag.
        if ($this->git->isRemoteBranchVisible($branch, $remote)) {
            return;
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    /**
     * Returns `git log --oneline` commits between base and head.
     */
    public function getLogOneline(string $base, string $head): string
    {
        return $this->git->logOneline($base, $head);
    }

    /**
     * Returns `git diff --stat` between base and head.
     */
    public function getDiffStat(string $base, string $head): string
    {
        return $this->git->diffStat($base, $head);
    }

    /**
     * Returns the full `git diff` between base and head.
     */
    public function getFullDiff(string $base, string $head): string
    {
        return $this->git->fullDiff($base, $head);
    }

    /**
     * Returns the URL of a remote.
     */
    public function getRemoteUrl(string $remote = self::ORIGIN_REMOTE): string
    {
        return $this->git->remoteUrl($remote);
    }

    private function logVerbose(string $message): void
    {
        ($this->verboseLogger)($message);
    }
}
