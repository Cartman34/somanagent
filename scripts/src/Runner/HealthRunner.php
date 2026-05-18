<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Health check script runner.
 *
 * Checks API reachability, then delegates connector diagnostics to somanagent:health.
 */
final class HealthRunner extends AbstractScriptRunner
{
    private const NAME = 'health';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Check application reachability and run somanagent:health';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--url', 'description' => 'Base URL to check (default: http://localhost:8080)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/health.php',
            'php scripts/health.php --url http://localhost:8080',
        ];
    }

    /**
     * Checks API reachability, then runs the application health command.
     */
    public function run(array $args): int
    {
        [, $options] = $this->parseArgs(array_values($args));
        $baseUrl = $this->getSingleOption($options, 'url') ?? 'http://localhost:8080';

        $this->console->step("Checking SoManAgent ($baseUrl)");

        try {
            $app = $this->httpGet("$baseUrl/api/health", 5);
            $this->console->ok("Application : {$app['app']} v{$app['version']} — reachable");
        } catch (\RuntimeException $e) {
            $this->console->line("  ❌ API unreachable: " . $e->getMessage());
            $this->console->line('  → Start the stack with: php scripts/server.php start');
            return 1;
        }

        $this->console->line();
        $this->console->step('Connector health via somanagent:health');

        return $this->app->runCommand('php scripts/console.php somanagent:health');
    }

    /**
     * Performs a GET request and decodes the JSON response.
     *
     * @return array<mixed>
     * @throws \RuntimeException when the request fails or the response is not valid JSON.
     */
    private function httpGet(string $url, int $timeout = 10): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            throw new \RuntimeException("Cannot reach $url");
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Non-JSON response from $url");
        }

        return $data;
    }
}
