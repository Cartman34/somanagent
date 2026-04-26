<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

use SoManAgent\Script\Application;

/**
 * Low-level command execution client shared by higher-level transport clients.
 */
final class ConsoleClient
{
    private string $projectRoot;
    private bool $dryRun;
    private Application $app;

    /** @var callable(string): void */
    private $verboseLogger;

    public function __construct(string $projectRoot, bool $dryRun, Application $app, callable $verboseLogger)
    {
        $this->projectRoot = $projectRoot;
        $this->dryRun = $dryRun;
        $this->app = $app;
        $this->verboseLogger = $verboseLogger;
    }

    public function logVerbose(string $message): void
    {
        ($this->verboseLogger)($message);
    }

    public function run(string $command): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run: ' : 'Run: ') . $command);
        if ($this->dryRun) {
            return;
        }

        $code = $this->app->runCommand($command);
        if ($code !== 0) {
            throw new \RuntimeException("Command failed with exit code {$code}: {$command}");
        }
    }

    public function capture(string $command): string
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                implode("\n", $output),
            ));
        }

        return implode("\n", $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function captureWithExitCode(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function succeeds(string $command): bool
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return $code === 0;
    }

    public function toRelativeProjectPath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedPath === $normalizedRoot) {
            return '.';
        }

        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            return $path;
        }

        return substr($normalizedPath, strlen($prefix));
    }
}
