<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\GitHub;

use Sowapps\SoManAgent\Script\Client\AppScript;
use Sowapps\SoManAgent\Script\Client\NetworkErrorDetection;
use Sowapps\SoManAgent\Script\Client\ProjectScriptClient;
use Sowapps\SoManAgent\Script\RetryHelper;
use Sowapps\SoManAgent\Script\RetryPolicy;

/**
 * GitHub platform client backed by the local github.php project script.
 */
final class GitHubClient implements GitHubClientInterface
{
    use NetworkErrorDetection;

    private bool $dryRun;
    private ProjectScriptClient $scripts;
    private RetryPolicy $retryPolicy;

    /**
     * @param bool $dryRun Whether to run in dry-run mode (no actual commands executed)
     * @param ProjectScriptClient $scripts The project scripts client
     * @param RetryPolicy $retryPolicy The retry policy for network operations
     */
    public function __construct(
        bool $dryRun,
        ProjectScriptClient $scripts,
        RetryPolicy $retryPolicy,
    ) {
        $this->dryRun = $dryRun;
        $this->scripts = $scripts;
        $this->retryPolicy = $retryPolicy;
    }

    /**
     * Runs a GitHub CLI command and throws on failure.
     *
     * @param string $arguments The GitHub CLI arguments to execute
     * @return void
     * @throws \RuntimeException If the command exits with a non-zero code
     */
    public function run(string $arguments): void
    {
        $command = $this->scripts->command(AppScript::GITHUB, $arguments);
        [$code, $output] = $this->captureArgumentsWithExitCode($arguments);
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
     * Captures the output of a GitHub CLI command.
     *
     * @param string $arguments The GitHub CLI arguments to execute
     * @return string The command output
     * @throws \RuntimeException If the command exits with a non-zero code
     */
    public function capture(string $arguments): string
    {
        $command = $this->scripts->command(AppScript::GITHUB, $arguments);
        [$code, $output] = $this->captureArgumentsWithExitCode($arguments);
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
     * @return array{0: int, 1: string}
     */
    public function captureArgumentsWithExitCode(string $arguments): array
    {
        $command = $this->scripts->command(AppScript::GITHUB, $arguments);

        if ($this->dryRun) {
            return [0, ''];
        }

        $result = $this->networkRetryHelper()->run(
            fn(): array => $this->scripts->captureWithExitCode(AppScript::GITHUB, $arguments),
            fn(array $result): bool => $result[0] !== 0 && $this->isRetryableNetworkError($result[1]),
        );

        if ($result[0] !== 0 && $this->isRetryableNetworkError($result[1])) {
            throw new \RuntimeException(sprintf(
                "GitHub network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
                $this->retryPolicy->getRetryCount(),
                $command,
                $result[1],
            ));
        }

        return $result;
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function createPr(string $title, string $headBranch, string $baseBranch, string $bodyFilePath): array
    {
        $arguments = sprintf(
            '%s --title %s --head %s --base %s --body-file %s',
            GitHubCommandName::PR_CREATE->value,
            escapeshellarg($title),
            escapeshellarg($headBranch),
            escapeshellarg($baseBranch),
            escapeshellarg($bodyFilePath)
        );

        return $this->captureArgumentsWithExitCode($arguments);
    }

    /**
     * Edits an existing pull request.
     *
     * @param int $prNumber The pull request number
     * @param string|null $title New title for the PR (optional)
     * @param string|null $bodyFilePath Path to file containing body text (optional)
     * @return void
     */
    public function editPr(int $prNumber, ?string $title = null, ?string $bodyFilePath = null): void
    {
        $arguments = sprintf('%s %d', GitHubCommandName::PR_EDIT->value, $prNumber);
        if ($title !== null) {
            $arguments .= sprintf(' --title %s', escapeshellarg($title));
        }
        if ($bodyFilePath !== null) {
            $arguments .= sprintf(' --body-file %s', escapeshellarg($bodyFilePath));
        }

        $this->run($arguments);
    }

    /**
     * Closes a pull request.
     *
     * @param int $prNumber The pull request number to close
     * @return void
     */
    public function closePr(int $prNumber): void
    {
        $this->run(sprintf('%s %d', GitHubCommandName::PR_CLOSE->value, $prNumber));
    }

    /**
     * Merges a pull request.
     *
     * @param int $prNumber The pull request number to merge
     * @return void
     */
    public function mergePr(int $prNumber): void
    {
        $this->run(sprintf('%s %d', GitHubCommandName::PR_MERGE->value, $prNumber));
    }

    /**
     * Lists all open pull requests.
     *
     * @return string The output of the PR list command
     */
    public function listPrs(): string
    {
        return $this->capture(GitHubCommandName::PR_LIST->value);
    }

    /**
     * Returns the state of a pull request: "merged", "open", or "closed".
     *
     * @param int $prNumber Pull request number on GitHub
     * @return string "merged" when already merged, "open" when open, "closed" when closed but not merged
     */
    public function getPrState(int $prNumber): string
    {
        return trim($this->capture(sprintf('%s %d', GitHubCommandName::PR_VIEW_STATE->value, $prNumber)));
    }

    /**
     * @return list<string>
     */
    protected function networkErrorNeedles(): array
    {
        return ['GitHub API transport error:'];
    }

    private function networkRetryHelper(): RetryHelper
    {
        return $this->retryPolicy->createHelper();
    }
}
