<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

use SoManAgent\Script\RetryHelper;

/**
 * Git command client for local and remote repository operations.
 */
final class GitClient
{
    private bool $dryRun;
    private ConsoleClient $console;

    /** @var array<string> */
    private array $networkErrorNeedles;

    private int $retryCount;
    private int $retryBaseDelay;
    private int $retryFactor;

    /**
     * @param array<string> $networkErrorNeedles
     */
    public function __construct(
        bool $dryRun,
        ConsoleClient $console,
        array $networkErrorNeedles = [],
        int $retryCount = 0,
        int $retryBaseDelay = 0,
        int $retryFactor = 0,
    ) {
        $this->dryRun = $dryRun;
        $this->console = $console;
        $this->networkErrorNeedles = $networkErrorNeedles;
        $this->retryCount = $retryCount;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->retryFactor = $retryFactor;
    }

    public function run(string $command): void
    {
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would run git command: ' : 'Run git command: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $this->console->run($command);
    }

    public function capture(string $command): string
    {
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would capture git output: ' : 'Capture git output: ') . $command);
        if ($this->dryRun) {
            return '';
        }

        return $this->console->capture($command);
    }

    public function succeeds(string $command): bool
    {
        $this->console->logVerbose(($this->dryRun ? '[dry-run] Would check git command success: ' : 'Check git command success: ') . $command);
        if ($this->dryRun) {
            return false;
        }

        return $this->console->succeeds($command);
    }

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

    public function inPath(string $path, string $subCommand): string
    {
        return sprintf(
            'git -C %s %s',
            escapeshellarg($this->console->toRelativeProjectPath($path)),
            $subCommand,
        );
    }

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

    public function fetchRemoteBranch(string $branch, string $remote = 'origin', ?string $worktree = null): void
    {
        $command = sprintf(
            'fetch %s %s:%s',
            escapeshellarg($remote),
            escapeshellarg($branch),
            escapeshellarg('refs/remotes/' . $remote . '/' . $branch)
        );
        if ($worktree !== null) {
            $command = $this->inPath($worktree, $command);
        } else {
            $command = 'git ' . $command;
        }

        $this->runNetwork($command);
    }

    public function isRemoteBranchVisible(string $branch, string $remote = 'origin'): bool
    {
        $output = $this->captureNetwork(sprintf(
            'git ls-remote --heads %s %s',
            escapeshellarg($remote),
            escapeshellarg($branch),
        ));

        return trim($output) !== '';
    }

    public function addWorktreeDetach(string $path): void
    {
        $this->run(sprintf(
            'git worktree add --detach %s HEAD',
            escapeshellarg($this->toRelativeProjectPath($path))
        ));
    }

    public function addWorktree(string $path, string $branch): void
    {
        $this->run(sprintf(
            'git worktree add %s %s',
            escapeshellarg($this->toRelativeProjectPath($path)),
            escapeshellarg($branch)
        ));
    }

    public function removeWorktreeForce(string $path): void
    {
        $this->run(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path))
        ));
    }

    public function createBranch(string $branch, string $startPoint): void
    {
        $this->run(sprintf(
            'git branch %s %s',
            escapeshellarg($branch),
            escapeshellarg($startPoint)
        ));
    }

    public function checkoutBranch(string $path, string $branch): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('checkout %s', escapeshellarg($branch))
        ));
    }

    public function checkoutBranchCreate(string $path, string $branch, string $startPoint): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint))
        ));
    }

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

    public function branchHead(string $branch): string
    {
        return trim($this->capture(sprintf('git rev-parse %s', escapeshellarg($branch))));
    }

    public function localBranchExists(string $branch): bool
    {
        return $this->succeeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/heads/' . $branch)));
    }

    public function remoteBranchExists(string $remote, string $branch): bool
    {
        return $this->succeeds(sprintf('git show-ref --verify --quiet %s', escapeshellarg('refs/remotes/' . $remote . '/' . $branch)));
    }

    public function deleteLocalBranch(string $branch): void
    {
        $this->run(sprintf('git branch -D %s', escapeshellarg($branch)));
    }

    public function deleteRemoteBranch(string $remote, string $branch): void
    {
        $this->runNetwork(sprintf('git push %s --delete %s', escapeshellarg($remote), escapeshellarg($branch)));
    }

    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->run($this->inPath(
            $path,
            sprintf('merge --no-ff %s -m %s', escapeshellarg($branch), escapeshellarg($message))
        ));
    }

    public function countCommitsAhead(string $base, string $branch): int
    {
        return (int) trim($this->capture(sprintf('git rev-list --count %s..%s', escapeshellarg($base), escapeshellarg($branch))));
    }

    public function hasLocalChanges(string $path = '.'): bool
    {
        if ($path === '.') {
            return trim($this->capture('git status --short')) !== '';
        }
        return trim($this->capture($this->inPath($path, 'status --short'))) !== '';
    }

    public function currentBranch(string $path = '.'): string
    {
        if ($path === '.') {
            return trim($this->capture('git branch --show-current'));
        }
        
        if ($this->succeeds($this->inPath($path, 'symbolic-ref --quiet --short HEAD'))) {
            return trim($this->capture($this->inPath($path, 'symbolic-ref --quiet --short HEAD')));
        }
        
        return '';
    }

    public function pullFastForwardOnly(): void
    {
        $this->runNetwork('git pull --ff-only');
    }

    public function checkoutAndPull(string $branch): void
    {
        $this->run(sprintf('git checkout %s', escapeshellarg($branch)));
        $this->runNetwork('git pull');
    }

    public function getChangedFiles(string $base, string $branch): array
    {
        return array_values(array_filter(explode("\n", trim($this->capture(sprintf(
            'git diff --name-only %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch)
        ))))));
    }

    public function listWorktreesPorcelain(): string
    {
        return trim($this->capture('git worktree list --porcelain'));
    }

    public function getGitPath(string $path, string $subPath): string
    {
        return trim($this->capture($this->inPath($path, sprintf('rev-parse --git-path %s', escapeshellarg($subPath)))));
    }

    public function getTrackedFiles(string $path, string $dir): array
    {
        return array_values(array_filter(explode("\n", trim($this->capture($this->inPath(
            $path,
            sprintf('ls-files %s', escapeshellarg($dir))
        ))))));
    }

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
                $this->retryCount,
                $command,
                $result[1],
            ));
        }

        return $result;
    }

    private function isRetryableNetworkError(string $output): bool
    {
        foreach ($this->networkErrorNeedles as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
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
