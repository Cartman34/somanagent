<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Generic retry helper with exponential backoff.
 */
final class RetryHelper
{
    /**
     * Stores the retry count and the initial exponential backoff delay.
     */
    public function __construct(
        private readonly int $retryCount,
        private readonly int $initialDelayMicroseconds,
    ) {
    }

    /**
     * Runs one operation and retries while the predicate says so.
     *
     * @template TResult
     * @param callable(): TResult $operation
     * @param callable(TResult): bool $shouldRetry
     * @return TResult
     */
    public function run(callable $operation, callable $shouldRetry): mixed
    {
        $result = null;

        for ($attempt = 0; $attempt <= $this->retryCount; $attempt++) {
            $result = $operation();
            if (!$shouldRetry($result) || $attempt === $this->retryCount) {
                return $result;
            }

            usleep($this->initialDelayMicroseconds * (2 ** $attempt));
        }

        return $result;
    }
}
