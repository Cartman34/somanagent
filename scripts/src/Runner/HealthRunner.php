<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Health check script runner.
 *
 * Checks application and connector health via the API.
 */
final class HealthRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Check application and connector health via the API';
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

    public function run(array $args): int
    {
        $baseUrl = $this->parseBaseUrl($args);

        $this->console->step("Checking SoManAgent ($baseUrl)");

        try {
            $app = $this->httpGet("$baseUrl/api/health");
            $this->console->ok("Application  : {$app['app']} v{$app['version']}");

            $connectors = $this->httpGet("$baseUrl/api/health/connectors");

            $this->console->line();
            $this->console->line('  Connectors:');
            $allOk = true;
            foreach ($connectors['connectors'] as $name => $ok) {
                $icon   = $ok ? '✓' : '✗';
                $status = $ok ? 'OK' : 'UNREACHABLE';
                $this->console->line("    $icon  $name : $status");
                if (!$ok) {
                    $allOk = false;
                }
            }

            $this->console->line();
            $this->console->line('  Overall status: ' . ($allOk ? '✓ OK' : '⚠  Degraded'));
            return $allOk ? 0 : 1;
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage() . "\n     → Run: php scripts/dev.php");
        }
    }

    /**
     * Parse --url flag from CLI arguments.
     *
     * @param array<string> $args
     */
    private function parseBaseUrl(array $args): string
    {
        $baseUrl = 'http://localhost:8080';
        foreach ($args as $i => $arg) {
            if ($arg === '--url' && isset($args[$i + 1])) {
                $baseUrl = $args[$i + 1];
            }
        }
        return $baseUrl;
    }

    /**
     * Perform a GET request and decode JSON response.
     *
     * @return array<mixed>
     */
    private function httpGet(string $url): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("Cannot reach $url");
        }
        $data = json_decode($raw, true);
        if ($data === null) {
            throw new \RuntimeException("Non-JSON response from $url");
        }
        return $data;
    }
}
