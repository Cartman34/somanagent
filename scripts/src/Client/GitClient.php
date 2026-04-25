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
