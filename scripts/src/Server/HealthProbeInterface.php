<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Server;

/**
 * Provides read-only health probes for services managed by scripts/server.php.
 */
interface HealthProbeInterface
{
    /**
     * Checks whether PostgreSQL accepts a native PDO connection.
     */
    public function checkPostgreSql(): HealthCheckResult;

    /**
     * Checks whether Redis answers to a raw RESP PING over a TCP socket.
     */
    public function checkRedis(): HealthCheckResult;

    /**
     * Reports whether a Docker container is currently running.
     */
    public function isContainerRunning(string $containerName): bool;

    /**
     * Checks whether an HTTP endpoint is reachable through PHP streams.
     */
    public function checkHttp(string $url, int $timeout): HealthCheckResult;
}
