<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Server;

/**
 * Native PHP implementation of server health probes.
 *
 * PostgreSQL and Redis checks deliberately avoid host binaries such as
 * `pg_isready` and `redis-cli`; PHP extensions and sockets are enough for the
 * read-only diagnostics required by scripts/server.php health.
 */
final class NativeHealthProbe implements HealthProbeInterface
{
    private const POSTGRES_DSN = 'pgsql:host=localhost;port=5432;dbname=somanagent;connect_timeout=2';
    private const POSTGRES_USER = 'somanagent';
    private const POSTGRES_PASSWORD = 'somanagent';
    private const REDIS_ADDRESS = 'tcp://localhost:6379';
    private const REDIS_TIMEOUT_SECONDS = 2.0;
    private const REDIS_PING_COMMAND = "*1\r\n$4\r\nPING\r\n";

    /**
     * Checks PostgreSQL by opening a short native PDO connection to the local service.
     */
    public function checkPostgreSql(): HealthCheckResult
    {
        try {
            new \PDO(
                self::POSTGRES_DSN,
                self::POSTGRES_USER,
                self::POSTGRES_PASSWORD,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            return new HealthCheckResult(false, sprintf('connection failed: %s', $e->getMessage()));
        }

        return new HealthCheckResult(true, 'connection accepted on localhost:5432');
    }

    /**
     * Checks Redis by opening a socket and sending a raw RESP PING command.
     */
    public function checkRedis(): HealthCheckResult
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            self::REDIS_ADDRESS,
            $errno,
            $errstr,
            self::REDIS_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );

        if (!is_resource($socket)) {
            $message = $errstr !== '' ? $errstr : 'socket connection failed';

            return new HealthCheckResult(false, sprintf('connection failed: %s', $message));
        }

        stream_set_timeout($socket, (int) self::REDIS_TIMEOUT_SECONDS);
        $written = @fwrite($socket, self::REDIS_PING_COMMAND);
        if ($written === false || $written === 0) {
            fclose($socket);

            return new HealthCheckResult(false, 'failed to send PING');
        }

        $response = @fgets($socket);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        if ($metadata['timed_out'] === true) {
            return new HealthCheckResult(false, 'PING timed out');
        }

        $message = trim((string) $response);
        if ($message === '+PONG') {
            return new HealthCheckResult(true, 'PONG');
        }

        return new HealthCheckResult(false, sprintf('unexpected response: %s', $message !== '' ? $message : '(empty)'));
    }

    /**
     * Reports whether Docker says the named container is running.
     */
    public function isContainerRunning(string $containerName): bool
    {
        $out = [];
        exec('docker inspect --format={{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null', $out, $code);

        return $code === 0 && trim($out[0] ?? '') === 'true';
    }

    /**
     * Checks an HTTP endpoint with PHP streams and no external HTTP client binary.
     */
    public function checkHttp(string $url, int $timeout): HealthCheckResult
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return new HealthCheckResult(false, sprintf('not reachable at %s', $url));
        }

        return new HealthCheckResult(true, sprintf('reachable at %s', $url));
    }
}
