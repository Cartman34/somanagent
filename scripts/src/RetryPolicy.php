<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Retry configuration for script operations that use RetryHelper.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $retryCount = 3,
        private readonly int $initialDelayMicroseconds = 500000,
        private readonly int $backoffFactor = 2,
    ) {
    }

    public function createHelper(): RetryHelper
    {
        return new RetryHelper(
            $this->retryCount,
            $this->initialDelayMicroseconds,
            $this->backoffFactor,
        );
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }
}
