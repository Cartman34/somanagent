<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Server\Test;

use Sowapps\SoManAgent\Script\Server\HealthCheckResult;
use Sowapps\SoManAgent\Script\Runner\ServerRunner;
use Sowapps\SoManAgent\Script\Server\HealthProbeInterface;

/**
 * Integration tests for scripts/server.php.
 *
 * Runs the real script as a subprocess. Tests are limited to paths that do not
 * require Docker: help display, preview-only/dry-run modes, and error cases.
 */
final class ServerRunnerTest
{
    private const SCRIPT = 'scripts/server.php';

    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testHelpDisplays();
        $failed += $this->testSubcommandHelpDisplays();
        $failed += $this->testUnknownSubcommandFails();
        $failed += $this->testPreviewOnlyShowsPlanWithoutDocker();
        $failed += $this->testDryRunShowsPlanWithoutDocker();
        $failed += $this->testMutuallyExclusiveFlagsRejected();
        $failed += $this->testHelpFlagAcceptedOnMutationCommand();
        $failed += $this->testRestartPreviewOnly();
        $failed += $this->testHealthMinimalUsesNativeProbe();
        $failed += $this->testHealthFullChecksHttpServices();
        $failed += $this->testHealthFailsWhenPostgreSqlIsDown();
        $failed += $this->testHealthFailsWhenRedisIsDown();

        return $failed;
    }

    private function testHelpDisplays(): int
    {
        [$exit, $stdout] = $this->runScript(['help']);
        if ($exit !== 0) {
            echo "FAIL testHelpDisplays: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, 'start') || !str_contains($stdout, 'stop') || !str_contains($stdout, 'health')) {
            echo "FAIL testHelpDisplays: expected start/stop/health in output: {$stdout}\n";

            return 1;
        }
        echo "OK testHelpDisplays\n";

        return 0;
    }

    private function testSubcommandHelpDisplays(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--help']);
        if ($exit !== 0) {
            echo "FAIL testSubcommandHelpDisplays: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, '--minimal') || !str_contains($stdout, '--preview-only')) {
            echo "FAIL testSubcommandHelpDisplays: expected --minimal and --preview-only in output: {$stdout}\n";

            return 1;
        }
        echo "OK testSubcommandHelpDisplays\n";

        return 0;
    }

    private function testUnknownSubcommandFails(): int
    {
        $unknownSubcommand = 'bogus-command';
        [$exit, $stdout, $stderr] = $this->runScript([$unknownSubcommand]);
        if ($exit === 0) {
            echo "FAIL testUnknownSubcommandFails: expected non-zero exit\n";

            return 1;
        }
        $output = $stdout . "\n" . $stderr;
        if (!str_contains($output, "Unknown subcommand: '{$unknownSubcommand}'")) {
            echo "FAIL testUnknownSubcommandFails: unexpected output: {$output}\n";

            return 1;
        }
        echo "OK testUnknownSubcommandFails\n";

        return 0;
    }

    private function testPreviewOnlyShowsPlanWithoutDocker(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--preview-only']);
        if ($exit !== 0) {
            echo "FAIL testPreviewOnlyShowsPlanWithoutDocker: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'Preview:') || !str_contains($stdout, 'docker compose')) {
            echo "FAIL testPreviewOnlyShowsPlanWithoutDocker: expected Preview block in output: {$stdout}\n";

            return 1;
        }
        echo "OK testPreviewOnlyShowsPlanWithoutDocker\n";

        return 0;
    }

    private function testDryRunShowsPlanWithoutDocker(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--dry-run']);
        if ($exit !== 0) {
            echo "FAIL testDryRunShowsPlanWithoutDocker: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'dry-run') || !str_contains($stdout, 'Preview:')) {
            echo "FAIL testDryRunShowsPlanWithoutDocker: expected dry-run marker and Preview: in output: {$stdout}\n";

            return 1;
        }
        echo "OK testDryRunShowsPlanWithoutDocker\n";

        return 0;
    }

    private function testMutuallyExclusiveFlagsRejected(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['start', '--preview-only', '--dry-run']);
        if ($exit === 0) {
            echo "FAIL testMutuallyExclusiveFlagsRejected: expected non-zero exit\n";

            return 1;
        }
        $output = $stdout . "\n" . $stderr;
        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testMutuallyExclusiveFlagsRejected: expected 'mutually exclusive' in output: {$output}\n";

            return 1;
        }
        echo "OK testMutuallyExclusiveFlagsRejected\n";

        return 0;
    }

    private function testHelpFlagAcceptedOnMutationCommand(): int
    {
        [$exit, $stdout] = $this->runScript(['stop', '--help']);
        if ($exit !== 0) {
            echo "FAIL testHelpFlagAcceptedOnMutationCommand: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, '--force') || !str_contains($stdout, '--preview-only')) {
            echo "FAIL testHelpFlagAcceptedOnMutationCommand: expected --force and --preview-only in stop help: {$stdout}\n";

            return 1;
        }
        echo "OK testHelpFlagAcceptedOnMutationCommand\n";

        return 0;
    }

    private function testRestartPreviewOnly(): int
    {
        [$exit, $stdout] = $this->runScript(['restart', '--preview-only']);
        if ($exit !== 0) {
            echo "FAIL testRestartPreviewOnly: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'docker compose down') || !str_contains($stdout, 'docker compose')) {
            echo "FAIL testRestartPreviewOnly: expected stop and start commands in restart preview: {$stdout}\n";

            return 1;
        }
        echo "OK testRestartPreviewOnly\n";

        return 0;
    }

    private function testHealthMinimalUsesNativeProbe(): int
    {
        $probe = FakeHealthProbe::healthy();
        [$exit, $stdout] = $this->runHealthWithProbe($probe);

        if ($exit !== 0) {
            echo "FAIL testHealthMinimalUsesNativeProbe: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'PostgreSQL: connection accepted') || !str_contains($stdout, 'Redis: PONG')) {
            echo "FAIL testHealthMinimalUsesNativeProbe: expected PostgreSQL and Redis OK output: {$stdout}\n";

            return 1;
        }
        echo "OK testHealthMinimalUsesNativeProbe\n";

        return 0;
    }

    private function testHealthFullChecksHttpServices(): int
    {
        $probe = FakeHealthProbe::healthy();
        $probe->runningContainers = [
            'somanagent_nginx'   => true,
            'somanagent_mercure' => true,
        ];
        [$exit, $stdout] = $this->runHealthWithProbe($probe);

        if ($exit !== 0) {
            echo "FAIL testHealthFullChecksHttpServices: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'API (nginx): reachable') || !str_contains($stdout, 'Mercure: reachable')) {
            echo "FAIL testHealthFullChecksHttpServices: expected full-profile HTTP checks: {$stdout}\n";

            return 1;
        }
        echo "OK testHealthFullChecksHttpServices\n";

        return 0;
    }

    private function testHealthFailsWhenPostgreSqlIsDown(): int
    {
        $probe = FakeHealthProbe::healthy();
        $probe->postgres = new HealthCheckResult(false, 'connection failed: timeout');
        [$exit, $stdout] = $this->runHealthWithProbe($probe);

        if ($exit !== 1) {
            echo "FAIL testHealthFailsWhenPostgreSqlIsDown: expected exit 1, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'PostgreSQL: connection failed: timeout')) {
            echo "FAIL testHealthFailsWhenPostgreSqlIsDown: expected PostgreSQL failure output: {$stdout}\n";

            return 1;
        }
        echo "OK testHealthFailsWhenPostgreSqlIsDown\n";

        return 0;
    }

    private function testHealthFailsWhenRedisIsDown(): int
    {
        $probe = FakeHealthProbe::healthy();
        $probe->redis = new HealthCheckResult(false, 'connection failed: refused');
        [$exit, $stdout] = $this->runHealthWithProbe($probe);

        if ($exit !== 1) {
            echo "FAIL testHealthFailsWhenRedisIsDown: expected exit 1, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'Redis: connection failed: refused')) {
            echo "FAIL testHealthFailsWhenRedisIsDown: expected Redis failure output: {$stdout}\n";

            return 1;
        }
        echo "OK testHealthFailsWhenRedisIsDown\n";

        return 0;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runHealthWithProbe(FakeHealthProbe $probe): array
    {
        $runner = new ServerRunner($probe);
        ob_start();
        $exit = $runner->run(['health']);
        $stdout = (string) ob_get_clean();

        return [$exit, $stdout];
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function runScript(array $args): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/' . self::SCRIPT);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            return [-1, '', 'failed to start subprocess'];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }
}

/**
 * In-memory health probe used to test server.php health without external services.
 */
final class FakeHealthProbe implements HealthProbeInterface
{
    /**
     * Result returned for the PostgreSQL probe.
     */
    public HealthCheckResult $postgres;

    /**
     * Result returned for the Redis probe.
     */
    public HealthCheckResult $redis;

    /**
     * Running state keyed by Docker container name.
     *
     * @var array<string, bool>
     */
    public array $runningContainers = [];

    /**
     * HTTP result keyed by URL.
     *
     * @var array<string, HealthCheckResult>
     */
    private array $httpResults = [];

    private function __construct()
    {
        $this->postgres = new HealthCheckResult(true, 'connection accepted on localhost:5432');
        $this->redis = new HealthCheckResult(true, 'PONG');
        $this->httpResults = [
            'http://localhost:8080/api/health' => new HealthCheckResult(
                true,
                'reachable at http://localhost:8080/api/health',
            ),
            'http://localhost:8080/.well-known/mercure' => new HealthCheckResult(
                true,
                'reachable at http://localhost:8080/.well-known/mercure',
            ),
        ];
    }

    /**
     * Creates a fake probe where DB, Redis, and known HTTP endpoints are healthy by default.
     */
    public static function healthy(): self
    {
        return new self();
    }

    /**
     * Returns the configured PostgreSQL result.
     */
    public function checkPostgreSql(): HealthCheckResult
    {
        return $this->postgres;
    }

    /**
     * Returns the configured Redis result.
     */
    public function checkRedis(): HealthCheckResult
    {
        return $this->redis;
    }

    /**
     * Returns the configured running state for one container.
     */
    public function isContainerRunning(string $containerName): bool
    {
        return $this->runningContainers[$containerName] ?? false;
    }

    /**
     * Returns the configured HTTP result for one endpoint.
     */
    public function checkHttp(string $url, int $timeout): HealthCheckResult
    {
        return $this->httpResults[$url] ?? new HealthCheckResult(false, "not reachable at {$url}");
    }
}
