<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\RetryHelper;

/**
 * Handles remote git branch visibility checks and GitHub PR orchestration.
 */
final class BacklogPullRequestManager
{
    private bool $dryRun;
    private string $headInvalidNeedle;
    private BacklogShell $shell;

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
        string $headInvalidNeedle,
        BacklogShell $shell,
        array $networkErrorNeedles,
        int $retryCount,
        int $retryBaseDelay,
        int $retryFactor,
    ) {
        $this->dryRun = $dryRun;
        $this->headInvalidNeedle = $headInvalidNeedle;
        $this->shell = $shell;
        $this->networkErrorNeedles = $networkErrorNeedles;
        $this->retryCount = $retryCount;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->retryFactor = $retryFactor;
    }

    private function logVerbose(string $message): void
    {
        $this->shell->logVerbose($message);
    }

    private function toRelativeProjectPath(string $path): string
    {
        return $this->shell->toRelativeProjectPath($path);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function captureWithExitCode(string $command): array
    {
        return $this->shell->captureWithExitCode($command);
    }

    public function createOrUpdatePr(string $branch, string $title, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);

        if ($prNumber === null) {
            $this->createPrWithRetry($branch, $title, $bodyFile);

            return;
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --title %s --body-file %s',
            $prNumber,
            escapeshellarg($title),
            escapeshellarg($bodyFile),
        ));
    }

    public function pushBranchAndWaitForRemoteVisibility(string $branch, ?string $worktree = null): void
    {
        $gitPrefix = $worktree !== null
            ? sprintf('git -C %s', escapeshellarg($this->toRelativeProjectPath($worktree)))
            : 'git';

        $this->runNetworkCommand(sprintf(
            '%s push -u origin %s',
            $gitPrefix,
            escapeshellarg($branch),
        ), 'Git');

        $this->runNetworkCommand(sprintf(
            '%s fetch origin %s:%s',
            $gitPrefix,
            escapeshellarg($branch),
            escapeshellarg('refs/remotes/origin/' . $branch),
        ), 'Git');

        $this->waitForRemoteBranchVisibility($branch);
    }

    public function updatePrBody(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --body-file %s',
            $prNumber,
            escapeshellarg($bodyFile),
        ));
    }

    public function updatePrBodyIfExists(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            return;
        }

        $this->runGithubCommand(sprintf(
            'php scripts/github.php pr edit %d --body-file %s',
            $prNumber,
            escapeshellarg($bodyFile),
        ));
    }

    public function findPrNumberByBranch(string $branch): ?int
    {
        if ($branch === '') {
            return null;
        }

        $output = $this->captureNetworkOutputWithRetry('php scripts/github.php pr list', 'GitHub');
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^\s*#(\d+)\s+.*\[(.+?) → (.+?)\]$/u', $line, $matches) === 1) {
                if ($matches[2] === $branch) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    public function runGithubCommand(string $command): void
    {
        $this->runNetworkCommand($command, 'GitHub');
    }

    public function runNetworkCommand(string $command, string $label): void
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry($command, $label);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function createPrWithRetry(string $branch, string $title, string $bodyFile): void
    {
        $command = sprintf(
            'php scripts/github.php pr create --title %s --head %s --base main --body-file %s',
            escapeshellarg($title),
            escapeshellarg($branch),
            escapeshellarg($bodyFile),
        );

        [$code, $output] = $this->networkRetryHelper()->run(
            function () use ($branch, $command): array {
                [$code, $output] = $this->captureNetworkCommandWithRetry($command, 'GitHub');
                if ($code !== 0 && $this->isHeadInvalidCreateError($output)) {
                    $this->waitForRemoteBranchVisibility($branch);
                }

                return [$code, $output];
            },
            fn(array $result): bool => $result[0] !== 0 && $this->isHeadInvalidCreateError($result[1]),
        );

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function waitForRemoteBranchVisibility(string $branch): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would wait for remote branch visibility: ' . $branch);

            return;
        }

        $isVisible = $this->networkRetryHelper()->run(
            fn(): bool => $this->isRemoteBranchVisible($branch),
            fn(bool $result): bool => !$result,
        );

        if ($isVisible) {
            return;
        }

        throw new \RuntimeException("Remote branch did not become visible in time: {$branch}");
    }

    private function isRemoteBranchVisible(string $branch): bool
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry(sprintf(
            'git ls-remote --heads origin %s',
            escapeshellarg($branch),
        ), 'Git');

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d while checking remote branch visibility: %s\n%s",
                $code,
                $branch,
                $output,
            ));
        }

        return trim($output) !== '';
    }

    private function isHeadInvalidCreateError(string $output): bool
    {
        return str_contains($output, $this->headInvalidNeedle);
    }

    /**
     * Runs one network command with retry on transient transport failures.
     *
     * @return array{0: int, 1: string}
     */
    private function captureNetworkCommandWithRetry(string $command, string $label): array
    {
        if ($this->dryRun) {
            $this->logVerbose(sprintf('[dry-run] Would run %s command: %s', strtolower($label), $command));

            return [0, ''];
        }

        $result = $this->networkRetryHelper()->run(
            fn(): array => $this->captureWithExitCode($command),
            fn(array $result): bool => $result[0] !== 0 && $this->isRetryableNetworkError($result[1]),
        );

        if ($result[0] !== 0 && $this->isRetryableNetworkError($result[1])) {
            throw new \RuntimeException(sprintf(
                "%s network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
                $label,
                $this->retryCount,
                $command,
                $result[1],
            ));
        }

        return $result;
    }

    private function captureNetworkOutputWithRetry(string $command, string $label): string
    {
        [$code, $output] = $this->captureNetworkCommandWithRetry($command, $label);
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
