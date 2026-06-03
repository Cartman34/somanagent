<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client;

/**
 * Contract for low-level command execution shared by higher-level transport clients.
 */
interface ConsoleClientInterface
{
    /**
     * Sends a message to the verbose logger.
     */
    public function logVerbose(string $message): void;

    /**
     * Runs a shell command via the application runner, throwing on non-zero exit.
     */
    public function run(string $command): void;

    /**
     * Runs a shell command and returns its combined stdout/stderr output, throwing on non-zero exit.
     */
    public function capture(string $command): string;

    /**
     * Runs a shell command and returns its exit code and combined output without throwing.
     *
     * @return array{0: int, 1: string}
     */
    public function captureWithExitCode(string $command): array;

    /**
     * Returns true when the command exits with code 0, false otherwise.
     */
    public function succeeds(string $command): bool;

    /**
     * Converts an absolute path to a path relative to the project root, or returns it unchanged if outside.
     */
    public function toRelativeProjectPath(string $path): string;
}
