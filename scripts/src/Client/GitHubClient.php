<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

use SoManAgent\Script\RetryHelper;

/**
 * GitHub platform client backed by the local github.php project script.
 */
final class GitHubClient
{
    private bool $dryRun;
    private ConsoleClient $console;
    private ProjectScriptClient $scripts;

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
        ProjectScriptClient $scripts,
        array $networkErrorNeedles,
        int $retryCount,
        int $retryBaseDelay,
        int $retryFactor,
    ) {
        $this->dryRun = $dryRun;
        $this->console = $console;
        $this->scripts = $scripts;
        $this->networkErrorNeedles = $networkErrorNeedles;
        $this->retryCount = $retryCount;
        $this->retryBaseDelay = $retryBaseDelay;
        $this->retryFactor = $retryFactor;
    }

    public function run(string $arguments): void
    {
        $command = $this->scripts->command(AppScript::GITHUB, $arguments);
        [$code, $output] = $this->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    public function capture(string $arguments): string
    {
        $command = $this->scripts->command(AppScript::GITHUB, $arguments);
        [$code, $output] = $this->captureWithExitCode($command);
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
    public function captureWithExitCode(string $command): array
    {
        if ($this->dryRun) {
            return [0, ''];
        }

        $result = $this->networkRetryHelper()->run(
            fn(): array => $this->console->captureWithExitCode($command),
            fn(array $result): bool => $result[0] !== 0 && $this->isRetryableNetworkError($result[1]),
        );

        if ($result[0] !== 0 && $this->isRetryableNetworkError($result[1])) {
            throw new \RuntimeException(sprintf(
                "GitHub network error after %d retries. Safe to rerun the same command.\nCommand: %s\n%s",
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
