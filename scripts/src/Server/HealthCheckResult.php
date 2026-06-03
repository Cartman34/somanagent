<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Server;

/**
 * Result returned by one read-only service health probe.
 */
final readonly class HealthCheckResult
{
    /**
     * @param bool $healthy Whether the checked service is considered healthy.
     * @param string $message User-facing diagnostic detail for the checked service.
     */
    public function __construct(
        /** Whether the checked service is considered healthy. */
        public bool $healthy,
        /** User-facing diagnostic detail for the checked service. */
        public string $message,
    ) {
    }
}
