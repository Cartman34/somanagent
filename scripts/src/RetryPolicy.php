<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script;

use Sowapps\SoManAgent\Script\RetryHelper;

/**
 * Retry configuration for script operations that use RetryHelper.
 */
final class RetryPolicy
{
    /**
     * Sets the retry count, initial delay (in microseconds), and backoff factor.
     */
    public function __construct(
        private readonly int $retryCount = 3,
        private readonly int $initialDelayMicroseconds = 500000,
        private readonly int $backoffFactor = 2,
    ) {
    }

    /**
     * Returns a new RetryHelper configured from this policy.
     */
    public function createHelper(): RetryHelper
    {
        return new RetryHelper(
            $this->retryCount,
            $this->initialDelayMicroseconds,
            $this->backoffFactor,
        );
    }

    /**
     * Returns the maximum number of retry attempts.
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }
}
