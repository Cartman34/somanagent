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
 * Handles backlog pull-request orchestration above Git and GitHub clients.
 */
final class PullRequestManager
{
    private bool $dryRun;
    private string $headInvalidNeedle;
    private GitClient $git;
    private GitHubClient $github;
    private int $retryCount;
    private int $retryBaseDelay;
    private int $retryFactor;

    public function __construct(
        bool $dryRun,
        string $headInvalidNeedle,
        GitClient $git,
        GitHubClient $github,
        int $retryCount,
        int $retryBaseDelay,
        int $retryFactor,
    ) {
        $this->dryRun = $dryRun;
        $this->headInvalidNeedle = $headInvalidNeedle;
        $this->git = $git;
        $this->github = $github;
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

        $this->github->run(sprintf(
            'pr edit %d --title %s --body-file %s',
            $prNumber,
            escapeshellarg($title),
            escapeshellarg($bodyFile),
        ));
    }

    public function pushBranchAndWaitForRemoteVisibility(string $branch, ?string $worktree = null): void
    {
        $gitPrefix = $worktree !== null
            ? sprintf('git -C %s', escapeshellarg($this->git->toRelativeProjectPath($worktree)))
            : 'git';

        $this->git->runNetwork(sprintf(
            '%s push -u origin %s',
            $gitPrefix,
            escapeshellarg($branch),
        ));

        $this->git->runNetwork(sprintf(
            '%s fetch origin %s:%s',
            $gitPrefix,
            escapeshellarg($branch),
            escapeshellarg('refs/remotes/origin/' . $branch),
        ));

        $this->waitForRemoteBranchVisibility($branch);
    }

    public function updatePrBody(string $branch, string $bodyFile): void
    {
        $prNumber = $this->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        $this->github->run(sprintf(
            'pr edit %d --body-file %s',
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

        $this->github->run(sprintf(
            'pr edit %d --body-file %s',
            $prNumber,
            escapeshellarg($bodyFile),
        ));
    }

    public function closePr(int $prNumber): void
    {
        $this->github->run(sprintf('pr close %d', $prNumber));
    }

    public function mergePr(int $prNumber): void
    {
        $this->github->run(sprintf('pr merge %d', $prNumber));
    }

    public function editPrTitle(int $prNumber, string $title): void
    {
        $this->github->run(sprintf(
            'pr edit %d --title %s',
            $prNumber,
            escapeshellarg($title),
        ));
    }

    public function findPrNumberByBranch(string $branch): ?int
    {
        if ($branch === '') {
            return null;
        }

        $output = $this->github->capture('pr list');
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^\s*#(\d+)\s+.*\[(.+?) → (.+?)\]$/u', $line, $matches) === 1) {
                if ($matches[2] === $branch) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    private function createPrWithRetry(string $branch, string $title, string $bodyFile, string $baseBranch): void
    {
        $arguments = sprintf(
            'pr create --title %s --head %s --base %s --body-file %s',
            escapeshellarg($title),
            escapeshellarg($branch),
            escapeshellarg($baseBranch),
            escapeshellarg($bodyFile),
        );
        $command = sprintf('github.php %s', $arguments);

        [$code, $output] = $this->networkRetryHelper()->run(
            function () use ($branch, $arguments): array {
                [$code, $output] = $this->github->captureArgumentsWithExitCode($arguments);
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
        $output = $this->git->captureNetwork(sprintf(
            'git ls-remote --heads origin %s',
            escapeshellarg($branch),
        ));

        return trim($output) !== '';
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
