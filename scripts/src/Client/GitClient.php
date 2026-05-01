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
    private ConsoleClient $console;
    private RetryPolicy $retryPolicy;

    /**
     * Initializes the Git client with console and retry policy.
     *
     * @param bool $dryRun If true, commands are logged but not executed
     * @param ConsoleClient $console Console client for command execution
     * @param RetryPolicy $retryPolicy Retry policy for network operations
     */
    public function __construct(
        bool $dryRun,
        ConsoleClient $console,
        RetryPolicy $retryPolicy,
    ) {
        $this->dryRun = $dryRun;
        $this->console = $console;
        $this->retryPolicy = $retryPolicy;
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
     * worktree state, such as fetch, pull, and push. In dry-run mode the command
     * is logged but not executed.
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
     * @param list<string> $files
     */
    public function updateIndexAssumeUnchanged(string $path, array $files): void
    {
        if ($files === []) {
            return;
        }

        $this->run($this->inPath(
            $path,
            'update-index --assume-unchanged -- ' . implode(' ', array_map('escapeshellarg', $files))
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
     * Checks out a branch and pulls the latest changes.
     *
     * @param string $branch Branch to checkout and pull
     */
    public function checkoutAndPull(string $branch): void
    {
        $this->run(sprintf('git checkout %s', escapeshellarg($branch)));
        $this->runNetwork('git pull');
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
     * Gets the Git internal path for a subpath in a repository.
     *
     * @param string $path Repository path
     * @param string $subPath Subpath to resolve (e.g., "hooks", "info")
     * @return string The resolved git path
     */
    public function getGitPath(string $path, string $subPath): string
    {
        return trim($this->captureReadonly($this->inPath($path, sprintf('rev-parse --git-path %s', escapeshellarg($subPath)))));
    }

    /**
     * @return list<string>
     */
    public function getTrackedFiles(string $path, string $dir): array
    {
        return array_values(array_filter(explode("\n", trim($this->captureReadonly($this->inPath(
            $path,
            sprintf('ls-files %s', escapeshellarg($dir))
        ))))));
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

    private function networkRetryHelper(): RetryHelper
    {
        return $this->retryPolicy->createHelper();
    }
}
