<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client;

/**
 * Detects retryable network errors from a command's combined output.
 *
 * Holds the transport-error needles shared by every network-facing client and
 * the matching routine. Each using class declares its own client-specific
 * needles through {@see networkErrorNeedles()}; the trait merges them with the
 * shared list when scanning output.
 */
trait NetworkErrorDetection
{
    /**
     * Transport-error needles common to every network-facing client.
     *
     * @var list<string>
     */
    private static array $commonNetworkErrorNeedles = [
        'Could not resolve host:',
        'Connection timed out',
        'Failed to connect',
        'Operation timed out',
        'Temporary failure in name resolution',
    ];

    /**
     * Returns the client-specific transport-error needles.
     *
     * @return list<string>
     */
    abstract protected function networkErrorNeedles(): array;

    /**
     * Returns true when the output contains a known retryable network-error needle.
     *
     * @param string $output Combined stdout/stderr of the failed command
     * @return bool True when the error is a retryable transport failure
     */
    private function isRetryableNetworkError(string $output): bool
    {
        $needles = array_merge(self::$commonNetworkErrorNeedles, $this->networkErrorNeedles());
        foreach ($needles as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }
}
