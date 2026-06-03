<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client;

/**
 * Contract for local and remote Git repository operations.
 */
interface GitClientInterface
{
    /**
     * Returns true when network commands are disabled on this client instance.
     */
    public function isNetworkDisabled(): bool;

    /**
     * Runs a local Git mutation command. Logged and skipped in dry-run mode.
     */
    public function run(string $command): void;

    /**
     * Captures output from a read-only Git command.
     */
    public function captureReadonly(string $command): string;

    /**
     * Checks whether a read-only Git command succeeds.
     */
    public function checkReadonly(string $command): bool;

    /**
     * Runs a Git network command with retry policy. No-op if network is disabled.
     */
    public function runNetwork(string $command): void;

    /**
     * Captures output from a Git network command with retry policy. Empty string if network is disabled.
     */
    public function captureNetwork(string $command): string;

    /**
     * Runs a Git command inside the given worktree path and captures its output.
     */
    public function inPath(string $path, string $subCommand): string;

    /**
     * Pushes a branch to the given remote, setting upstream on the first push.
     */
    public function pushUpstream(string $branch, string $remote = 'origin', ?string $worktree = null): void;

    /**
     * Force-pushes a branch using --force-with-lease against the expected remote SHA.
     */
    public function pushForceWithLease(string $branch, string $remote, string $expectedRemoteSha, ?string $worktree = null): void;

    /**
     * Fetches refs from the given remote, optionally a single branch or into a specific destination.
     */
    public function fetch(string $remote = 'origin', ?string $branch = null, ?string $destination = null, ?string $worktree = null): void;

    /**
     * Returns true when the remote branch is currently visible from this machine.
     */
    public function isRemoteBranchVisible(string $branch, string $remote = 'origin'): bool;

    /**
     * Adds a detached worktree at the given path.
     */
    public function addWorktreeDetach(string $path): void;

    /**
     * Adds a worktree at the given path checked out on the given branch.
     */
    public function addWorktree(string $path, string $branch): void;

    /**
     * Force-removes the worktree at the given path.
     */
    public function removeWorktreeForce(string $path): void;

    /**
     * Creates a local branch from the given start point.
     */
    public function createBranch(string $branch, string $startPoint): void;

    /**
     * Checks out the given branch inside the worktree at the given path.
     */
    public function checkoutBranch(string $path, string $branch): void;

    /**
     * Checks out a new branch created from the given start point inside the worktree at the given path.
     */
    public function checkoutBranchCreate(string $path, string $branch, string $startPoint): void;

    /**
     * Returns the SHA at the head of the given branch.
     */
    public function branchHead(string $branch): string;

    /**
     * Returns true when the given Git ref exists locally.
     */
    public function refExists(string $ref): bool;

    /**
     * Returns the common ancestor SHA between two refs.
     */
    public function mergeBase(string $left, string $right): string;

    /**
     * Returns true when $ancestor is reachable from $descendant.
     */
    public function isAncestor(string $ancestor, string $descendant): bool;

    /**
     * Returns true when the given local branch exists.
     */
    public function localBranchExists(string $branch): bool;

    /**
     * Returns true when the given remote branch exists.
     */
    public function remoteBranchExists(string $remote, string $branch): bool;

    /**
     * Deletes the given local branch.
     */
    public function deleteLocalBranch(string $branch): void;

    /**
     * Deletes the given remote branch.
     */
    public function deleteRemoteBranch(string $remote, string $branch): void;

    /**
     * Rebases the branch checked out at the given path onto the given target.
     */
    public function rebaseInPath(string $path, string $onto): void;

    /**
     * Aborts an in-progress rebase inside the given worktree path.
     */
    public function rebaseAbortInPath(string $path): void;

    /**
     * Returns the list of unmerged paths inside the given worktree.
     *
     * @return list<string>
     */
    public function getUnmergedFilesInPath(string $path): array;

    /**
     * Merges the given branch into the worktree at the given path with the given commit message.
     */
    public function mergeBranchInPath(string $path, string $branch, string $message): void;

    /**
     * Returns the number of commits the branch is ahead of the base.
     */
    public function countCommitsAhead(string $base, string $branch): int;

    /**
     * Returns true when the worktree at the given path has local changes (staged or unstaged).
     */
    public function hasLocalChanges(string $path = '.'): bool;

    /**
     * Returns the current branch name in the worktree at the given path.
     */
    public function currentBranch(string $path = '.'): string;

    /**
     * Pulls the current branch fast-forward only.
     */
    public function pullFastForwardOnly(): void;

    /**
     * Returns the list of files changed on the branch compared to base.
     *
     * @return list<string>
     */
    public function getChangedFiles(string $base, string $branch): array;

    /**
     * Returns the porcelain v1 output of `git worktree list`.
     */
    public function listWorktreesPorcelain(): string;

    /**
     * Returns true when the given branch is currently checked out in any worktree.
     */
    public function isBranchCheckedOutInWorktree(string $branch): bool;

    /**
     * Advances the given local branch to the given commit or ref.
     */
    public function advanceLocalBranch(string $branch, string $to): void;

    /**
     * Returns the result of `git rev-parse <flag>` inside the worktree at the given path, or null on failure.
     */
    public function revParseInPath(string $path, string $flag): ?string;

    /**
     * Returns the one-line commit log between $base and $head.
     */
    public function logOneline(string $base, string $head): string;

    /**
     * Returns the diff stat between $base and $head.
     */
    public function diffStat(string $base, string $head): string;

    /**
     * Returns the full diff between $base and $head.
     */
    public function fullDiff(string $base, string $head): string;

    /**
     * Returns the URL of the given remote.
     */
    public function remoteUrl(string $remote = 'origin'): string;

    /**
     * Converts an absolute path to a path relative to the project root, or returns it unchanged if outside.
     */
    public function toRelativeProjectPath(string $path): string;
}
