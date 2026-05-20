<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

use SoManAgent\Script\RetryHelper;
use SoManAgent\Script\RetryPolicy;

/**
 * Git command client for local and remote repository operations.
 *
 * Set `$networkDisabled` to true to leave local Git commands enabled while
 * turning network commands into logged no-ops. Script factories derive this
 * flag from the truthy `SOMANAGER_GIT_OFFLINE` environment variable.
 */
final class GitClient
{
    private const NETWORK_ERROR_NEEDLES = [
        'fatal: unable to access',
        'Could not resolve host:',
        'Connection timed out',
        'Failed to connect',
        'Operation timed out',
        'Temporary failure in name resolution',
    ];

    private bool $dryRun;
    private bool $networkDisabled;
    private ConsoleClient $console;
    private RetryPolicy $retryPolicy;

    /**
     * Initializes the Git client with console and retry policy.
     *
     * @param bool $dryRun If true, all mutation commands are logged but not executed
     * @param ConsoleClient $console Console client for command execution
     * @param RetryPolicy $retryPolicy Retry policy for network operations
     * @param bool $networkDisabled If true, only network commands are logged but not executed
     */
    public function __construct(
        bool $dryRun,
        ConsoleClient $console,
        RetryPolicy $retryPolicy,
        bool $networkDisabled = false,
    ) {
        $this->dryRun = $dryRun;
        $this->networkDisabled = $networkDisabled;
        $this->console = $console;
        $this->retryPolicy = $retryPolicy;
    }

    public static function shouldDisableNetworkFromEnvironment(): bool
    {
        $value = getenv('SOMANAGER_GIT_OFFLINE');

        return is_string($value) && self::isTruthyEnvironmentValue($value);
    }

    public function isNetworkDisabled(): bool
    {
        return $this->networkDisabled;
    }

    /**
     * Runs a local Git mutation command.
     *
     * Use this for commands that can change the repository or worktree state.
     * In dry-run mode the command is logged but not executed.
     */
    public function run(string $command): void
    {
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would run git command: ' : 'Run git command: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $this->console->run($command);
    }

    /**
     * Captures output from a read-only Git command.
     *
     * Use this only for inspections that do not modify repository, worktree,
     * index, remotes, or configuration state. Read-only commands still run in
     * dry-run mode because dry-run only suppresses mutations.
     */
    public function captureReadonly(string $command): string
    {
        $this->console->logVerbose('Capture git output: ' . $command);

        return $this->console->capture($command);
    }

    /**
     * Checks whether a read-only Git command succeeds.
     *
     * Use this only for inspections that do not modify repository, worktree,
     * index, remotes, or configuration state. Read-only checks still run in
     * dry-run mode because dry-run only suppresses mutations.
     */
    public function checkReadonly(string $command): bool
    {
        $this->console->logVerbose('Check git command success: ' . $command);

        return $this->console->succeeds($command);
    }

    /**
     * Runs a network Git mutation command with retry handling.
     *
     * Use this for network commands that can change local refs, remote refs, or
     * worktree state, such as fetch, pull, and push. In dry-run or network
     * disabled mode the command is logged but not executed.
     */
    public function runNetwork(string $command): void
    {
        [$code, $output] = $this->captureNetworkWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    /**
     * Executes a network Git command and returns its output.
     *
     * @param string $command The git command to execute
     * @return string The command output
     * @throws \RuntimeException If the command fails
     */
    public function captureNetwork(string $command): string
    {
        [$code, $output] = $this->captureNetworkWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

        return $output;
    }

    /**
     * Builds a git command that operates on a specific repository path.
     *
     * @param string $path Repository path
     * @param string $subCommand Git subcommand to run
     * @return string The complete git command with -C flag
     */
    public function inPath(string $path, string $subCommand): string
    {
        return sprintf(
            'git -C %s %s',
            escapeshellarg($this->console->toRelativeProjectPath($path)),
            $subCommand,
        );
    }

    /**
     * Pushes a branch to the remote with upstream tracking.
     *
     * @param string $branch Branch name to push
     * @param string $remote Remote name (default: origin)
     * @param string|null $worktree Optional worktree path to run command in
     */
    public function pushUpstream(string $branch, string $remote = 'origin', ?string $worktree = null): void
    {
        $command = sprintf('push -u %s %s', escapeshellarg($remote), escapeshellarg($branch));
        if ($worktree !== null) {
            $command = $this->inPath($worktree, $command);
        } else {
            $command = 'git ' . $command;
        }

        $this->runNetwork($command);
    }

    /**
     * Pushes a branch to a remote with `--force-with-lease` locked to an observed SHA.
     *
     * The push only succeeds when the remote ref still points to
     * `$expectedRemoteSha` at the moment of the push, preventing the local
     * branch from clobbering remote commits that appeared after the
     * observation. Always sets the upstream tracking with `-u`.
     *
     * @param string $branch Branch name to push
     * @param string $remote Remote name
     * @param string $expectedRemoteSha Observed remote SHA used as the lease lock
     * @param string|null $worktree Optional worktree path to run the command in
     */
    public function pushForceWithLease(string $branch, string $remote, string $expectedRemoteSha, ?string $worktree = null): void
    {
        $subCommand = sprintf(
            'push --force-with-lease=%s:%s -u %s %s',
            escapeshellarg($branch),
            escapeshellarg($expectedRemoteSha),
            escapeshellarg($remote),
            escapeshellarg($branch),
        );
        $command = $worktree !== null
            ? $this->inPath($worktree, $subCommand)
            : 'git ' . $subCommand;

        $this->runNetwork($command);
    }

    /**
     * @param string $remote
     * @param string|null $branch
     * @param string|null $destination
     * @param string|null $worktree
     */
    public function fetch(string $remote = 'origin', ?string $branch = null, ?string $destination = null, ?string $worktree = null): void
    {
        $parts = ['fetch', escapeshellarg($remote)];
        if ($branch !== null) {
            $parts[] = $destination !== null
                ? escapeshellarg($branch) . ':' . escapeshellarg($destination)
                : escapeshellarg($branch);
        }
        $subCommand = implode(' ', $parts);
        $command = $worktree !== null
            ? $this->inPath($worktree, $subCommand)
            : 'git ' . $subCommand;
        $this->runNetwork($command);
    }

    /**
     * Checks if a remote branch exists and is visible.
     *
     * @param string $branch Branch name to check
     * @param string $remote Remote name (default: origin)
     * @return bool True if the branch exists on the remote
     */
    public function isRemoteBranchVisible(string $branch, string $remote = 'origin'): bool
    {
        $output = $this->captureNetwork(sprintf(
            'git ls-remote --heads %s %s',
            escapeshellarg($remote),
            escapeshellarg($branch),
        ));

        return trim($output) !== '';
    }

    /**
     * Creates a detached worktree at the specified path.
     *
     * @param string $path Path where the worktree will be created
     */
    public function addWorktreeDetach(string $path): void
    {
        $this->run(sprintf(
            'git worktree add --detach %s HEAD',
            escapeshellarg($this->toRelativeProjectPath($path))
        ));
    }

    /**
     * Creates a new worktree for a specific branch.
     *
     * @param string $path Path where the worktree will be created
     * @param string $branch Branch name for the worktree
     */
    public function addWorktree(string $path, string $branch): void
    {
        $this->run(sprintf(
            'git worktree add %s %s',
            escapeshellarg($this->toRelativeProjectPath($path)),
            escapeshellarg($branch)
        ));
    }

    /**
     * Force removes a worktree at the specified path.
     *
     * @param string $path Path of the worktree to remove
     */
    public function removeWorktreeForce(string $path): void
    {
        $this->run(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path))
        ));
    }

    /**
     * Creates a new branch at the specified start point.
     *
     * @param string $branch Name of the branch to create
     * @param string $startPoint Commit or branch to start from
     */
    public function createBranch(string $branch, string $startPoint): void
    {
        $this->run(sprintf(
            'git branch %s %s',
            escapeshellarg($branch),
            escapeshellarg($startPoint)
        ));
    }

    /**
     * Checks out an existing branch in the specified path.
     *
     * @param string $path Repository path
     * @param string $branch Branch name to checkout
     */
    public function checkoutBranch(string $path, string $branch): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('checkout %s', escapeshellarg($branch))
        ));
    }

    /**
     * Creates and checks out a new branch from a start point.
     *
     * @param string $path Repository path
     * @param string $branch Name of the new branch
     * @param string $startPoint Commit or branch to start from
     */
    public function checkoutBranchCreate(string $path, string $branch, string $startPoint): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint))
        ));
    }

    /**
     * Gets the commit hash (HEAD) of a local branch.
     *
     * @param string $branch Branch name
     * @return string The commit hash
     */
    public function branchHead(string $branch): string
    {
        return trim($this->captureReadonly(sprintf('git rev-parse %s', escapeshellarg($branch))));
    }

    /**
     * Checks if a Git reference (branch, tag, or commit) exists.
     *
     * @param string $ref The reference to check
     * @return bool True if the reference exists
     */
    public function refExists(string $ref): bool
    {
        return $this->checkReadonly(sprintf('git rev-parse --verify --quiet %s', escapeshellarg($ref . '^{commit}')));
    }

    /**
     * Finds the merge base between two Git references.
     *
     * @param string $left First reference
     * @param string $right Second reference
     * @return string The merge base commit hash
     */
    public function mergeBase(string $left, string $right): string
    {
        return trim($this->captureReadonly(sprintf(
            'git merge-base %s %s',
            escapeshellarg($left),
            escapeshellarg($right)
        )));
    }

    /**
     * Checks if one commit is an ancestor of another.
     *
     * @param string $ancestor Potential ancestor commit
     * @param string $descendant Potential descendant commit
     * @return bool True if ancestor is indeed an ancestor of descendant
     */
    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->checkReadonly(sprintf(
            'git merge-base --is-ancestor %s %s',
            escapeshellarg($ancestor),
            escapeshellarg($descendant)
        ));
    }

    /**
     * Checks if a local branch exists.
     *
     * @param string $branch Branch name
     * @return bool True if the local branch exists
     */
    public function localBranchExists(string $branch): bool
    {
        return $this->checkReadonly(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)));
    }

    /**
     * Checks if a remote branch exists.
     *
     * @param string $remote Remote name
     * @param string $branch Branch name
     * @return bool True if the remote branch exists
     */
    public function remoteBranchExists(string $remote, string $branch): bool
    {
        return $this->checkReadonly(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/remotes/' . $remote . '/' . $branch)));
    }

    /**
     * Deletes a local branch (force delete).
     *
     * @param string $branch Branch name to delete
     */
    public function deleteLocalBranch(string $branch): void
    {
        $this->run(sprintf('git branch -D %s', escapeshellarg($branch)));
    }

    /**
     * Deletes a remote branch.
     *
     * @param string $remote Remote name
     * @param string $branch Branch name to delete
     */
    public function deleteRemoteBranch(string $remote, string $branch): void
    {
        $this->runNetwork(sprintf('git push %s --delete %s', escapeshellarg($remote), escapeshellarg($branch)));
    }

    /**
     * Rebases the current branch of a worktree onto a target ref.
     *
     * Captures stdout+stderr so callers can include the rebase output in error
     * messages on conflict.
     *
     * @param string $path Worktree path to rebase in
     * @param string $onto Target ref to rebase onto
     * @throws \RuntimeException When the rebase fails
     */
    public function rebaseInPath(string $path, string $onto): void
    {
        $command = $this->inPath($path, sprintf('rebase %s', escapeshellarg($onto)));
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would run git command: ' : 'Run git command: ') . $command);
        if ($this->dryRun) {
            return;
        }

        [$code, $output] = $this->console->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Rebase failed with exit code %d in worktree %s:\n%s",
                $code,
                $this->toRelativeProjectPath($path),
                $output,
            ));
        }
    }

    /**
     * Aborts an in-progress rebase in a worktree.
     *
     * Swallows the non-zero exit code that `git rebase --abort` returns when
     * there is no rebase in progress, so this method is safe to call as a
     * cleanup step after a failed rebase attempt.
     *
     * @param string $path Worktree path to abort the rebase in
     */
    public function rebaseAbortInPath(string $path): void
    {
        $command = $this->inPath($path, 'rebase --abort');
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would run git command: ' : 'Run git command: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $this->console->captureWithExitCode($command);
    }

    /**
     * Lists files with unresolved merge conflicts in the given worktree.
     *
     * Safe to call while a rebase or merge is in progress.
     *
     * @param string $path Worktree path
     * @return list<string>
     */
    public function getUnmergedFilesInPath(string $path): array
    {
        $command = $this->inPath($path, 'diff --name-only --diff-filter=U');
        $output = trim($this->captureReadonly($command));

        return $output !== '' ? array_values(array_filter(explode("\n", $output))) : [];
    }

    /**
     * Merges a branch into the current branch in a repository path.
     *
     * @param string $path Repository path
     * @param string $branch Branch to merge
     * @param string $message Merge commit message
     */
    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('merge --no-ff %s -m %s', escapeshellarg($branch), escapeshellarg($message))
        ));
    }

    /**
     * Counts the number of commits between base and branch.
     *
     * @param string $base Base commit or branch
     * @param string $branch Branch to compare
     * @return int Number of commits ahead of base
     */
    public function countCommitsAhead(string $base, string $branch): int
    {
        return (int) trim($this->captureReadonly(sprintf('git rev-list --count %s..%s', escapeshellarg($base), escapeshellarg($branch))));
    }

    /**
     * Checks if there are uncommitted changes in the repository.
     *
     * @param string $path Repository path (default: current directory)
     * @return bool True if there are local changes
     */
    public function hasLocalChanges(string $path = '.'): bool
    {
        if ($path === '.') {
            return trim($this->captureReadonly('git status --short')) !== '';
        }
        return trim($this->captureReadonly($this->inPath($path, 'status --short'))) !== '';
    }

    /**
     * Gets the name of the current branch.
     *
     * @param string $path Repository path (default: current directory)
     * @return string Current branch name, or empty string if not on a branch
     */
    public function currentBranch(string $path = '.'): string
    {
        if ($path === '.') {
            return trim($this->captureReadonly('git branch --show-current'));
        }
        
        if ($this->checkReadonly($this->inPath($path, 'symbolic-ref --quiet --short HEAD'))) {
            return trim($this->captureReadonly($this->inPath($path, 'symbolic-ref --quiet --short HEAD')));
        }
        
        return '';
    }

    /**
     * Pulls changes using fast-forward only strategy.
     */
    public function pullFastForwardOnly(): void
    {
        $this->runNetwork('git pull --ff-only');
    }

    /**
     * @return list<string>
     */
    public function getChangedFiles(string $base, string $branch): array
    {
        return array_values(array_filter(explode("\n", trim($this->captureReadonly(sprintf(
            'git diff --name-only %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch)
        ))))));
    }

    /**
     * Lists all worktrees in porcelain format.
     *
     * @return string Worktree list in porcelain format
     */
    public function listWorktreesPorcelain(): string
    {
        return trim($this->captureReadonly('git worktree list --porcelain'));
    }

    /**
     * Checks if a local branch is currently checked out in any worktree.
     *
     * @param string $branch Branch name
     * @return bool True if the branch is checked out in any worktree
     */
    public function isBranchCheckedOutInWorktree(string $branch): bool
    {
        $output = $this->listWorktreesPorcelain();

        return in_array('branch refs/heads/' . $branch, explode("\n", $output), true);
    }

    /**
     * Advances a local branch to point to the given target without network access.
     *
     * @param string $branch Branch name to advance
     * @param string $to Target reference to advance to
     */
    public function advanceLocalBranch(string $branch, string $to): void
    {
        $this->run(sprintf('git branch -f %s %s', escapeshellarg($branch), escapeshellarg($to)));
    }

    /**
     * Runs a rev-parse flag in the given directory and returns the trimmed output, or null on failure.
     *
     * @param string $path Directory to run git in
     * @param string $flag rev-parse flag (e.g. '--git-dir', '--show-toplevel')
     */
    public function revParseInPath(string $path, string $flag): ?string
    {
        $cmd = sprintf('git -C %s rev-parse %s', escapeshellarg($path), $flag);
        [$code, $output] = $this->console->captureWithExitCode($cmd);
        if ($code !== 0) {
            return null;
        }
        $result = trim($output);

        return $result !== '' ? $result : null;
    }

    /**
     * Returns `git log --oneline` commits between base and head.
     */
    public function logOneline(string $base, string $head): string
    {
        return trim($this->captureReadonly(sprintf(
            'git log --oneline %s..%s',
            escapeshellarg($base),
            escapeshellarg($head),
        )));
    }

    /**
     * Returns `git diff --stat` between base and head.
     */
    public function diffStat(string $base, string $head): string
    {
        return trim($this->captureReadonly(sprintf(
            'git diff --stat %s..%s',
            escapeshellarg($base),
            escapeshellarg($head),
        )));
    }

    /**
     * Returns the full `git diff` between base and head.
     */
    public function fullDiff(string $base, string $head): string
    {
        return trim($this->captureReadonly(sprintf(
            'git diff %s..%s',
            escapeshellarg($base),
            escapeshellarg($head),
        )));
    }

    /**
     * Returns the URL of a remote.
     */
    public function remoteUrl(string $remote = 'origin'): string
    {
        return trim($this->captureReadonly(sprintf('git remote get-url %s', escapeshellarg($remote))));
    }

    /**
     * Converts an absolute path to a path relative to the project root.
     *
     * @param string $path The path to convert
     * @return string Relative path from project root
     */
    public function toRelativeProjectPath(string $path): string
    {
        return $this->console->toRelativeProjectPath($path);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function captureNetworkWithExitCode(string $command): array
    {
        if ($this->dryRun) {
            $this->console->logVerbose('[dry-run] Would run git network command: ' . $command);

            return [0, ''];
        }

        if ($this->networkDisabled) {
            $this->console->logVerbose('[git-offline] Skip git network command: ' . $command);

            return [0, ''];
        }

        $result = $this->networkRetryHelper()->run(
            fn(): array => $this->console->captureWithExitCode($command),
            fn(array $result): bool => $result[0] !== 0 && $this->isRetryableNetworkError($result[1]),
        );

        if ($result[0] !== 0 && $this->isRetryableNetworkError($result[1])) {
            throw new \RuntimeException(sprintf(
                "Git network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
                $this->retryPolicy->getRetryCount(),
                $command,
                $result[1],
            ));
        }

        return $result;
    }

    private function isRetryableNetworkError(string $output): bool
    {
        foreach (self::NETWORK_ERROR_NEEDLES as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isTruthyEnvironmentValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        if ($normalized === '0') {
            return false;
        }

        if ($normalized === 'false') {
            return false;
        }

        if ($normalized === 'no') {
            return false;
        }

        if ($normalized === 'off') {
            return false;
        }

        return true;
    }

    private function networkRetryHelper(): RetryHelper
    {
        return $this->retryPolicy->createHelper();
    }
}
